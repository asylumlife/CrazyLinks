/**
 * ESLint flat config (ESLint 9+, used by `wp-scripts lint-js`). Extends the @wordpress/scripts default.
 */
const base = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	{
		ignores: [ 'assets/build/**', 'vendor/**', 'node_modules/**', 'assets/src/frontend.js', 'assets/src/block-editor.js' ],
	},
	...base,
	{
		files: [ '**/*.ts', '**/*.tsx' ],
		rules: {
			// TypeScript resolves these; the import plugin cannot see externals provided by WordPress.
			'import/no-unresolved': 'off',
			'import/no-extraneous-dependencies': 'off',
			'jsdoc/require-param-type': 'off',
			'jsdoc/require-returns-type': 'off',
			'jsdoc/require-param': 'off',
			'jsdoc/require-returns': 'off',
			'no-undef': 'off',
			'no-unused-vars': 'off',
		},
	},
	{
		files: [ 'tests/e2e/**' ],
		rules: {
			'jest/no-done-callback': 'off',
		},
	},
];
