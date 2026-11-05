<?php
/**
 * Plain-English change list for a set of operations.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Describes operations for people: "Added link “Tour dates” at the top", "Changed “Merch”: title, URL".
 * Built from the operations and the base document, so it never repeats model-written prose.
 */
final class ChangeList {

	/**
	 * Change items.
	 *
	 * @param array<int, array<string, mixed>> $ops   Operations.
	 * @param array<string, mixed>             $base  Document the operations apply to.
	 * @param string[]                         $added Ids given to added blocks (in order).
	 * @return array<int, array{kind:string, text:string, block_id:string|null}>
	 */
	public static function build( array $ops, array $base, array $added = [] ): array {
		$names = [];
		foreach ( (array) ( $base['blocks'] ?? [] ) as $block ) {
			if ( is_array( $block ) ) {
				$names[ (string) ( $block['id'] ?? '' ) ] = self::label( $block );
			}
		}
		$refs  = [];
		$items = [];
		$n     = 0;
		foreach ( $ops as $op ) {
			$kind = (string) ( $op['op'] ?? '' );
			switch ( $kind ) {
				case 'add_block':
					$block = (array) ( $op['block'] ?? [] );
					$id    = $added[ $n ] ?? null;
					++$n;
					$label = self::label( $block );
					if ( null !== $id ) {
						$names[ $id ] = $label;
					}
					if ( isset( $op['ref'] ) ) {
						$refs[ (string) $op['ref'] ]  = (string) $id;
						$names[ (string) $op['ref'] ] = $label;
					}
					$where = null === ( $op['after_id'] ?? null )
						? __( 'at the top', 'crazylinks' )
						/* translators: %s: block name. */
						: sprintf( __( 'after %s', 'crazylinks' ), $names[ (string) $op['after_id'] ] ?? __( 'another block', 'crazylinks' ) );
					/* translators: 1: block name, 2: position such as "at the top". */
					$items[] = self::item( 'add', sprintf( __( 'Add %1$s %2$s', 'crazylinks' ), $label, $where ), $id );
					break;
				case 'update_block':
					$ref   = (string) ( $op['id'] ?? '' );
					$label = $names[ $ref ] ?? __( 'a block', 'crazylinks' );
					$what  = [];
					foreach ( array_keys( (array) ( $op['props'] ?? [] ) ) as $key ) {
						$what[] = self::field_name( (string) $key );
					}
					foreach ( [ 'visible', 'schedule', 'animation', 'tracking' ] as $key ) {
						if ( array_key_exists( $key, $op ) ) {
							$what[] = self::field_name( $key );
						}
					}
					if ( array_key_exists( 'pinned', $op ) && [] === $what ) {
						/* translators: %s: block name. */
						$text = ! empty( $op['pinned'] ) ? sprintf( __( 'Pin %s to the top', 'crazylinks' ), $label ) : sprintf( __( 'Unpin %s', 'crazylinks' ), $label );
					} else {
						if ( array_key_exists( 'pinned', $op ) ) {
							$what[] = ! empty( $op['pinned'] ) ? __( 'pinned', 'crazylinks' ) : __( 'unpinned', 'crazylinks' );
						}
						/* translators: 1: block name, 2: comma-separated list of fields. */
						$text = sprintf( __( 'Change %1$s: %2$s', 'crazylinks' ), $label, implode( ', ', array_unique( $what ) ) );
					}
					$items[] = self::item( 'update', $text, $refs[ $ref ] ?? $ref );
					break;
				case 'move_block':
					$ref   = (string) ( $op['id'] ?? '' );
					$label = $names[ $ref ] ?? __( 'a block', 'crazylinks' );
					$after = $op['after_id'] ?? null;
					$text  = null === $after
						/* translators: %s: block name. */
						? sprintf( __( 'Move %s to the top', 'crazylinks' ), $label )
						/* translators: 1: block name, 2: other block name. */
						: sprintf( __( 'Move %1$s after %2$s', 'crazylinks' ), $label, $names[ (string) $after ] ?? __( 'another block', 'crazylinks' ) );
					if ( array_key_exists( 'parent_id', $op ) ) {
						$text .= null === $op['parent_id']
							? ' ' . __( '(out of its section)', 'crazylinks' )
							/* translators: %s: section name. */
							: ' ' . sprintf( __( '(into %s)', 'crazylinks' ), $names[ (string) $op['parent_id'] ] ?? __( 'a section', 'crazylinks' ) );
					}
					$items[] = self::item( 'move', $text, $refs[ $ref ] ?? $ref );
					break;
				case 'remove_block':
					$ref = (string) ( $op['id'] ?? '' );
					/* translators: %s: block name. */
					$items[] = self::item( 'remove', sprintf( __( 'Remove %s', 'crazylinks' ), $names[ $ref ] ?? __( 'a block', 'crazylinks' ) ), $refs[ $ref ] ?? $ref );
					break;
				case 'set_design':
					$items[] = self::item( 'design', self::design_text( (array) ( $op['design'] ?? [] ) ), null );
					break;
				case 'set_profile':
					$fields = array_map( [ self::class, 'field_name' ], array_map( 'strval', array_keys( (array) ( $op['profile'] ?? [] ) ) ) );
					/* translators: %s: comma-separated list of profile fields. */
					$items[] = self::item( 'profile', sprintf( __( 'Update the profile: %s', 'crazylinks' ), implode( ', ', $fields ) ), null );
					break;
			}
		}
		return $items;
	}

