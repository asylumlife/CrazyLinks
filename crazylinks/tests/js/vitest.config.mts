/**
 * Vitest config for the editor unit tests (`npm run test:unit` → wp-scripts test-unit-js).
 */
import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

export default defineConfig( {
	root: fileURLToPath( new URL( '../..', import.meta.url ) ),
	test: {
		include: [ 'tests/js/**/*.test.ts' ],
		exclude: [ 'node_modules/**', 'vendor/**' ],
		environment: 'node',
		globals: true,
	},
} );
