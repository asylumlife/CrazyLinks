/**
 * Playwright smoke test for the page editor against the local dev site (no Docker).
 *
 * E2E_BASE_URL   default http://crazylinks.test:8888
 * E2E_USER       default admin
 * E2E_PASS       wp-admin password (form login for the editor UI)
 * E2E_APP_PASS   application password (REST setup, verification and cleanup)
 */
import { defineConfig } from '@playwright/test';

export default defineConfig( {
	testDir: '.',
	timeout: 90_000,
	retries: 0,
	workers: 1,
	reporter: 'list',
	outputDir: '../../test-results',
	use: {
		baseURL: process.env.E2E_BASE_URL ?? 'http://crazylinks.test:8888',
		channel: process.env.E2E_CHANNEL ?? 'chrome',
		headless: true,
		viewport: { width: 1440, height: 1000 },
		trace: 'retain-on-failure',
	},
} );
