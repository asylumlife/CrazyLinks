<?php
/**
 * Section (block type `group`): holds other blocks, with an optional heading and description, its
 * own background, padding, corner radius, text colour and list/grid layout. A collapsible section
 * uses <details>, so it works without JavaScript. Documents from before sections (only `title` and
 * `collapsed`) render as before: a titled group is collapsible.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>              $props
 * @var string                            $inner Rendered children (already escaped).
 * @var \CrazyLinks\Render\RenderContext $ctx
 */

defined( 'ABSPATH' ) || exit;

if ( '' === trim( $inner ) ) {
	return;
}
$crazylinks_title       = (string) ( $props['title'] ?? '' );
$crazylinks_heading     = (string) ( $props['heading'] ?? '' );
$crazylinks_text        = '' !== $crazylinks_heading ? $crazylinks_heading : $crazylinks_title;
$crazylinks_desc        = (string) ( $props['description'] ?? '' );
$crazylinks_collapsible = array_key_exists( 'collapsible', $props ) ? ! empty( $props['collapsible'] ) : '' !== $crazylinks_title;
$crazylinks_collapsible = $crazylinks_collapsible && '' !== $crazylinks_text;
$crazylinks_has_bg      = \CrazyLinks\Render\Contrast::section_has_background( $props );

$crazylinks_padding = in_array( $props['padding'] ?? '', [ 'none', 'sm', 'md', 'lg' ], true ) ? (string) $props['padding'] : ( $crazylinks_has_bg ? 'md' : 'none' );
$crazylinks_radius  = in_array( $props['radius'] ?? '', [ 'none', 'sm', 'md', 'lg' ], true ) ? (string) $props['radius'] : 'inherit';
$crazylinks_classes = [ 'cl-group', 'cl-section', 'cl-section--pad-' . $crazylinks_padding, 'cl-section--radius-' . $crazylinks_radius ];
if ( $crazylinks_has_bg ) {
	$crazylinks_classes[] = 'cl-section--bg';
}
if ( 'grid' === ( $props['layout'] ?? '' ) ) {
	$crazylinks_classes[] = 'cl-section--grid';
}

$crazylinks_style = '';
if ( $crazylinks_has_bg ) {
	$crazylinks_style .= 'background:' . \CrazyLinks\Render\Tokens::background_css( (array) $props['background'] ) . ';';
}
$crazylinks_ink = \CrazyLinks\Render\Contrast::section_text( $props, $ctx->page_text );
if ( '' !== $crazylinks_ink ) {
	// Children inherit the section's text colour (and use it for muted text) so they stay readable on its background.
	$crazylinks_style .= '--cl-text:' . $crazylinks_ink . ';--cl-muted:' . $crazylinks_ink . ';';
}
$crazylinks_attrs = ' class="' . esc_attr( implode( ' ', $crazylinks_classes ) ) . '"' . ( '' !== $crazylinks_style ? ' style="' . esc_attr( $crazylinks_style ) . '"' : '' );
$crazylinks_body  = '<div class="cl-group__body cl-section__body">' . $inner . '</div>';
$crazylinks_desc  = '' !== $crazylinks_desc ? '<p class="cl-section__desc">' . esc_html( $crazylinks_desc ) . '</p>' : '';
?>
<?php if ( $crazylinks_collapsible ) : ?>
	<details<?php echo $crazylinks_attrs . ( empty( $props['collapsed'] ) ? ' open' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>>
		<summary class="cl-group__title cl-section__title"><?php echo esc_html( $crazylinks_text ); ?></summary>
		<?php echo $crazylinks_desc . $crazylinks_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped description and children. ?>
	</details>
<?php else : ?>
	<section<?php echo $crazylinks_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>>
		<?php if ( '' !== $crazylinks_text ) : ?>
			<h2 class="cl-section__title"><?php echo esc_html( $crazylinks_text ); ?></h2>
		<?php endif; ?>
		<?php echo $crazylinks_desc . $crazylinks_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped description and children. ?>
	</section>
<?php endif; ?>
