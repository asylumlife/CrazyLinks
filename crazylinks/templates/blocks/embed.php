<?php
/**
 * Embed block: oEmbed with a click-to-play facade. The provider iframe loads only after a tap;
 * without JavaScript the facade is a plain link to the provider.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $block
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_url = (string) ( $props['url'] ?? '' );
if ( '' === $crazylinks_url ) {
	return;
}
$crazylinks_data  = \CrazyLinks\Frontend\Embeds::cached( $ctx->post_id, $crazylinks_url );
$crazylinks_title = (string) ( $props['title'] ?? ( $crazylinks_data['title'] ?? '' ) );
if ( '' === $crazylinks_title ) {
	$crazylinks_title = (string) wp_parse_url( $crazylinks_url, PHP_URL_HOST );
}
$crazylinks_html = (string) ( $crazylinks_data['html'] ?? '' );
?>
<div class="cl-embed"<?php echo '' !== $crazylinks_html ? ' data-cl-embed' : ''; ?>>
	<a class="cl-embed__facade" href="<?php echo esc_url( $crazylinks_url ); ?>" rel="noopener">
		<?php if ( ! empty( $crazylinks_data['thumbnail_url'] ) ) : ?>
			<img class="cl-embed__thumb" src="<?php echo esc_url( (string) $crazylinks_data['thumbnail_url'] ); ?>" alt="" loading="lazy" decoding="async" width="480" height="270">
		<?php endif; ?>
		<?php echo \CrazyLinks\Render\Icons::play(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG. ?>
		<span class="cl-embed__title">
			<?php
			/* translators: %s: embedded media title. */
			echo esc_html( sprintf( __( 'Play: %s', 'crazylinks' ), $crazylinks_title ) );
			?>
		</span>
	</a>
	<?php if ( '' !== $crazylinks_html ) : ?>
		<template class="cl-embed__tpl"><?php echo \CrazyLinks\Frontend\Embeds::kses( $crazylinks_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered by Embeds::kses(). ?></template>
	<?php endif; ?>
</div>
