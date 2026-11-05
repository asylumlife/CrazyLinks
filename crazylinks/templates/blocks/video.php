<?php
/**
 * Video: an uploaded file in a native player, or a URL through the same click-to-play facade as the embed block.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $block
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 * @var \CrazyLinks\Render\Renderer      $renderer
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_id = (int) ( $props['video'] ?? 0 );
if ( $crazylinks_id <= 0 ) {
	if ( '' !== (string) ( $props['url'] ?? '' ) ) {
		$crazylinks_facade = $renderer->partial(
			'blocks/embed',
			[
				'block' => $block,
				'props' => $props,
				'ctx'   => $ctx,
				'inner' => '',
			]
		);
		echo $crazylinks_facade; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The embed partial escapes its own output.
	}
	return;
}

$crazylinks_src  = (string) wp_get_attachment_url( $crazylinks_id );
$crazylinks_mime = (string) get_post_mime_type( $crazylinks_id );
if ( '' === $crazylinks_src || ! str_starts_with( $crazylinks_mime, 'video/' ) ) {
	return;
}
$crazylinks_poster = ! empty( $props['poster'] ) ? (string) wp_get_attachment_image_url( (int) $props['poster'], 'cl-featured' ) : '';
$crazylinks_auto   = ! empty( $props['autoplay'] );
$crazylinks_title  = (string) ( $props['title'] ?? '' );
?>
<video class="cl-video" playsinline preload="<?php echo $crazylinks_auto ? 'auto' : 'none'; ?>"
	<?php
	if ( $crazylinks_auto ) {
		echo ' autoplay muted';
	} else {
		echo ' controls';
		echo ! empty( $props['muted'] ) ? ' muted' : '';
	}
	echo ! empty( $props['loop'] ) ? ' loop' : '';
	if ( '' !== $crazylinks_poster ) {
		echo ' poster="' . esc_url( $crazylinks_poster ) . '"';
	}
	?>
	aria-label="<?php echo esc_attr( '' !== $crazylinks_title ? $crazylinks_title : __( 'Video', 'crazylinks' ) ); ?>">
	<source src="<?php echo esc_url( $crazylinks_src ); ?>" type="<?php echo esc_attr( $crazylinks_mime ); ?>">
	<a href="<?php echo esc_url( $crazylinks_src ); ?>"><?php esc_html_e( 'Download the video', 'crazylinks' ); ?></a>
</video>
