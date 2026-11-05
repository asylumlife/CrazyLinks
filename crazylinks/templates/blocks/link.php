<?php
/**
 * Link button. Displays: classic, thumbnail (left or right), featured (16:9 card). Optional subtitle, badge, NEW flag, pinned marker.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $block
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 * @var \CrazyLinks\Render\Renderer      $renderer
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_href = $ctx->href( $block, (string) ( $props['url'] ?? '' ) );
if ( '' === $crazylinks_href ) {
	return;
}
$crazylinks_display  = in_array( $props['display'] ?? 'classic', [ 'classic', 'featured', 'thumbnail' ], true ) ? (string) ( $props['display'] ?? 'classic' ) : 'classic';
$crazylinks_featured = 'featured' === $crazylinks_display;
$crazylinks_thumb_id = (int) ( $props['thumbnail'] ?? 0 );
$crazylinks_tile     = 'grid' === $ctx->layout && ! $crazylinks_featured;
$crazylinks_media    = '';
if ( $crazylinks_thumb_id > 0 ) {
	$crazylinks_media = $renderer->image(
		$crazylinks_thumb_id,
		$crazylinks_featured ? 'cl-featured' : ( $crazylinks_tile ? 'cl-tile' : 'cl-thumb' ),
		$ctx,
		array_filter(
			[
				'class' => $crazylinks_featured ? 'cl-link__cover' : 'cl-link__thumb',
				'alt'   => $renderer->alt( $props['thumbnail_alt'] ?? '', $crazylinks_thumb_id, ! empty( $props['decorative'] ) ),
				'style' => \CrazyLinks\Render\Renderer::focal_style( $props['focal_point'] ?? null ),
				'sizes' => $crazylinks_featured ? '(max-width: 480px) 100vw, 480px' : ( $crazylinks_tile ? '(max-width: 480px) 45vw, 220px' : '48px' ),
			],
			static fn( $v, $k ) => 'alt' === $k || '' !== $v,
			ARRAY_FILTER_USE_BOTH
		)
	);
}
if ( '' === $crazylinks_media && ! empty( $props['emoji'] ) ) {
	$crazylinks_media = '<span class="cl-link__emoji" aria-hidden="true">' . esc_html( (string) $props['emoji'] ) . '</span>';
}
if ( '' === $crazylinks_media && ! empty( $props['icon'] ) && \CrazyLinks\Render\Icons::has( (string) $props['icon'] ) ) {
	$crazylinks_media = '<span class="cl-link__icon">' . \CrazyLinks\Render\Icons::svg( (string) $props['icon'] ) . '</span>';
}

$crazylinks_classes = [ 'cl-link', $crazylinks_featured ? 'cl-link--featured' : 'cl-link--classic' ];
if ( '' !== $crazylinks_media && ! $crazylinks_featured ) {
	$crazylinks_classes[] = 'cl-link--thumb';
	if ( 'right' === ( $props['thumb_side'] ?? 'left' ) ) {
		$crazylinks_classes[] = 'cl-link--thumb-right';
	}
}
if ( $crazylinks_featured && '' === $crazylinks_media ) {
	$crazylinks_classes[] = 'cl-link--bare';
}

// Badge: an explicit badge wins; otherwise a NEW flag until `new_until`. The flag carries data-cl-end so cached pages drop it on time.
$crazylinks_badge_text = (string) ( $props['badge']['text'] ?? '' );
$crazylinks_badge_end  = '';
$crazylinks_new_until  = isset( $props['new_until'] ) ? strtotime( (string) $props['new_until'] ) : false;
if ( false !== $crazylinks_new_until ) {
	$ctx->note_boundary( $crazylinks_new_until );
	if ( '' === $crazylinks_badge_text && $crazylinks_new_until > $ctx->now ) {
		$crazylinks_badge_text = __( 'New', 'crazylinks' );
		$crazylinks_badge_end  = gmdate( 'c', $crazylinks_new_until );
	}
}
$crazylinks_style = '';
foreach ( [
	'color'      => '--cl-btn',
	'text_color' => '--cl-btn-text',
] as $crazylinks_key => $crazylinks_var ) {
	$crazylinks_val = (string) ( $props['style'][ $crazylinks_key ] ?? '' );
	if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $crazylinks_val ) ) {
		$crazylinks_style .= $crazylinks_var . ':' . $crazylinks_val . ';';
	}
}
if ( ! empty( $props['style']['fill'] ) ) {
	$crazylinks_classes[] = 'cl-fill-' . sanitize_html_class( (string) $props['style']['fill'] );
}

$crazylinks_rel = array_intersect( (array) ( $props['rel'] ?? [] ), [ 'nofollow', 'sponsored' ] );
$crazylinks_new = ! empty( $props['open_new_tab'] );
if ( $crazylinks_new ) {
	$crazylinks_rel[] = 'noopener';
}
?>
<a class="<?php echo esc_attr( implode( ' ', $crazylinks_classes ) ); ?>" href="<?php echo esc_url( $crazylinks_href, \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES ); ?>"
	<?php
	if ( $crazylinks_rel ) {
		echo ' rel="' . esc_attr( implode( ' ', $crazylinks_rel ) ) . '"';
	}
	if ( $crazylinks_new ) {
		echo ' target="_blank"';
	}
	if ( '' !== $crazylinks_style ) {
		echo ' style="' . esc_attr( $crazylinks_style ) . '"';
	}
	?>
>
	<?php echo $crazylinks_media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
	<span class="cl-link__body">
		<span class="cl-link__title"><?php echo esc_html( (string) $props['title'] ); ?></span>
		<?php if ( ! empty( $props['subtitle'] ) ) : ?>
			<span class="cl-link__subtitle"><?php echo esc_html( (string) $props['subtitle'] ); ?></span>
		<?php endif; ?>
	</span>
	<?php if ( '' !== $crazylinks_badge_text ) : ?>
		<span class="cl-badge"
		<?php
		$crazylinks_badge_color = (string) ( $props['badge']['color'] ?? '' );
		if ( '' !== $crazylinks_badge_end ) {
			echo ' data-cl-end="' . esc_attr( $crazylinks_badge_end ) . '"';
		}
		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $crazylinks_badge_color ) ) {
			echo ' style="' . esc_attr( '--cl-badge:' . $crazylinks_badge_color ) . '"';
		}
		?>
		><?php echo esc_html( $crazylinks_badge_text ); ?></span>
	<?php endif; ?>
	<?php if ( ! empty( $block['pinned'] ) ) : ?>
		<span class="cl-pin"><?php echo \CrazyLinks\Render\Icons::svg( 'pinned', 'cl-icon cl-icon--pin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?><span class="cl-sr"><?php esc_html_e( '(pinned)', 'crazylinks' ); ?></span></span>
	<?php endif; ?>
	<?php if ( $crazylinks_new ) : ?>
		<span class="cl-sr"><?php esc_html_e( '(opens in a new tab)', 'crazylinks' ); ?></span>
	<?php endif; ?>
</a>
