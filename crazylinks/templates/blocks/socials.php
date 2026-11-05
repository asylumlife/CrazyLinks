<?php
/**
 * Social icons row.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $block
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_items = array_filter( (array) ( $props['items'] ?? [] ), static fn( $i ) => is_array( $i ) && ! empty( $i['url'] ) );
if ( ! $crazylinks_items ) {
	return;
}
$crazylinks_labels = \CrazyLinks\Render\Icons::networks();
?>
<ul class="cl-socials" aria-label="<?php esc_attr_e( 'Social profiles', 'crazylinks' ); ?>">
	<?php foreach ( $crazylinks_items as $crazylinks_item ) : ?>
		<?php $crazylinks_net = (string) $crazylinks_item['network']; ?>
		<li>
			<a class="cl-social cl-social--<?php echo esc_attr( sanitize_html_class( $crazylinks_net ) ); ?>" href="<?php echo esc_url( $ctx->item_href( $block, (string) ( $crazylinks_item['id'] ?? '' ), (string) $crazylinks_item['url'] ), \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES ); ?>" rel="me noopener">
				<?php echo \CrazyLinks\Render\Icons::network( $crazylinks_net ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG / escaped monogram. ?>
				<span class="cl-sr"><?php echo esc_html( $crazylinks_labels[ $crazylinks_net ] ?? $crazylinks_net ); ?></span>
			</a>
		</li>
	<?php endforeach; ?>
</ul>
