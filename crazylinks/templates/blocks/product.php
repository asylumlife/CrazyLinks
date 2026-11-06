<?php
/**
 * Product: one store product, or the newest products, as a card or a link button. The Buy link goes
 * to the product page on the store (never the cart), through /go/ so clicks are counted.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $block
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 */

defined( 'ABSPATH' ) || exit;

use CrazyLinks\Woo\Store;

$crazylinks_latest = 'latest' === ( $props['mode'] ?? 'single' );
if ( $crazylinks_latest ) {
	$crazylinks_items = Store::latest( (int) ( $props['count'] ?? 3 ), (string) ( $props['category'] ?? '' ) );
} else {
	$crazylinks_one   = Store::product( (int) ( $props['product_id'] ?? 0 ) );
	$crazylinks_items = $crazylinks_one ? [ $crazylinks_one ] : [];
}
// The store changes without the page being saved, so a cached copy lives at most 15 minutes.
$ctx->note_boundary( $ctx->now + 15 * MINUTE_IN_SECONDS );
if ( ! $crazylinks_items ) {
	if ( 'preview' === $ctx->mode ) {
		echo '<p class="cl-product__empty">' . esc_html__( 'Product block: choose a product, and connect a store in CrazyLinks → Settings. Visitors see nothing until a product is found.', 'crazylinks' ) . '</p>';
	}
	return;
}
$crazylinks_button = 'button' === ( $props['display'] ?? 'card' );
$crazylinks_price  = ! isset( $props['show_price'] ) || ! empty( $props['show_price'] );
$crazylinks_cta    = '' !== trim( (string) ( $props['button_text'] ?? '' ) ) ? (string) $props['button_text'] : __( 'Buy', 'crazylinks' );
$crazylinks_sub    = $crazylinks_latest ? '' : (string) ( $props['subtitle'] ?? '' );
$crazylinks_rel    = 'noopener';

/**
 * Price markup for one product (all values escaped).
 *
 * @param array<string, mixed> $product Product.
 */
$crazylinks_price_html = static function ( array $product ): string {
	if ( '' === (string) $product['price'] ) {
		return '';
	}
	if ( ! empty( $product['on_sale'] ) ) {
		return '<p class="cl-product__price"><span class="cl-product__sale">' . esc_html__( 'Sale', 'crazylinks' ) . '</span> <del class="cl-product__was"><span class="cl-sr">' . esc_html__( 'Regular price:', 'crazylinks' ) . ' </span>' . esc_html( (string) $product['regular_price'] ) . '</del> <ins class="cl-product__now"><span class="cl-sr">' . esc_html__( 'Sale price:', 'crazylinks' ) . ' </span>' . esc_html( (string) $product['price'] ) . '</ins></p>';
	}
	$from = ! empty( $product['price_from'] ) ? esc_html__( 'From', 'crazylinks' ) . ' ' : '';
	return '<p class="cl-product__price"><span class="cl-product__now">' . $from . esc_html( (string) $product['price'] ) . '</span></p>';
};
?>
<?php if ( $crazylinks_latest || $crazylinks_button ) : ?>
<ul class="cl-products cl-products--<?php echo $crazylinks_button ? 'button' : 'card'; ?>" aria-label="<?php esc_attr_e( 'Products', 'crazylinks' ); ?>">
<?php endif; ?>
<?php foreach ( $crazylinks_items as $crazylinks_product ) : ?>
	<?php
	$crazylinks_href = $ctx->product_href( $block, (int) $crazylinks_product['id'], (string) $crazylinks_product['url'] );
	if ( '' === $crazylinks_href ) {
		continue;
	}
	$crazylinks_name  = ( ! $crazylinks_latest && '' !== trim( (string) ( $props['title'] ?? '' ) ) ) ? (string) $props['title'] : (string) $crazylinks_product['name'];
	$crazylinks_image = (string) $crazylinks_product['image'];
	?>
	<?php if ( $crazylinks_latest || $crazylinks_button ) : ?>
<li class="cl-products__item">
	<?php endif; ?>
	<?php if ( $crazylinks_button ) : ?>
	<a class="cl-link cl-link--classic cl-link--thumb cl-product-link" href="<?php echo esc_url( $crazylinks_href ); ?>" rel="<?php echo esc_attr( $crazylinks_rel ); ?>" aria-label="<?php echo esc_attr( $crazylinks_cta . ': ' . $crazylinks_name ); ?>">
		<?php if ( '' !== $crazylinks_image ) : ?>
			<img class="cl-link__thumb" src="<?php echo esc_url( $crazylinks_image ); ?>" alt="" width="44" height="44" loading="lazy" decoding="async">
		<?php endif; ?>
		<span class="cl-link__body">
			<span class="cl-link__title"><?php echo esc_html( $crazylinks_name ); ?></span>
			<?php if ( $crazylinks_price && '' !== (string) $crazylinks_product['price'] ) : ?>
				<span class="cl-link__subtitle"><?php echo esc_html( ( ! empty( $crazylinks_product['price_from'] ) ? __( 'From', 'crazylinks' ) . ' ' : '' ) . (string) $crazylinks_product['price'] ); ?></span>
			<?php elseif ( '' !== $crazylinks_sub ) : ?>
				<span class="cl-link__subtitle"><?php echo esc_html( $crazylinks_sub ); ?></span>
			<?php endif; ?>
		</span>
		<?php if ( ! empty( $crazylinks_product['on_sale'] ) ) : ?>
			<span class="cl-badge"><?php esc_html_e( 'Sale', 'crazylinks' ); ?></span>
		<?php endif; ?>
	</a>
	<?php else : ?>
	<article class="cl-product<?php echo $crazylinks_latest ? ' cl-product--row' : ''; ?>">
		<?php if ( '' !== $crazylinks_image ) : ?>
			<img class="cl-product__img" src="<?php echo esc_url( $crazylinks_image ); ?>" alt="" width="<?php echo $crazylinks_latest ? '72' : '480'; ?>" height="<?php echo $crazylinks_latest ? '72' : '480'; ?>" loading="lazy" decoding="async">
		<?php endif; ?>
		<div class="cl-product__body">
			<p class="cl-product__name"><?php echo esc_html( $crazylinks_name ); ?></p>
			<?php if ( '' !== $crazylinks_sub ) : ?>
				<p class="cl-product__sub"><?php echo esc_html( $crazylinks_sub ); ?></p>
			<?php endif; ?>
			<?php echo $crazylinks_price ? $crazylinks_price_html( $crazylinks_product ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure above. ?>
		</div>
		<a class="cl-link cl-link--classic cl-product__buy" href="<?php echo esc_url( $crazylinks_href ); ?>" rel="<?php echo esc_attr( $crazylinks_rel ); ?>" aria-label="<?php echo esc_attr( $crazylinks_cta . ': ' . $crazylinks_name ); ?>"><?php echo esc_html( $crazylinks_cta ); ?></a>
	</article>
	<?php endif; ?>
	<?php if ( $crazylinks_latest || $crazylinks_button ) : ?>
</li>
	<?php endif; ?>
<?php endforeach; ?>
<?php if ( $crazylinks_latest || $crazylinks_button ) : ?>
</ul>
<?php endif; ?>
