<?php
/**
 * Image with optional link and caption.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $block
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 * @var \CrazyLinks\Render\Renderer      $renderer
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_img = $renderer->image(
	(int) ( $props['image'] ?? 0 ),
	'cl-featured',
	$ctx,
	[
		'class' => 'cl-image',
		'alt'   => $renderer->alt( $props['alt'] ?? '', (int) ( $props['image'] ?? 0 ), ! empty( $props['decorative'] ) ),
		'sizes' => '(max-width: 480px) 100vw, 480px',
	]
);
if ( '' === $crazylinks_img ) {
	return;
}
$crazylinks_href = $ctx->href( $block, (string) ( $props['url'] ?? '' ) );
?>
<figure class="cl-figure">
	<?php if ( '' !== $crazylinks_href ) : ?>
		<a href="<?php echo esc_url( $crazylinks_href, \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES ); ?>"><?php echo $crazylinks_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?></a>
	<?php else : ?>
		<?php echo $crazylinks_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?>
	<?php endif; ?>
	<?php if ( ! empty( $props['caption'] ) ) : ?>
		<figcaption><?php echo esc_html( (string) $props['caption'] ); ?></figcaption>
	<?php endif; ?>
</figure>
