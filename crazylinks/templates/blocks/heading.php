<?php
/**
 * Heading, with optional size, weight, colour, uppercase, emoji or icon, subtext, divider and
 * spacing. Every modifier is a `.cl-heading--*` class; only the colour override is inline.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed> $props
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_pick    = static fn( string $key, array $allowed, string $fallback ): string => in_array( $props[ $key ] ?? '', $allowed, true ) ? (string) $props[ $key ] : $fallback;
$crazylinks_level   = 'h3' === ( $props['level'] ?? 'h2' ) ? 'h3' : 'h2';
$crazylinks_align   = $crazylinks_pick( 'align', [ 'left', 'center', 'right' ], 'center' );
$crazylinks_classes = [ 'cl-heading', 'cl-align-' . $crazylinks_align ];
foreach ( [
	'size'    => [ [ 'sm', 'lg', 'xl' ], '' ],
	'weight'  => [ [ 'normal', 'black' ], '' ],
	'spacing' => [ [ 'tight', 'loose' ], '' ],
	'divider' => [ [ 'line', 'dots' ], '' ],
] as $crazylinks_key => [ $crazylinks_allowed, $crazylinks_default ] ) {
	$crazylinks_value = $crazylinks_pick( $crazylinks_key, $crazylinks_allowed, $crazylinks_default );
	if ( '' !== $crazylinks_value ) {
		$crazylinks_classes[] = 'cl-heading--' . ( 'divider' === $crazylinks_key ? 'div-' : '' ) . $crazylinks_value;
	}
}
if ( ! empty( $props['uppercase'] ) ) {
	$crazylinks_classes[] = 'cl-heading--upper';
}
$crazylinks_color = (string) ( $props['color'] ?? '' );
$crazylinks_style = preg_match( '/^#[0-9a-fA-F]{3,8}$/', $crazylinks_color ) ? ' style="color:' . esc_attr( $crazylinks_color ) . '"' : '';

$crazylinks_media = '';
if ( ! empty( $props['emoji'] ) ) {
	$crazylinks_media = '<span class="cl-heading__emoji" aria-hidden="true">' . esc_html( (string) $props['emoji'] ) . '</span>';
} elseif ( ! empty( $props['icon'] ) && \CrazyLinks\Render\Icons::has( (string) $props['icon'] ) ) {
	$crazylinks_media = \CrazyLinks\Render\Icons::svg( (string) $props['icon'], 'cl-icon cl-heading__icon' );
}
$crazylinks_text = esc_html( (string) ( $props['text'] ?? '' ) );
if ( '' !== $crazylinks_media ) {
	$crazylinks_text = $crazylinks_media . '<span class="cl-heading__text">' . $crazylinks_text . '</span>';
}

printf(
	'<%1$s class="%2$s"%3$s>%4$s</%1$s>',
	esc_html( $crazylinks_level ),
	esc_attr( implode( ' ', $crazylinks_classes ) ),
	$crazylinks_style, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
	$crazylinks_text // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped text and static SVG.
);
if ( '' !== trim( (string) ( $props['subtext'] ?? '' ) ) ) {
	printf( '<p class="cl-heading__sub cl-align-%s">%s</p>', esc_attr( $crazylinks_align ), esc_html( (string) $props['subtext'] ) );
}
