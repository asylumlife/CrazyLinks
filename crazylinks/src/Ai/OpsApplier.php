<?php
/**
 * Applies AI operations to a page document.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Schema\Ulid;
use CrazyLinks\Schema\ValidationResult;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- OpsException messages are data for the validator and are escaped wherever they are displayed.

/**
 * Pure function from (document, operations) to a new document. No database access, no globals; the only
 * input besides the arguments is the id factory, so tests can make new block ids deterministic.
 *
 * Placement rules (also stated in the system prompt):
 * - `after_id: null` puts the block first in its parent (the page, or a group);
 * - otherwise right after `after_id`, in the same parent as that block;
 * - `parent_id` moves a block into a group (or to the top level with null); groups never nest.
 *
 * The result still has to pass PageValidator (finalize()), exactly like a human save.
 */
final class OpsApplier {

	public const MAX_BLOCKS = 200;

	/**
	 * New block id factory.
	 *
	 * @var callable(): string
	 */
	private $ids;

	/**
	 * Constructor.
	 *
	 * @param callable|null $id_factory Returns a new ULID per call (default Ulid::generate).
	 */
	public function __construct( ?callable $id_factory = null ) {
		$this->ids = $id_factory ?? [ Ulid::class, 'generate' ];
	}

	/**
	 * An id factory that hands out the given ids first, then fresh ones.
	 *
	 * @param string[] $ids Pre-generated ids.
	 * @return callable(): string
	 */
	public static function sequence( array $ids ): callable {
		return static function () use ( &$ids ): string {
			$next = array_shift( $ids );
			return is_string( $next ) && Ulid::is_valid( $next ) ? $next : Ulid::generate();
		};
	}

	/**
	 * Applies every operation in order.
	 *
	 * @param array<string, mixed>             $doc        Document.
	 * @param array<int, array<string, mixed>> $operations Operations.
	 * @return array{doc: array<string, mixed>, refs: array<string, string>, added: string[]}
	 * @throws OpsException On the first operation that cannot be applied (message prefixed with its index).
	 */
	public function apply( array $doc, array $operations ): array {
		$refs  = [];
		$added = [];
		foreach ( array_values( $operations ) as $i => $op ) {
			try {
				$doc = $this->step( $doc, (array) $op, $refs, $added );
			} catch ( OpsException $e ) {
				throw new OpsException( $e->getMessage(), '/operations/' . $i . $e->field() );
			}
		}
		return [
			'doc'   => $doc,
			'refs'  => $refs,
			'added' => $added,
		];
	}

	/**
	 * Applies one operation.
	 *
	 * @param array<string, mixed>  $doc   Document.
	 * @param array<string, mixed>  $op    Operation.
	 * @param array<string, string> $refs  Refs created so far (new-1 => ULID), updated in place.
	 * @param string[]              $added Ids of added blocks, updated in place.
	 * @return array<string, mixed>
	 * @throws OpsException When the operation cannot be applied.
	 */
	public function step( array $doc, array $op, array &$refs, array &$added = [] ): array {
		$doc['blocks'] = array_values( (array) ( $doc['blocks'] ?? [] ) );
		switch ( (string) ( $op['op'] ?? '' ) ) {
			case 'add_block':
				return $this->add( $doc, $op, $refs, $added );
			case 'update_block':
				return $this->update( $doc, $op, $refs );
			case 'move_block':
				return $this->move( $doc, $op, $refs );
			case 'remove_block':
				return $this->remove( $doc, $op, $refs );
			case 'set_design':
				return $this->design( $doc, (array) ( $op['design'] ?? [] ) );
			case 'set_profile':
				$doc['profile'] = array_merge( (array) ( $doc['profile'] ?? [] ), (array) ( $op['profile'] ?? [] ) );
				return $doc;
		}
		throw new OpsException( __( 'Unknown operation.', 'crazylinks' ), '/op' );
	}

	/**
	 * Runs the page validator on the result, exactly like a human save.
	 *
	 * @param array<string, mixed>      $doc      Result document.
	 * @param int                       $user_id  Acting user.
	 * @param array<string, mixed>|null $previous Stored document.
	 */
	public function finalize( array $doc, int $user_id, ?array $previous ): ValidationResult {
		return ( new PageValidator() )->validate( $doc, $user_id, $previous );
	}

	/**
	 * Index of a block by id, or -1.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @param string               $id  Id.
	 */
	public static function index_of( array $doc, string $id ): int {
		foreach ( (array) $doc['blocks'] as $i => $block ) {
			if ( is_array( $block ) && ( $block['id'] ?? '' ) === $id ) {
				return (int) $i;
			}
		}
		return -1;
	}

