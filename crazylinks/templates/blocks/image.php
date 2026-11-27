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

$crazylinks_alt = $renderer->alt( $props['alt'] ?? '', (int) ( $props['image'] ?? 0 ), ! empty( $props['decorative'] ) );
$crazylinks_img = $renderer->image(
	(int) ( $props['image'] ?? 0 ),
	'cl-featured',
	$ctx,
	[
		'class' => 'cl-image',
		'alt'   => $crazylinks_alt,
		'sizes' => \CrazyLinks\Render\ImageSizes::FULL_WIDTH_SIZES,
	]
);
if ( '' === $crazylinks_img ) {
	return;
}
$crazylinks_href = $ctx->href( $block, (string) ( $props['url'] ?? '' ) );
// A linked image without alt text needs another name for the link: the caption, else a generic one.
$crazylinks_link_name = '';
if ( '' !== $crazylinks_href && '' === $crazylinks_alt ) {
	$crazylinks_link_name = '' !== (string) ( $props['caption'] ?? '' ) ? (string) $props['caption'] : __( 'View image link', 'crazylinks' );
	$crazylinks_link_name = '<span class="cl-sr">' . esc_html( $crazylinks_link_name ) . '</span>';
}
?>
<figure class="cl-figure">
	<?php if ( '' !== $crazylinks_href ) : ?>
		<a class="cl-figure__link" href="<?php echo esc_url( $crazylinks_href, \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES ); ?>"><?php echo $crazylinks_img . $crazylinks_link_name; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output and escaped text. ?></a>
	<?php else : ?>
		<?php echo $crazylinks_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?>
	<?php endif; ?>
	<?php if ( ! empty( $props['caption'] ) ) : ?>
		<figcaption class="cl-figure__caption"><?php echo esc_html( (string) $props['caption'] ); ?></figcaption>
	<?php endif; ?>
</figure>
