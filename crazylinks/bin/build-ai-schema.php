<?php
/**
 * Regenerates schemas/ai-ops.v1.json from schemas/page.v1.json.
 *
 * Usage: php bin/build-ai-schema.php  (AiOpsTest fails when the two drift apart)
 *
 * @package CrazyLinks
 */

PHP_SAPI === 'cli' || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- local CLI build script reading and writing repository files.

( static function ( string $root ): void {

	$page  = json_decode( file_get_contents( $root . '/schemas/page.v1.json' ), true );
	$defs  = $page['$defs'];
	$types = array_values( array_diff( $defs['block']['properties']['type']['enum'], [ 'html' ] ) );

	$out = [
		'$schema'              => 'https://json-schema.org/draft/2020-12/schema',
		'$id'                  => 'https://crazylinks.dev/schemas/ai-ops.v1.json',
		'title'                => 'CrazyLinks AI operations, schema v1',
		'description'          => 'What the AI returns: a summary, a list of operations on the page document, and questions when the request is ambiguous. Flat and non-recursive, additionalProperties false throughout. Custom HTML blocks and custom CSS are not expressible. Block props mirror schemas/page.v1.json (a test keeps them in sync).',
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => [ 'summary', 'operations', 'questions' ],
		'properties'           => [
			'summary'    => [
				'type'        => 'string',
				'maxLength'   => 600,
				'description' => 'One or two plain sentences describing the change (or answering an analytics question).',
			],
			'operations' => [
				'type'     => 'array',
				'maxItems' => 60,
				'items'    => [
					'anyOf' => [
						[ '$ref' => '#/$defs/op_add_block' ],
						[ '$ref' => '#/$defs/op_update_block' ],
						[ '$ref' => '#/$defs/op_move_block' ],
						[ '$ref' => '#/$defs/op_remove_block' ],
						[ '$ref' => '#/$defs/op_set_design' ],
						[ '$ref' => '#/$defs/op_set_profile' ],
					],
				],
			],
			'questions'  => [
				'type'        => 'array',
				'maxItems'    => 5,
				'items'       => [
					'type'      => 'string',
					'maxLength' => 300,
				],
				'description' => 'Ask instead of guessing. When non-empty, operations should be empty.',
			],
		],
		'$defs'                => [],
	];
	$d   = &$out['$defs'];
	foreach ( [ 'ulid', 'color', 'color_or_empty', 'datetime_or_null', 'background', 'schedule' ] as $k ) {
		$d[ $k ] = $defs[ $k ];
	}
	$d['block_ref']         = [
		'type'        => 'string',
		'pattern'     => '^(?:[0-9A-HJKMNP-TV-Z]{26}|new-[0-9]{1,3})$',
		'description' => 'An existing block id, or the ref of a block added earlier in this list (new-1, new-2, ...).',
	];
	$d['block_ref_or_null'] = [
		'type'    => [ 'string', 'null' ],
		'pattern' => '^(?:[0-9A-HJKMNP-TV-Z]{26}|new-[0-9]{1,3})$',
	];
	$d['profile']           = $defs['profile'];
	$design                 = $defs['design'];
	unset( $design['properties']['custom_css'] );
	$design['description'] = 'Design patch. Objects (background, text, button) merge into the current values; setting theme resets background, text, button, accent and radius to the theme first.';
	$d['design']           = $design;
	// Nested design objects (text, button) are inline in the design def; background is a shared def (also used by sections).
	$common     = [
		'parent_id' => [ '$ref' => '#/$defs/block_ref_or_null' ],
		'visible'   => [ 'type' => 'boolean' ],
		'schedule'  => [ '$ref' => '#/$defs/schedule' ],
		'pinned'    => [ 'type' => 'boolean' ],
		'animation' => $defs['block']['properties']['animation'],
		'tracking'  => [ 'type' => 'boolean' ],
	];
	$new_refs   = [];
	$patch_refs = [];
	foreach ( $types as $t ) {
		$props              = $defs[ 'props_' . $t ];
		$d[ 'props_' . $t ] = $props;
		$patch              = $props;
		unset( $patch['required'] );
		// Patches may not blank a required field either, so property constraints (minLength etc.) stay as-is.
		$d[ 'patch_' . $t ] = $patch;
		$d[ 'new_' . $t ]   = [
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => [ 'type', 'props' ],
			'properties'           => [ 'type' => [ 'const' => $t ] ] + $common + [ 'props' => [ '$ref' => '#/$defs/props_' . $t ] ],
		];
		$new_refs[]         = [ '$ref' => '#/$defs/new_' . $t ];
		$patch_refs[]       = [ '$ref' => '#/$defs/patch_' . $t ];
	}
	$d['op_add_block']    = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => [ 'op', 'after_id', 'block' ],
		'description'          => 'Adds a block. after_id null puts it first (at the top of the page, or first inside its group); otherwise right after that block, in the same group as that block.',
		'properties'           => [
			'op'       => [ 'const' => 'add_block' ],
			'after_id' => [ '$ref' => '#/$defs/block_ref_or_null' ],
			'ref'      => [
				'type'        => 'string',
				'pattern'     => '^new-[0-9]{1,3}$',
				'description' => 'Optional name (new-1, new-2, ...) so later operations can refer to this block.',
			],
			'block'    => [ 'anyOf' => $new_refs ],
		],
	];
	$d['op_update_block'] = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => [ 'op', 'id' ],
		'description'          => 'Changes a block. props are merged into the current props (only list what changes); the props must belong to the block\'s type.',
		'properties'           => [
			'op'        => [ 'const' => 'update_block' ],
			'id'        => [ '$ref' => '#/$defs/block_ref' ],
			'props'     => [ 'anyOf' => $patch_refs ],
			'visible'   => [ 'type' => 'boolean' ],
			'schedule'  => [ '$ref' => '#/$defs/schedule' ],
			'pinned'    => [ 'type' => 'boolean' ],
			'animation' => $defs['block']['properties']['animation'],
			'tracking'  => [ 'type' => 'boolean' ],
		],
	];
	$d['op_move_block']   = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => [ 'op', 'id', 'after_id' ],
		'description'          => 'Moves a block. after_id null moves it first in its parent; parent_id (optional) moves it into a group, or to the top level with null.',
		'properties'           => [
			'op'        => [ 'const' => 'move_block' ],
			'id'        => [ '$ref' => '#/$defs/block_ref' ],
			'after_id'  => [ '$ref' => '#/$defs/block_ref_or_null' ],
			'parent_id' => [ '$ref' => '#/$defs/block_ref_or_null' ],
		],
	];
	$d['op_remove_block'] = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => [ 'op', 'id' ],
		'description'          => 'Removes a block (a group is removed with its children).',
		'properties'           => [
			'op' => [ 'const' => 'remove_block' ],
			'id' => [ '$ref' => '#/$defs/block_ref' ],
		],
	];
	$d['op_set_design']   = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => [ 'op', 'design' ],
		'properties'           => [
			'op'     => [ 'const' => 'set_design' ],
			'design' => [ '$ref' => '#/$defs/design' ],
		],
	];
	$d['op_set_profile']  = [
		'type'                 => 'object',
		'additionalProperties' => false,
		'required'             => [ 'op', 'profile' ],
		'description'          => 'Profile patch, merged into the current profile.',
		'properties'           => [
			'op'      => [ 'const' => 'set_profile' ],
			'profile' => [ '$ref' => '#/$defs/profile' ],
		],
	];
	file_put_contents( $root . '/schemas/ai-ops.v1.json', json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
	echo "ok\n";
} )( $argv[1] ?? dirname( __DIR__ ) );