	/**
	 * Resolves an id or ref to an existing block index.
	 *
	 * @param array<string, mixed>  $doc   Document.
	 * @param mixed                 $ref   Id or ref.
	 * @param array<string, string> $refs  Refs.
	 * @param string                $field Field for errors.
	 * @throws OpsException When missing.
	 */
	private function resolve( array $doc, $ref, array $refs, string $field ): int {
		$ref = is_string( $ref ) ? $ref : '';
		$id  = $refs[ $ref ] ?? $ref;
		$i   = '' === $id ? -1 : self::index_of( $doc, $id );
		if ( $i < 0 ) {
			/* translators: %s: block id. */
			throw new OpsException( sprintf( __( 'There is no block with id "%s" on this page.', 'crazylinks' ), $ref ), $field );
		}
		return $i;
	}

	/**
	 * Throws when the block is custom HTML (never AI-editable).
	 *
	 * @param array<string, mixed> $block Block.
	 * @param string               $field Field.
	 * @throws OpsException For html blocks.
	 */
	private function guard_html( array $block, string $field ): void {
		if ( 'html' === ( $block['type'] ?? '' ) ) {
			throw new OpsException( __( 'Custom HTML blocks cannot be changed, moved or removed by AI.', 'crazylinks' ), $field );
		}
	}

	/**
	 * Works out the parent and the sibling to insert after.
	 *
	 * @param array<string, mixed>  $doc        Document.
	 * @param mixed                 $after      after_id value.
	 * @param bool                  $has_parent Whether parent_id was given.
	 * @param mixed                 $parent_ref parent_id value.
	 * @param array<string, string> $refs       Refs.
	 * @param string|null           $fallback   Parent when neither is given.
	 * @return array{0: string|null, 1: string|null} [parent id, after id]
	 * @throws OpsException On inconsistent placement.
	 */
	private function placement( array $doc, $after, bool $has_parent, $parent_ref, array $refs, ?string $fallback ): array {
		$parent_id = $fallback;
		if ( $has_parent ) {
			$parent_id = null;
			if ( null !== $parent_ref ) {
				$p = $doc['blocks'][ $this->resolve( $doc, $parent_ref, $refs, '/parent_id' ) ];
				if ( 'group' !== $p['type'] ) {
					throw new OpsException( __( 'parent_id must be a group block.', 'crazylinks' ), '/parent_id' );
				}
				$parent_id = (string) $p['id'];
			}
		}
		if ( null === $after ) {
			return [ $parent_id, null ];
		}
		$a = $doc['blocks'][ $this->resolve( $doc, $after, $refs, '/after_id' ) ];
		if ( $has_parent && null !== $parent_id && $a['id'] === $parent_id ) {
			// "After the group, inside the group" reads as "first inside the group".
			return [ $parent_id, null ];
		}
		$after_parent = null === $a['parent_id'] ? null : (string) $a['parent_id'];
		if ( $has_parent && $after_parent !== $parent_id ) {
			throw new OpsException( __( 'after_id must be a block inside the same parent as parent_id.', 'crazylinks' ), '/after_id' );
		}
		return [ $after_parent, (string) $a['id'] ];
	}

	/**
	 * Puts block $id into $parent right after $after (or first), and renumbers both parents.
	 *
	 * @param array<string, mixed> $doc    Document.
	 * @param string               $id     Block id.
	 * @param string|null          $target Parent group id.
	 * @param string|null          $after  Sibling id.
	 * @return array<string, mixed>
	 */
	private function place( array $doc, string $id, ?string $target, ?string $after ): array {
		$i   = self::index_of( $doc, $id );
		$old = $doc['blocks'][ $i ]['parent_id'];

		$doc['blocks'][ $i ]['parent_id'] = $target;

		$siblings = array_values( array_filter( $this->ordered( $doc, $target ), static fn( $sid ) => $sid !== $id ) );
		$pos      = null === $after ? 0 : (int) array_search( $after, $siblings, true ) + 1;
		array_splice( $siblings, $pos, 0, [ $id ] );
		$doc = $this->renumber( $doc, $siblings );
		if ( $old !== $target ) {
			$doc = $this->renumber( $doc, $this->ordered( $doc, null === $old ? null : (string) $old ) );
		}
		return $doc;
	}

