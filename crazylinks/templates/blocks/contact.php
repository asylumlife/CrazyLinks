<?php
/**
 * Contact card: phone, email and address rows, each a direct link, plus a "Save contact" row
 * pointing at the page's vCard download when props.vcard is on.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_rows = [];
if ( ! empty( $props['phone'] ) ) {
	$crazylinks_digits = (string) preg_replace( '/[^0-9+]/', '', (string) $props['phone'] );
	if ( '' !== $crazylinks_digits ) {
		$crazylinks_rows[] = [ 'phone', __( 'Phone', 'crazylinks' ), (string) $props['phone'], 'tel:' . $crazylinks_digits ];
	}
}
if ( ! empty( $props['email'] ) ) {
	$crazylinks_rows[] = [ 'email', __( 'Email', 'crazylinks' ), (string) $props['email'], 'mailto:' . $props['email'] ];
}
if ( ! empty( $props['address'] ) ) {
	$crazylinks_rows[] = [ 'pin', __( 'Address', 'crazylinks' ), (string) $props['address'], \CrazyLinks\Render\Maps::google( (string) $props['address'] ) ];
}
if ( ! $crazylinks_rows ) {
	return;
}
if ( ! empty( $props['vcard'] ) && '' !== $ctx->slug && $ctx->post_id > 0 ) {
	// Root-relative so the cached HTML does not depend on the request host.
	$crazylinks_rows[] = [ 'vcard', __( 'Save contact', 'crazylinks' ), __( 'Download vCard', 'crazylinks' ), \CrazyLinks\Frontend\Router::vcard_path( $ctx->slug ) ];
}
?>
<ul class="cl-contact" aria-label="<?php esc_attr_e( 'Contact', 'crazylinks' ); ?>">
	<?php foreach ( $crazylinks_rows as [ $crazylinks_icon, $crazylinks_label, $crazylinks_value, $crazylinks_href ] ) : ?>
		<li>
			<a class="cl-contact__row" href="<?php echo esc_url( $crazylinks_href, \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES ); ?>"<?php echo 'pin' === $crazylinks_icon ? ' rel="noopener"' : ''; ?><?php echo 'vcard' === $crazylinks_icon ? ' download rel="nofollow"' : ''; ?>>
				<?php echo \CrazyLinks\Render\Icons::svg( $crazylinks_icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
				<span class="cl-contact__text">
					<span class="cl-contact__label"><?php echo esc_html( $crazylinks_label ); ?></span>
					<span class="cl-contact__value"><?php echo esc_html( $crazylinks_value ); ?></span>
				</span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