	/**
	 * "link “Tour dates”".
	 *
	 * @param array<string, mixed> $block Block.
	 */
	public static function label( array $block ): string {
		$props = (array) ( $block['props'] ?? [] );
		$type  = (string) ( $block['type'] ?? '' );
		$type  = 'group' === $type ? 'section' : str_replace( '_', ' ', $type );
		foreach ( [ 'title', 'text', 'label', 'heading', 'alt', 'address' ] as $key ) {
			if ( ! empty( $props[ $key ] ) && is_string( $props[ $key ] ) ) {
				$name = wp_strip_all_tags( $props[ $key ] );
				$name = mb_strlen( $name ) > 40 ? mb_substr( $name, 0, 39 ) . '…' : $name;
				/* translators: 1: block type, 2: block title. */
				return sprintf( __( '%1$s “%2$s”', 'crazylinks' ), $type, $name );
			}
		}
		if ( 'payment' === ( $block['type'] ?? '' ) && ! empty( $props['service'] ) ) {
			/* translators: %s: payment service slug. */
			return sprintf( __( 'payment button (%s)', 'crazylinks' ), (string) $props['service'] );
		}
		/* translators: %s: block type. */
		return sprintf( __( '%s block', 'crazylinks' ), $type );
	}

	/**
	 * Design patch description.
	 *
	 * @param array<string, mixed> $design Patch.
	 */
	private static function design_text( array $design ): string {
		$parts = [];
		foreach ( $design as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $sub => $v ) {
					$parts[] = self::field_name( $key . '.' . $sub ) . ( is_scalar( $v ) ? ' → ' . self::scalar( $v ) : '' );
				}
			} else {
				$parts[] = self::field_name( (string) $key ) . ' → ' . self::scalar( $value );
			}
		}
		/* translators: %s: comma-separated list of design changes. */
		return sprintf( __( 'Restyle: %s', 'crazylinks' ), implode( ', ', $parts ) );
	}

	/**
	 * Printable scalar.
	 *
	 * @param mixed $v Value.
	 */
	private static function scalar( $v ): string {
		if ( is_bool( $v ) ) {
			return $v ? __( 'on', 'crazylinks' ) : __( 'off', 'crazylinks' );
		}
		return is_scalar( $v ) ? (string) $v : '';
	}

	/**
	 * Friendly field names (unknown keys fall back to the key with spaces).
	 *
	 * @param string $key Key or dotted key.
	 */
	public static function field_name( string $key ): string {
		$map = [
			'title'                    => __( 'title', 'crazylinks' ),
			'url'                      => __( 'URL', 'crazylinks' ),
			'subtitle'                 => __( 'subtitle', 'crazylinks' ),
			'display'                  => __( 'display style', 'crazylinks' ),
			'thumbnail'                => __( 'thumbnail', 'crazylinks' ),
			'badge'                    => __( 'badge', 'crazylinks' ),
			'visible'                  => __( 'visibility', 'crazylinks' ),
			'schedule'                 => __( 'schedule', 'crazylinks' ),
			'animation'                => __( 'animation', 'crazylinks' ),
			'tracking'                 => __( 'click tracking', 'crazylinks' ),
			'items'                    => __( 'items', 'crazylinks' ),
			'text'                     => __( 'text', 'crazylinks' ),
			'content'                  => __( 'text', 'crazylinks' ),
			'name'                     => __( 'name', 'crazylinks' ),
			'bio'                      => __( 'bio', 'crazylinks' ),
			'avatar'                   => __( 'avatar', 'crazylinks' ),
			'theme'                    => __( 'theme', 'crazylinks' ),
			'accent'                   => __( 'accent color', 'crazylinks' ),
			'radius'                   => __( 'corner radius', 'crazylinks' ),
			'layout'                   => __( 'layout', 'crazylinks' ),
			'dark_mode'                => __( 'dark mode', 'crazylinks' ),
			'button.shape'             => __( 'button shape', 'crazylinks' ),
			'button.fill'              => __( 'button fill', 'crazylinks' ),
			'button.color'             => __( 'button color', 'crazylinks' ),
			'button.text_color'        => __( 'button text color', 'crazylinks' ),
			'button.shadow'            => __( 'button shadow', 'crazylinks' ),
			'button.hover'             => __( 'button hover effect', 'crazylinks' ),
			'button.border_width'      => __( 'button border', 'crazylinks' ),
			'background.type'          => __( 'background type', 'crazylinks' ),
			'background.color'         => __( 'background color', 'crazylinks' ),
			'background.from'          => __( 'gradient start', 'crazylinks' ),
			'background.to'            => __( 'gradient end', 'crazylinks' ),
			'background.angle'         => __( 'gradient angle', 'crazylinks' ),
			'background.pattern'       => __( 'background pattern', 'crazylinks' ),
			'background.image'         => __( 'background image', 'crazylinks' ),
			'text.color'               => __( 'text color', 'crazylinks' ),
			'text.muted'               => __( 'secondary text color', 'crazylinks' ),
			'text.font'                => __( 'font', 'crazylinks' ),
			'text.size'                => __( 'text size', 'crazylinks' ),
			'header_layout'            => __( 'header layout', 'crazylinks' ),
			'avatar_style'             => __( 'avatar style', 'crazylinks' ),
			'location'                 => __( 'location', 'crazylinks' ),
			'pronouns'                 => __( 'pronouns', 'crazylinks' ),
			'verified_badge'           => __( 'verified badge', 'crazylinks' ),
			'background.radial'        => __( 'radial gradient', 'crazylinks' ),
			'background.overlay'       => __( 'background overlay', 'crazylinks' ),
			'background.blur'          => __( 'background blur', 'crazylinks' ),
			'background.pattern_color' => __( 'pattern color', 'crazylinks' ),
			'background.pattern_size'  => __( 'pattern size', 'crazylinks' ),
		];
		return $map[ $key ] ?? str_replace( [ '_', '.' ], ' ', $key );
	}

	/**
	 * Item.
	 *
	 * @param string      $kind     add | update | move | remove | design | profile.
	 * @param string      $text     Sentence.
	 * @param string|null $block_id Affected block id.
	 * @return array{kind:string, text:string, block_id:string|null}
	 */
	private static function item( string $kind, string $text, ?string $block_id ): array {
		return [
			'kind'     => $kind,
			'text'     => $text,
			'block_id' => $block_id,
		];
	}
}
