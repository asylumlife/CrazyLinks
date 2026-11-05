<?php
/**
 * Ops schema, validator, applier and change list.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Ai\ChangeList;
use CrazyLinks\Ai\OpsApplier;
use CrazyLinks\Ai\OpsException;
use CrazyLinks\Ai\OpsSchema;
use CrazyLinks\Ai\OpsValidator;
use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Schema\SchemaValidator;
use CrazyLinks\Tests\Fixtures\AiHelpers;

require_once dirname( __DIR__ ) . '/fixtures/ai/AiHelpers.php';

/**
 * Accept/reject matrix and deterministic applier fixtures.
 */
class AiOpsTest extends TestCase {

	use AiHelpers;

	private array $base;

	public function set_up(): void {
		parent::set_up();
		$this->as_role( 'administrator' );
		$result = ( new PageValidator() )->validate( $this->musician(), get_current_user_id() );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$this->base = $result->document();
	}

	private function id( string $n ): string {
		return '01JM' . str_repeat( '0', 20 ) . $n;
	}

	private function ops( array $operations, string $mode = 'edit', array $extra = [] ): \CrazyLinks\Ai\OpsResult {
		$n   = 0;
		$ids = static function () use ( &$n ): string {
			++$n;
			return '01JN' . str_pad( (string) $n, 22, '0', STR_PAD_LEFT );
		};
		return ( new OpsValidator( new OpsApplier( $ids ) ) )->validate(
			[
				'summary'    => 'x',
				'operations' => $operations,
				'questions'  => [],
			] + $extra,
			$this->base,
			get_current_user_id(),
			$mode
		);
	}

