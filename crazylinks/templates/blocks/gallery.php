<?php
/**
 * Image gallery: a responsive grid of square tiles, each optionally linked and captioned.
 * Carousel mode is not implemented yet; it renders as the grid.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 * @var \CrazyLinks\Render\Renderer      $renderer
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_cols  = 3 === (int) ( $props['columns'] ?? 2 ) ? 3 : 2;
$crazylinks_tiles = '';
foreach ( (array) ( $props['items'] ?? [] ) as $crazylinks_item ) {
	if ( ! is_array( $crazylinks_item ) ) {
		continue;
	}
	$crazylinks_attr = [
		'class' => 'cl-gallery__img',
		'sizes' => 3 === $crazylinks_cols ? '(max-width: 480px) 30vw, 150px' : '(max-width: 480px) 45vw, 230px',
	];
	$crazylinks_alt  = $renderer->alt( $crazylinks_item['alt'] ?? '', (int) ( $crazylinks_item['image'] ?? 0 ), ! empty( $crazylinks_item['decorative'] ) );
	if ( '' !== $crazylinks_alt || ! empty( $crazylinks_item['decorative'] ) ) {
		$crazylinks_attr['alt'] = $crazylinks_alt;
	}
	$crazylinks_img = $renderer->image( (int) ( $crazylinks_item['image'] ?? 0 ), 'cl-thumb', $ctx, $crazylinks_attr );
	if ( '' === $crazylinks_img ) {
		continue;
	}
	$crazylinks_caption = (string) ( $crazylinks_item['caption'] ?? '' );
	$crazylinks_href    = $ctx->href( [ 'tracking' => false ], (string) ( $crazylinks_item['url'] ?? '' ) );
	$crazylinks_name    = $crazylinks_alt;
	ob_start();
	?>
	<li class="cl-gallery__item">
		<figure class="cl-gallery__figure">
			<?php if ( '' !== $crazylinks_href ) : ?>
				<a class="cl-gallery__link" href="<?php echo esc_url( $crazylinks_href, \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES ); ?>">
					<?php echo $crazylinks_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?>
					<?php if ( '' === $crazylinks_name && '' === $crazylinks_caption ) : ?>
						<span class="cl-sr"><?php esc_html_e( 'View image link', 'crazylinks' ); ?></span>
					<?php endif; ?>
				</a>
			<?php else : ?>
				<?php echo $crazylinks_img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?>
			<?php endif; ?>
			<?php if ( '' !== $crazylinks_caption ) : ?>
				<figcaption class="cl-gallery__caption"><?php echo esc_html( $crazylinks_caption ); ?></figcaption>
			<?php endif; ?>
		</figure>
	</li>
	<?php
	$crazylinks_tiles .= (string) ob_get_clean();
}
if ( '' === $crazylinks_tiles ) {
	return;
}
?>
<ul class="cl-gallery cl-gallery--cols-<?php echo esc_attr( (string) $crazylinks_cols ); ?>" aria-label="<?php esc_attr_e( 'Gallery', 'crazylinks' ); ?>">
	<?php echo $crazylinks_tiles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above. ?>
</ul>
