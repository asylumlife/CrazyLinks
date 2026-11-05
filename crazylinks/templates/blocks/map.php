<?php
/**
 * Map card: the address and plain links to Google and/or Apple Maps. No embedded map, no remote requests.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed> $props
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_address = (string) ( $props['address'] ?? '' );
if ( '' === $crazylinks_address ) {
	return;
}
$crazylinks_provider = (string) ( $props['provider'] ?? 'both' );
$crazylinks_links    = [];
if ( 'apple' !== $crazylinks_provider ) {
	$crazylinks_links[] = [ __( 'Open in Google Maps', 'crazylinks' ), \CrazyLinks\Render\Maps::google( $crazylinks_address ) ];
}
if ( 'google' !== $crazylinks_provider ) {
	$crazylinks_links[] = [ __( 'Open in Apple Maps', 'crazylinks' ), \CrazyLinks\Render\Maps::apple( $crazylinks_address ) ];
}
?>
<div class="cl-map">
	<?php echo \CrazyLinks\Render\Icons::svg( 'pin', 'cl-icon cl-map__icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
	<?php if ( ! empty( $props['label'] ) ) : ?>
		<p class="cl-map__label"><?php echo esc_html( (string) $props['label'] ); ?></p>
	<?php endif; ?>
	<address class="cl-map__address"><?php echo nl2br( esc_html( $crazylinks_address ) ); ?></address>
	<div class="cl-map__links">
		<?php foreach ( $crazylinks_links as [ $crazylinks_text, $crazylinks_href ] ) : ?>
			<a class="cl-map__link" href="<?php echo esc_url( $crazylinks_href, [ 'https' ] ); ?>" rel="noopener"><?php echo esc_html( $crazylinks_text ); ?></a>
		<?php endforeach; ?>
	</div>
</div>