	public function test_schema_file_is_flat_strict_and_excludes_html(): void {
		$schema = OpsSchema::get();
		$this->assertSame( [ 'summary', 'operations', 'questions' ], $schema['required'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( [ 'summary', 'operations', 'questions' ], array_keys( $schema['properties'] ) );
		$this->assertNotContains( 'html', OpsSchema::block_types() );
		$this->assertArrayNotHasKey( 'props_html', $schema['$defs'] );
		$this->assertArrayNotHasKey( 'custom_css', $schema['$defs']['design']['properties'] );
		$ops = array_map( static fn( $r ) => $r['$ref'], $schema['properties']['operations']['items']['anyOf'] );
		$this->assertSame( [ '#/$defs/op_add_block', '#/$defs/op_update_block', '#/$defs/op_move_block', '#/$defs/op_remove_block', '#/$defs/op_set_design', '#/$defs/op_set_profile' ], $ops );

		// Every object node is closed.
		$walk = function ( $node, string $path ) use ( &$walk ): void {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( ( $node['type'] ?? null ) === 'object' && isset( $node['properties'] ) ) {
				$this->assertFalse( $node['additionalProperties'] ?? true, $path . ' must set additionalProperties false' );
			}
			foreach ( $node as $k => $v ) {
				$walk( $v, $path . '/' . $k );
			}
		};
		$walk( $schema, '' );

		// Non-recursive: no definition references itself, directly or through others.
		$refs = [];
		foreach ( $schema['$defs'] as $name => $def ) {
			preg_match_all( '~#/\$defs/([a-z_0-9]+)~', (string) wp_json_encode( $def, JSON_UNESCAPED_SLASHES ), $m );
			$refs[ $name ] = array_unique( $m[1] );
		}
		$visit = function ( string $name, array $stack ) use ( &$visit, $refs ): void {
			$this->assertNotContains( $name, $stack, 'Recursive $ref: ' . implode( ' > ', $stack ) );
			foreach ( $refs[ $name ] ?? [] as $next ) {
				$visit( $next, array_merge( $stack, [ $name ] ) );
			}
		};
		foreach ( array_keys( $refs ) as $name ) {
			$visit( $name, [] );
		}
	}

	public function test_block_props_stay_in_sync_with_the_page_schema(): void {
		$page = PageValidator::schema()['$defs'];
		$ops  = OpsSchema::get()['$defs'];
		$all  = array_diff( $page['block']['properties']['type']['enum'], [ 'html' ] );
		$this->assertEqualsCanonicalizing( array_values( $all ), OpsSchema::block_types() );
		foreach ( $all as $type ) {
			$this->assertSame( $page[ 'props_' . $type ], $ops[ 'props_' . $type ], $type );
			$patch = $page[ 'props_' . $type ];
			unset( $patch['required'] );
			$this->assertSame( $patch, $ops[ 'patch_' . $type ], $type );
		}
		$this->assertSame( $page['profile'], $ops['profile'] );
		$design = $page['design']['properties'];
		unset( $design['custom_css'] );
		$this->assertSame( $design, $ops['design']['properties'] );
	}

	public function test_provider_schema_drops_unsupported_keywords_but_keeps_property_names(): void {
		$p    = OpsSchema::for_provider();
		$json = (string) wp_json_encode( $p );
		foreach ( [ '"maxLength"', '"minLength"', '"minimum"', '"maximum"', '"pattern":"', '"maxItems"' ] as $kw ) {
			$this->assertStringNotContainsString( $kw, $json, $kw );
		}
		$this->assertSame( [ '$ref' => '#/$defs/background' ], $p['$defs']['design']['properties']['background'] );
		$this->assertArrayHasKey( 'pattern', $p['$defs']['background']['properties'], 'A property named "pattern" survives' );
		$this->assertArrayHasKey( 'weekly', $p['$defs']['schedule']['properties'], 'Weekly schedule windows are expressible' );
		$this->assertArrayHasKey( 'background', $p['$defs']['props_group']['properties'], 'Sections can set a background' );
		$this->assertArrayHasKey( 'title', $p['$defs']['props_link']['properties'] );
		$this->assertSame( [ 'title', 'url' ], $p['$defs']['props_link']['required'] );
	}

	public function test_schema_validator_accepts_the_unionless_view(): void {
		$errors = ( new SchemaValidator( OpsSchema::get() ) )->validate_with(
			[
				'summary'    => 's',
				'operations' => [],
				'questions'  => [],
			],
			OpsSchema::without_unions( OpsSchema::get() )
		);
		$this->assertSame( [], $errors );
	}

	/**
	 * Valid operations.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1?: string}>
	 */
	public function accept_provider(): array {
		$a = static fn( $n ) => '01JM' . str_repeat( '0', 20 ) . $n;
		return [
			'update props'          => [ [ [ 'op' => 'update_block', 'id' => $a( 'A3' ), 'props' => [ 'title' => 'New' ] ] ] ],
			'update flags'          => [ [ [ 'op' => 'update_block', 'id' => $a( 'A3' ), 'pinned' => true, 'visible' => false, 'animation' => 'pulse', 'tracking' => false ] ] ],
			'update schedule'       => [ [ [ 'op' => 'update_block', 'id' => $a( 'A6' ), 'schedule' => [ 'start' => '2030-01-01T00:00:00Z', 'end' => '2030-02-01T00:00:00Z' ] ] ] ],
			'add link mailto'       => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'link', 'props' => [ 'title' => 'Email', 'url' => 'mailto:a@example.com' ] ] ] ] ],
			'add heading'           => [ [ [ 'op' => 'add_block', 'after_id' => $a( 'A1' ), 'block' => [ 'type' => 'heading', 'props' => [ 'text' => 'Music', 'level' => 'h2' ] ] ] ] ],
			'add with ref chain'    => [ [ [ 'op' => 'add_block', 'after_id' => null, 'ref' => 'new-1', 'block' => [ 'type' => 'group', 'props' => [ 'title' => 'G' ] ] ], [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'link', 'parent_id' => 'new-1', 'props' => [ 'title' => 'In', 'url' => 'https://example.com' ] ] ] ] ],
			'move'                  => [ [ [ 'op' => 'move_block', 'id' => $a( 'A2' ), 'after_id' => $a( 'A6' ) ] ] ],
			'remove'                => [ [ [ 'op' => 'remove_block', 'id' => $a( 'A2' ) ] ] ],
			'design'                => [ [ [ 'op' => 'set_design', 'design' => [ 'theme' => 'midnight', 'button' => [ 'shape' => 'pill' ] ] ] ] ],
			'profile'               => [ [ [ 'op' => 'set_profile', 'profile' => [ 'bio' => 'Hi', 'pronouns' => 'they/them' ] ] ] ],
			'restyle design only'   => [ [ [ 'op' => 'set_design', 'design' => [ 'accent' => '#ff0000' ] ] ], 'restyle' ],
			'analytics pin'         => [ [ [ 'op' => 'update_block', 'id' => $a( 'A2' ), 'pinned' => true ] ], 'analytics_qa' ],
			'questions without ops' => [ [] ],
		];
	}

	/**
	 * @dataProvider accept_provider
	 */
	public function test_accepts( array $operations, string $mode = 'edit' ): void {
		$result = $this->ops( $operations, $mode );
		$this->assertTrue( $result->is_valid(), $result->error_text() );
	}

	/**
	 * Invalid operations and the error each must produce.
	 *
	 * @return array<string, array{0: mixed, 1: string, 2?: string}>
	 */
	public function reject_provider(): array {
		$a = static fn( $n ) => '01JM' . str_repeat( '0', 20 ) . $n;
		return [
			'not an object'           => [ 'nope', 'must be a JSON object' ],
			'unknown op'              => [ [ [ 'op' => 'rename_block', 'id' => $a( 'A2' ) ] ], 'op must be one of' ],
			'extra op property'       => [ [ [ 'op' => 'remove_block', 'id' => $a( 'A2' ), 'force' => true ] ], 'Unknown property' ],
			'html add'                => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'html', 'props' => [ 'html' => '<b>x</b>' ] ] ] ], 'cannot be added by AI' ],
			'unknown type'            => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'carousel', 'props' => [] ] ] ], 'cannot be added by AI' ],
			'missing required prop'   => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'link', 'props' => [ 'title' => 'No URL' ] ] ] ], 'Is required' ],
			'wrong prop for type'     => [ [ [ 'op' => 'update_block', 'id' => $a( 'A3' ), 'props' => [ 'text' => 'heading text' ] ] ], 'Unknown property' ],
			'javascript url'          => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'link', 'props' => [ 'title' => 'x', 'url' => 'javascript:alert(1)' ] ] ] ], 'URL is not allowed' ],
			'data url in update'      => [ [ [ 'op' => 'update_block', 'id' => $a( 'A3' ), 'props' => [ 'url' => 'data:text/html,hi' ] ] ], 'URL is not allowed' ],
			'protocol relative'       => [ [ [ 'op' => 'update_block', 'id' => $a( 'A3' ), 'props' => [ 'url' => '//evil.example' ] ] ], 'URL is not allowed' ],
			'tel in embed'            => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'embed', 'props' => [ 'url' => 'tel:123' ] ] ] ], 'URL is not allowed' ],
			'social javascript'       => [ [ [ 'op' => 'update_block', 'id' => $a( 'A1' ), 'props' => [ 'items' => [ [ 'network' => 'x', 'url' => 'javascript:1' ] ] ] ] ], 'URL is not allowed' ],
			'deep link scheme'        => [ [ [ 'op' => 'update_block', 'id' => $a( 'A3' ), 'props' => [ 'deep_link' => 'javascript:alert(1)' ] ] ], 'deep link scheme' ],
			'script in text'          => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'text', 'props' => [ 'content' => '<script>x</script>' ] ] ] ], 'Text may only use' ],
			'unknown id'              => [ [ [ 'op' => 'remove_block', 'id' => '01JM0000000000000000000ZZZ' ] ], 'no block with id' ],
			'unknown after_id'        => [ [ [ 'op' => 'move_block', 'id' => $a( 'A2' ), 'after_id' => 'new-9' ] ], 'no block with id' ],
			'bad id format'           => [ [ [ 'op' => 'remove_block', 'id' => 'link-2' ] ], 'invalid format' ],
			'move after itself'       => [ [ [ 'op' => 'move_block', 'id' => $a( 'A2' ), 'after_id' => $a( 'A2' ) ] ], 'after itself' ],
			'parent not a group'      => [ [ [ 'op' => 'move_block', 'id' => $a( 'A2' ), 'after_id' => null, 'parent_id' => $a( 'A3' ) ] ], 'must be a group' ],
			'nested group'            => [ [ [ 'op' => 'add_block', 'after_id' => null, 'ref' => 'new-1', 'block' => [ 'type' => 'group', 'props' => [] ] ], [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'group', 'parent_id' => 'new-1', 'props' => [] ] ] ], 'Groups cannot be nested' ],
			'duplicate ref'           => [ [ [ 'op' => 'add_block', 'after_id' => null, 'ref' => 'new-1', 'block' => [ 'type' => 'divider', 'props' => [] ] ], [ 'op' => 'add_block', 'after_id' => null, 'ref' => 'new-1', 'block' => [ 'type' => 'divider', 'props' => [] ] ] ], 'already used' ],
			'schedule backwards'      => [ [ [ 'op' => 'update_block', 'id' => $a( 'A6' ), 'schedule' => [ 'start' => '2031-01-01T00:00:00Z', 'end' => '2030-01-01T00:00:00Z' ] ] ], 'end must be after start' ],
			'schedule in the past'    => [ [ [ 'op' => 'update_block', 'id' => $a( 'A6' ), 'schedule' => [ 'end' => '2001-01-01T00:00:00Z' ] ] ], 'in the past' ],
			'bad date'                => [ [ [ 'op' => 'update_block', 'id' => $a( 'A6' ), 'schedule' => [ 'end' => 'next friday' ] ] ], 'ISO 8601' ],
			'image not attachment'    => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'image', 'props' => [ 'image' => 424242 ] ] ] ], 'media library' ],
			'avatar not attachment'   => [ [ [ 'op' => 'set_profile', 'profile' => [ 'avatar' => 424242 ] ] ], 'media library' ],
			'background not attachment' => [ [ [ 'op' => 'set_design', 'design' => [ 'background' => [ 'type' => 'image', 'image' => 424242 ] ] ] ], 'media library' ],
			'gallery not attachment'  => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'gallery', 'props' => [ 'items' => [ [ 'image' => 424242 ] ] ] ] ] ], 'media library' ],
			'custom css'              => [ [ [ 'op' => 'set_design', 'design' => [ 'custom_css' => 'body{}' ] ] ], 'Unknown property' ],
			'unknown theme'           => [ [ [ 'op' => 'set_design', 'design' => [ 'theme' => 'no-such-theme' ] ] ], 'Unknown theme' ],
			'bad color'               => [ [ [ 'op' => 'set_design', 'design' => [ 'accent' => 'red' ] ] ], 'invalid format' ],
			'bio too long'            => [ [ [ 'op' => 'set_profile', 'profile' => [ 'bio' => str_repeat( 'x', 281 ) ] ] ], 'at most 280' ],
			'payment wrong host'      => [ [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'payment', 'props' => [ 'service' => 'kofi', 'url' => 'https://evil.example/pay' ] ] ] ], 'does not belong' ],
			'too many ops'            => [ array_fill( 0, 61, [ 'op' => 'set_profile', 'profile' => [ 'name' => 'x' ] ] ), 'at most 60' ],
			'restyle with content'    => [ [ [ 'op' => 'remove_block', 'id' => $a( 'A2' ) ] ], 'only use set_design', 'restyle' ],
			'analytics with props'    => [ [ [ 'op' => 'update_block', 'id' => $a( 'A2' ), 'props' => [ 'title' => 'x' ] ] ], 'only pin', 'analytics_qa' ],
			'missing summary'         => [ [ 'operations' => [], 'questions' => [] ], 'summary' ],
		];
	}

	/**
	 * @dataProvider reject_provider
	 */
	public function test_rejects( $operations, string $needle, string $mode = 'edit' ): void {
		if ( is_array( $operations ) && array_is_list( $operations ) ) {
			$result = $this->ops( $operations, $mode );
		} else {
			$result = ( new OpsValidator() )->validate( $operations, $this->base, get_current_user_id(), $mode );
		}
		$this->assertFalse( $result->is_valid(), 'Expected rejection' );
		$this->assertStringContainsString( $needle, $result->error_text() );
	}

	public function test_html_blocks_are_never_touched(): void {
		$doc             = $this->base;
		$doc['blocks'][] = [
			'id'    => '01JM00000000000000000000H1',
			'type'  => 'html',
			'props' => [ 'html' => '<b>x</b>' ],
		];
		$doc    = ( new PageValidator() )->validate( $doc, get_current_user_id() )->document();
		$editor = $this->as_role( 'editor' );
		foreach ( [
			[ 'op' => 'update_block', 'id' => '01JM00000000000000000000H1', 'visible' => false ],
			[ 'op' => 'move_block', 'id' => '01JM00000000000000000000H1', 'after_id' => null ],
			[ 'op' => 'remove_block', 'id' => '01JM00000000000000000000H1' ],
		] as $op ) {
			$r = ( new OpsValidator() )->validate( [ 'summary' => '', 'operations' => [ $op ], 'questions' => [] ], $doc, $editor );
			$this->assertStringContainsString( 'Custom HTML blocks cannot be changed', $r->error_text(), $op['op'] );
		}
		// Other edits on a page with someone else's HTML block still pass PageValidator for an editor.
		$r = ( new OpsValidator() )->validate( [ 'summary' => '', 'operations' => [ [ 'op' => 'update_block', 'id' => $this->id( 'A3' ), 'props' => [ 'title' => 'ok' ] ] ], 'questions' => [] ], $doc, $editor );
		$this->assertTrue( $r->is_valid(), $r->error_text() );
	}

	public function test_page_validator_errors_point_at_block_ids(): void {
		// Payment with no handle or URL passes the ops schema but not PageValidator.
		$result = $this->ops( [ [ 'op' => 'add_block', 'after_id' => null, 'block' => [ 'type' => 'payment', 'props' => [ 'service' => 'kofi' ] ] ] ] );
		$this->assertFalse( $result->is_valid() );
		$this->assertStringContainsString( '/page/blocks/01JN0000000000000000000001/props/url', $result->error_text() );
	}

	public function test_applier_placement_rules(): void {
		$applier = new OpsApplier( OpsApplier::sequence( [ '01JN0000000000000000000001', '01JN0000000000000000000002' ] ) );
		$out     = $applier->apply(
			$this->base,
			[
				[ 'op' => 'add_block', 'after_id' => null, 'ref' => 'new-1', 'block' => [ 'type' => 'group', 'props' => [ 'title' => 'G' ] ] ],
				[ 'op' => 'move_block', 'id' => $this->id( 'A6' ), 'after_id' => null, 'parent_id' => 'new-1' ],
				// "After the group, inside the group" = first in the group.
				[ 'op' => 'add_block', 'after_id' => 'new-1', 'block' => [ 'type' => 'divider', 'parent_id' => 'new-1', 'props' => [] ] ],
				[ 'op' => 'move_block', 'id' => $this->id( 'A7' ), 'after_id' => $this->id( 'A1' ) ],
			]
		);
		$doc = ( new PageValidator() )->validate( $out['doc'], get_current_user_id() )->document();
		$this->assertSame( [ 'new-1' => '01JN0000000000000000000001' ], $out['refs'] );
		$order = array_map( static fn( $b ) => [ $b['id'], $b['parent_id'], $b['order'] ], $doc['blocks'] );
		$this->assertSame(
			[
				[ '01JN0000000000000000000001', null, 0 ],
				[ '01JN0000000000000000000002', '01JN0000000000000000000001', 0 ],
				[ $this->id( 'A6' ), '01JN0000000000000000000001', 1 ],
				[ $this->id( 'A1' ), null, 1 ],
				[ $this->id( 'A7' ), null, 2 ],
				[ $this->id( 'A2' ), null, 3 ],
				[ $this->id( 'A3' ), null, 4 ],
				[ $this->id( 'A4' ), null, 5 ],
				[ $this->id( 'A5' ), null, 6 ],
			],
			$order
		);
	}

	public function test_applier_is_pure_and_deterministic(): void {
		$ops   = [
			[ 'op' => 'add_block', 'after_id' => $this->id( 'A7' ), 'block' => [ 'type' => 'link', 'props' => [ 'title' => 'X', 'url' => 'https://x.example' ] ] ],
			[ 'op' => 'set_design', 'design' => [ 'button' => [ 'shape' => 'pill' ] ] ],
		];
		$base  = $this->base;
		$one   = ( new OpsApplier( OpsApplier::sequence( [ '01JN0000000000000000000001' ] ) ) )->apply( $base, $ops );
		$two   = ( new OpsApplier( OpsApplier::sequence( [ '01JN0000000000000000000001' ] ) ) )->apply( $base, $ops );
		$this->assertSame( $one, $two );
		$this->assertSame( $this->base, $base, 'Input untouched' );
	}

	public function test_remove_group_takes_children_and_design_theme_resets_theme_keys(): void {
		$applier = new OpsApplier();
		$doc     = $applier->apply(
			$this->base,
			[
				[ 'op' => 'set_design', 'design' => [ 'accent' => '#ff0000', 'button' => [ 'shape' => 'pill' ] ] ],
				[ 'op' => 'set_design', 'design' => [ 'button' => [ 'shadow' => 'hard' ] ] ],
			]
		)['doc'];
		$this->assertSame(
			[
				'shape'  => 'pill',
				'shadow' => 'hard',
			],
			$doc['design']['button'],
			'Nested design objects merge'
		);
		$doc = $applier->apply( $doc, [ [ 'op' => 'set_design', 'design' => [ 'theme' => 'midnight' ] ] ] )['doc'];
		$this->assertSame( [ 'theme' => 'midnight' ], $doc['design'], 'A new theme clears what the theme owns' );

		$cafe = ( new PageValidator() )->validate( $this->eval_set()['documents']['cafe'], get_current_user_id() )->document();
		$out  = $applier->apply( $cafe, [ [ 'op' => 'remove_block', 'id' => '01JC00000000000000000000C2' ] ] )['doc'];
		$this->assertCount( 4, $out['blocks'], 'Group and its two children removed' );
	}

	public function test_applier_exceptions_carry_the_operation_path(): void {
		try {
			( new OpsApplier() )->apply( $this->base, [ [ 'op' => 'set_profile', 'profile' => [] ], [ 'op' => 'remove_block', 'id' => 'new-4' ] ] );
			$this->fail( 'Expected OpsException' );
		} catch ( OpsException $e ) {
			$this->assertSame( '/operations/1/id', $e->field() );
		}
	}

	public function test_change_list(): void {
		$ops   = [
			[ 'op' => 'add_block', 'after_id' => null, 'ref' => 'new-1', 'block' => [ 'type' => 'link', 'props' => [ 'title' => 'Tour', 'url' => 'https://x.example' ] ] ],
			[ 'op' => 'update_block', 'id' => $this->id( 'A3' ), 'props' => [ 'title' => 'x', 'url' => 'https://y.example' ] ],
			[ 'op' => 'update_block', 'id' => $this->id( 'A2' ), 'pinned' => true ],
			[ 'op' => 'move_block', 'id' => $this->id( 'A5' ), 'after_id' => 'new-1' ],
			[ 'op' => 'remove_block', 'id' => $this->id( 'A6' ) ],
			[ 'op' => 'set_design', 'design' => [ 'button' => [ 'shape' => 'pill' ], 'accent' => '#f97316' ] ],
			[ 'op' => 'set_profile', 'profile' => [ 'bio' => 'Hi' ] ],
		];
		$items = ChangeList::build( $ops, $this->base, [ '01JN0000000000000000000001' ] );
		$this->assertSame( [ 'add', 'update', 'update', 'move', 'remove', 'design', 'profile' ], array_column( $items, 'kind' ) );
		$this->assertSame( 'Add link “Tour” at the top', $items[0]['text'] );
		$this->assertSame( '01JN0000000000000000000001', $items[0]['block_id'] );
		$this->assertSame( 'Change link “New single: Harbor Lights”: title, URL', $items[1]['text'] );
		$this->assertSame( 'Pin link “Listen to Low Tide (album)” to the top', $items[2]['text'] );
		$this->assertSame( 'Move link “The Rivers Podcast” after link “Tour”', $items[3]['text'] );
		$this->assertSame( 'Remove link “Tour dates”', $items[4]['text'] );
		$this->assertSame( 'Restyle: button shape → pill, accent color → #f97316', $items[5]['text'] );
		$this->assertSame( 'Update the profile: bio', $items[6]['text'] );
	}
}
