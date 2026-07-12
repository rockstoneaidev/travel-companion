import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

/**
 * Vitest gets its own config, deliberately.
 *
 * Vitest would otherwise inherit `vite.config.ts`, which loads the Laravel plugin
 * — and that plugin refuses to start in CI ("You should not run the Vite HMR
 * server in CI environments"), so the whole run dies before a single test is
 * collected. We are not serving assets here, we are running pure functions; the
 * dev-server plumbing has no business in the test config.
 *
 * The alternative, LARAVEL_BYPASS_ENV_CHECK=1, silences a warning that is telling
 * the truth. This removes the reason for it instead.
 */
export default defineConfig({
    resolve: {
        alias: { '@': resolve(import.meta.dirname, 'resources/js') },
    },
    test: {
        include: ['resources/js/**/*.test.ts'],
        environment: 'node',
    },
});
