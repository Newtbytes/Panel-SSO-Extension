import { defineConfig } from 'vitest/config';
import { pterodactylTestAliases, pterodactylTestSetup } from '../../packages/sdk/vitest.mjs';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    resolve: {
        alias: pterodactylTestAliases(),
    },
    server: {
        fs: {
            // The SDK's setup file is in the panel (../../packages/sdk), outside this directory, and
            // Vite only serves files from the root it was started in unless they are allowed here.
            allow: [fileURLToPath(new URL('../../', import.meta.url))],
        },
    },
    test: {
        name: 'sso',
        environment: 'jsdom',
        setupFiles: [pterodactylTestSetup],
        include: ['src/**/*.spec.{ts,tsx}'],
        clearMocks: true,
        restoreMocks: true,
    },
});
