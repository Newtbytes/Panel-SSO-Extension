<?php

declare(strict_types=1);

namespace Sso\Providers\OpenID;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Sso\Data\ExternalIdentity;
use Sso\Exceptions\SsoException;
use Sso\OAuth2Provider;
use Sso\SsoRoutes;

/**
 * Any OpenID Connect provider (authentik, Keycloak, Zitadel, Authelia and so on), configured by its
 * issuer URL. The endpoints come from the issuer's discovery document, so nothing about the provider
 * is hardcoded. This is OpenID Connect, which is built on OAuth 2, not the older OpenID 2.0 that
 * Steam uses.
 *
 * The profile comes from the userinfo endpoint, called with the access token that the token
 * endpoint returned to the panel directly, so no ID token has to be validated.
 */
final class OpenIDProvider extends OAuth2Provider
{
    private const string NAME = 'OpenID Connect';

    /** Discovery documents rarely change, so each issuer's endpoints are kept for an hour. */
    private const int DISCOVERY_TTL = 3600;

    /** userinfo_endpoint is only RECOMMENDED by OpenID Connect Discovery, but this provider needs it. */
    private const array ENDPOINTS = ['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'];

    /**
     * @inheritdoc
     */
    public function id(): string
    {
        return 'oidc';
    }

    /**
     * @inheritdoc
     */
    public function name(): string
    {
        $label = $this->config->string($this->key('label'));

        return $label !== '' ? $label : self::NAME;
    }

    /**
     * Not parent::settings(): that names its tab and help text after name(), which reads the button
     * label setting, and settings are defined before any setting can be read.
     *
     * @inheritdoc
     */
    public function settings(): array
    {
        return [
            ExtensionSettingDefinition::make($this->key('enabled'), $this->key('enabled'), false, ['boolean'])
                ->label('Enabled')
                ->tab(self::NAME)
                ->field('toggle')
                ->normalizeUsing(fn (mixed $value): bool => $value === true),
            ExtensionSettingDefinition::make($this->key('label'), $this->key('label'), '', ['nullable', 'string', 'max:64'])
                ->label('Button label')
                ->tab(self::NAME)
                ->help('The name on the sign-in button ("Continue with ...") and on Account > Connections, such as "authentik". Defaults to '.self::NAME.'.'),
            ExtensionSettingDefinition::make($this->key('issuer'), $this->key('issuer'), '', ['nullable', 'string', 'url:http,https', 'max:255'])
                ->label('Issuer URL')
                ->tab(self::NAME)
                ->help('The endpoints are read from <issuer>/.well-known/openid-configuration. For authentik, this is https://<authentik host>/application/o/<application slug>/. Accounts are matched by issuer and subject, so after a change, accounts linked through the old issuer no longer sign in.'),
            ExtensionSettingDefinition::make($this->key('client_id'), $this->key('client_id'), '', ['nullable', 'string', 'max:255'])
                ->label('Client ID')
                ->tab(self::NAME)
                ->help('Set the redirect URL of the application at the provider to '.SsoRoutes::callback($this->id())),
            ExtensionSettingDefinition::make($this->key('client_secret'), $this->key('client_secret'), '', ['nullable', 'string', 'max:255'])
                ->label('Client secret')
                ->tab(self::NAME)
                ->secret(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function enabled(): bool
    {
        return parent::enabled() && $this->issuer() !== '';
    }

    /**
     * @inheritdoc
     */
    protected function authorizeEndpoint(): string
    {
        return $this->discovery()['authorization_endpoint'];
    }

    /**
     * @inheritdoc
     */
    protected function tokenEndpoint(): string
    {
        return $this->discovery()['token_endpoint'];
    }

    /**
     * @inheritdoc
     */
    protected function userEndpoint(): string
    {
        return $this->discovery()['userinfo_endpoint'];
    }

    /**
     * @inheritdoc
     */
    protected function scopes(): array
    {
        return ['openid', 'email', 'profile'];
    }

    /**
     * @inheritdoc
     */
    protected function mapIdentity(array $user): ExternalIdentity
    {
        $sub = $this->stringId($user['sub'] ?? null);
        $email = $this->claim($user, 'email');
        $email = $email !== null && mb_strlen($email) <= 191 ? $email : null;
        $name = $this->claim($user, 'name') ?? $this->claim($user, 'preferred_username') ?? $this->claim($user, 'nickname') ?? $sub;

        return new ExternalIdentity(
            $this->id(),
            $this->subject($sub),
            mb_substr($name, 0, 255),
            $email,
            $email !== null && $this->verified($user['email_verified'] ?? null),
            $this->avatar($user['picture'] ?? null),
        );
    }

    /**
     * The issuer setting, without a trailing slash, so it can prefix the discovery path.
     *
     * @return string
     */
    private function issuer(): string
    {
        return rtrim($this->config->string($this->key('issuer')), '/');
    }

    /**
     * A subject is only unique within its issuer, so a user is identified by both. Hashing them
     * together also keeps the id inside the 191 characters the identities table allows, where a
     * subject alone may be up to 255.
     *
     * @param string
     * @return string
     */
    private function subject(string $sub): string
    {
        return hash('sha256', $this->issuer()."\n".$sub);
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
     */
    private function discovery(): array
    {
        $issuer = $this->issuer();

        return Cache::remember('sso:oidc:discovery:'.hash('sha256', $issuer), self::DISCOVERY_TTL, fn (): array => $this->discover($issuer));
    }

    /**
     * Fetches the issuer's discovery document (OpenID Connect Discovery 1.0, section 4). A failed or
     * invalid fetch throws, so nothing is cached and the next sign-in tries again.
     *
     * @param string
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
     */
    private function discover(string $issuer): array
    {
        $document = Http::acceptJson()->timeout(10)->get($issuer.'/.well-known/openid-configuration')->throw()->json();

        // The document must name the issuer it was fetched for. Trailing slashes are ignored, since
        // authentik's issuer ends in one and the setting may or may not.
        if (! is_array($document) || ! is_string($document['issuer'] ?? null) || rtrim($document['issuer'], '/') !== $issuer) {
            throw new SsoException(SsoException::PROVIDER);
        }

        $endpoints = [];
        foreach (self::ENDPOINTS as $key) {
            $url = $document[$key] ?? null;
            if (! is_string($url) || ! $this->isHttpUrl($url)) {
                throw new SsoException(SsoException::PROVIDER);
            }

            $endpoints[$key] = $url;
        }

        return $endpoints;
    }

    /**
     * @param array
     * @param string
     * @return string|null
     */
    private function claim(array $user, string $key): ?string
    {
        $value = $user[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }

    /**
     * email_verified is a boolean, but some providers (AWS Cognito, for one) send the string "true".
     * Anything else, a missing claim included, counts as unverified.
     *
     * @param mixed
     * @return bool
     */
    private function verified(mixed $claim): bool
    {
        return $claim === true || $claim === 'true';
    }

    /**
     * @param mixed
     * @return string|null
     */
    private function avatar(mixed $picture): ?string
    {
        return is_string($picture) && mb_strlen($picture) <= 2048 && $this->isHttpUrl($picture) ? $picture : null;
    }

    /**
     * @param string
     * @return bool
     */
    private function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) && is_string(parse_url($url, PHP_URL_HOST));
    }
}
