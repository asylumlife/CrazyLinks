<?php
/**
 * Payment button. The preset supplies the icon and default label; the button opens the service's own page.
 * Like link blocks, tracked payment buttons go through the /go/ redirect (click logging, UTM tags).
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $block
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_service = (string) ( $props['service'] ?? 'custom' );
$crazylinks_preset  = \CrazyLinks\Render\Payments::get( $crazylinks_service );
$crazylinks_url     = \CrazyLinks\Render\Payments::resolve( $props );
if ( '' === $crazylinks_url || ! \CrazyLinks\Render\Payments::url_matches( $crazylinks_service, $crazylinks_url ) ) {
	return;
}
$crazylinks_href  = $ctx->href( $block, $crazylinks_url );
$crazylinks_label = '' !== (string) ( $props['label'] ?? '' ) ? (string) $props['label'] : $crazylinks_preset['label'];
?>
<a class="cl-link cl-link--classic cl-link--thumb cl-pay cl-pay--<?php echo esc_attr( sanitize_html_class( $crazylinks_service ) ); ?>" href="<?php echo esc_url( $crazylinks_href, \CrazyLinks\Schema\UrlPolicy::WEB_SCHEMES ); ?>" rel="noopener noreferrer">
	<span class="cl-link__icon"><?php echo \CrazyLinks\Render\Icons::svg( $crazylinks_preset['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?></span>
	<span class="cl-link__body">
		<span class="cl-link__title"><?php echo esc_html( $crazylinks_label ); ?></span>
		<?php if ( ! empty( $props['amount_hint'] ) ) : ?>
			<span class="cl-link__subtitle"><?php echo esc_html( (string) $props['amount_hint'] ); ?></span>
		<?php endif; ?>
	</span>
</a>