	/**
	 * Ids of a parent's children in display order (order, then position).
	 *
	 * @param array<string, mixed> $doc    Document.
	 * @param string|null          $group  Parent id.
	 * @return string[]
	 */
	private function ordered( array $doc, ?string $group ): array {
		$items = [];
		foreach ( $doc['blocks'] as $i => $block ) {
			if ( ( null === $block['parent_id'] ? null : (string) $block['parent_id'] ) === $group ) {
				$items[] = [ (int) $block['order'], (int) $i, (string) $block['id'] ];
			}
		}
		usort( $items, static fn( $a, $b ) => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
		return array_column( $items, 2 );
	}

	/**
	 * Sets `order` to the position in $ids.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @param string[]             $ids Ordered ids.
	 * @return array<string, mixed>
	 */
	private function renumber( array $doc, array $ids ): array {
		$pos = array_flip( $ids );
		foreach ( $doc['blocks'] as $i => $block ) {
			if ( isset( $pos[ $block['id'] ] ) ) {
				$doc['blocks'][ $i ]['order'] = $pos[ $block['id'] ];
			}
		}
		return $doc;
	}

	/**
	 * Normalizes stored blocks so placement can rely on every field.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @return array<string, mixed>
	 */
	private function fill( array $doc ): array {
		foreach ( $doc['blocks'] as $i => $block ) {
			$block               = (array) $block;
			$block['parent_id']  = isset( $block['parent_id'] ) && '' !== $block['parent_id'] ? (string) $block['parent_id'] : null;
			$block['order']      = (int) ( $block['order'] ?? $i );
			$block['id']         = (string) ( $block['id'] ?? '' );
			$block['type']       = (string) ( $block['type'] ?? '' );
			$doc['blocks'][ $i ] = $block;
		}
		return $doc;
	}

	/**
	 * Op: add_block.
	 *
	 * @param array<string, mixed>  $doc   Document.
	 * @param array<string, mixed>  $op    Operation.
	 * @param array<string, string> $refs  Refs.
	 * @param string[]              $added Added ids.
	 * @return array<string, mixed>
	 * @throws OpsException On bad input.
	 */
	private function add( array $doc, array $op, array &$refs, array &$added ): array {
		$doc   = $this->fill( $doc );
		$block = (array) ( $op['block'] ?? [] );
		$type  = (string) ( $block['type'] ?? '' );
		if ( 'html' === $type || ! in_array( $type, OpsSchema::block_types(), true ) ) {
			throw new OpsException( __( 'This block type cannot be added by AI.', 'crazylinks' ), '/block/type' );
		}
		if ( count( $doc['blocks'] ) >= self::MAX_BLOCKS ) {
			/* translators: %d: maximum number of blocks. */
			throw new OpsException( sprintf( __( 'A page can have at most %d blocks.', 'crazylinks' ), self::MAX_BLOCKS ) );
		}
		$ref = isset( $op['ref'] ) ? (string) $op['ref'] : '';
		if ( '' !== $ref && isset( $refs[ $ref ] ) ) {
			throw new OpsException( __( 'This ref is already used by an earlier operation.', 'crazylinks' ), '/ref' );
		}
		[ $parent, $after ] = $this->placement( $doc, $op['after_id'] ?? null, array_key_exists( 'parent_id', $block ), $block['parent_id'] ?? null, $refs, null );
		if ( null !== $parent && 'group' === $type ) {
			throw new OpsException( __( 'Groups cannot be nested.', 'crazylinks' ), '/block/parent_id' );
		}

		$id  = ( $this->ids )();
		$new = [
			'id'        => $id,
			'type'      => $type,
			'parent_id' => $parent,
			'order'     => PHP_INT_MAX,
			'visible'   => isset( $block['visible'] ) ? (bool) $block['visible'] : true,
			'schedule'  => array_merge(
				[
					'start' => null,
					'end'   => null,
				],
				(array) ( $block['schedule'] ?? [] )
			),
			'pinned'    => ! empty( $block['pinned'] ),
			'animation' => isset( $block['animation'] ) ? (string) $block['animation'] : 'none',
			'tracking'  => isset( $block['tracking'] ) ? (bool) $block['tracking'] : true,
			'props'     => (array) ( $block['props'] ?? [] ),
		];

		$doc['blocks'][] = $new;
		if ( '' !== $ref ) {
			$refs[ $ref ] = $id;
		}
		$added[] = $id;
		return $this->place( $doc, $id, $parent, $after );
	}

	/**
	 * Op: update_block.
	 *
	 * @param array<string, mixed>  $doc  Document.
	 * @param array<string, mixed>  $op   Operation.
	 * @param array<string, string> $refs Refs.
	 * @return array<string, mixed>
	 * @throws OpsException On bad input.
	 */
	private function update( array $doc, array $op, array $refs ): array {
		$doc   = $this->fill( $doc );
		$i     = $this->resolve( $doc, $op['id'] ?? null, $refs, '/id' );
		$block = $doc['blocks'][ $i ];
		$this->guard_html( $block, '/id' );
		if ( isset( $op['props'] ) ) {
			$block['props'] = array_merge( (array) ( $block['props'] ?? [] ), (array) $op['props'] );
		}
		foreach ( [ 'visible', 'pinned', 'tracking' ] as $flag ) {
			if ( isset( $op[ $flag ] ) ) {
				$block[ $flag ] = (bool) $op[ $flag ];
			}
		}
		if ( isset( $op['animation'] ) ) {
			$block['animation'] = (string) $op['animation'];
		}
		if ( isset( $op['schedule'] ) ) {
			$block['schedule'] = array_merge(
				[
					'start' => null,
					'end'   => null,
				],
				(array) ( $block['schedule'] ?? [] ),
				(array) $op['schedule']
			);
		}
		$doc['blocks'][ $i ] = $block;
		return $doc;
	}

	/**
	 * Op: move_block.
	 *
	 * @param array<string, mixed>  $doc  Document.
	 * @param array<string, mixed>  $op   Operation.
	 * @param array<string, string> $refs Refs.
	 * @return array<string, mixed>
	 * @throws OpsException On bad input.
	 */
	private function move( array $doc, array $op, array $refs ): array {
		$doc   = $this->fill( $doc );
		$block = $doc['blocks'][ $this->resolve( $doc, $op['id'] ?? null, $refs, '/id' ) ];
		$this->guard_html( $block, '/id' );
		$after = $op['after_id'] ?? null;
		if ( null !== $after && ( $refs[ (string) $after ] ?? $after ) === $block['id'] ) {
			throw new OpsException( __( 'A block cannot be moved after itself.', 'crazylinks' ), '/after_id' );
		}
		$has_parent           = array_key_exists( 'parent_id', $op );
		[ $parent, $sibling ] = $this->placement( $doc, $after, $has_parent, $op['parent_id'] ?? null, $refs, $block['parent_id'] );
		if ( null !== $parent && 'group' === $block['type'] ) {
			throw new OpsException( __( 'Groups cannot be nested.', 'crazylinks' ), '/parent_id' );
		}
		if ( $parent === $block['id'] ) {
			throw new OpsException( __( 'A group cannot contain itself.', 'crazylinks' ), '/parent_id' );
		}
		return $this->place( $doc, (string) $block['id'], $parent, $sibling );
	}

	/**
	 * Op: remove_block (a group goes with its children, like the editor).
	 *
	 * @param array<string, mixed>  $doc  Document.
	 * @param array<string, mixed>  $op   Operation.
	 * @param array<string, string> $refs Refs.
	 * @return array<string, mixed>
	 * @throws OpsException On bad input.
	 */
	private function remove( array $doc, array $op, array $refs ): array {
		$doc   = $this->fill( $doc );
		$block = $doc['blocks'][ $this->resolve( $doc, $op['id'] ?? null, $refs, '/id' ) ];
		$this->guard_html( $block, '/id' );
		$id = (string) $block['id'];
		foreach ( $doc['blocks'] as $child ) {
			if ( $child['parent_id'] === $id && 'html' === $child['type'] ) {
				throw new OpsException( __( 'This group contains a custom HTML block, which AI cannot remove.', 'crazylinks' ), '/id' );
			}
		}
		$doc['blocks'] = array_values( array_filter( $doc['blocks'], static fn( $b ) => $b['id'] !== $id && $b['parent_id'] !== $id ) );
		return $this->renumber( $doc, $this->ordered( $doc, null === $block['parent_id'] ? null : (string) $block['parent_id'] ) );
	}

	/**
	 * Op: set_design. Nested objects merge; a new theme first clears what the theme owns (like the editor).
	 *
	 * @param array<string, mixed> $doc   Document.
	 * @param array<string, mixed> $patch Design patch.
	 * @return array<string, mixed>
	 */
	private function design( array $doc, array $patch ): array {
		$design = (array) ( $doc['design'] ?? [] );
		unset( $patch['custom_css'] );
		if ( isset( $patch['theme'] ) && ( $design['theme'] ?? '' ) !== $patch['theme'] ) {
			foreach ( [ 'background', 'text', 'button', 'accent', 'radius' ] as $key ) {
				unset( $design[ $key ] );
			}
		}
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) && isset( $design[ $key ] ) && is_array( $design[ $key ] ) ) {
				$design[ $key ] = array_merge( $design[ $key ], $value );
			} else {
				$design[ $key ] = $value;
			}
		}
		$doc['design'] = $design;
		return $doc;
	}
}
