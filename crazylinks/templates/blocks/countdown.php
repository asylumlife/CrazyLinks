<?php
/**
 * Countdown to a date. The server renders the remaining time; the front-end script ticks it.
 * Without JavaScript the numbers stay as rendered. Cached pages are rebuilt when the target passes.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_target = strtotime( (string) ( $props['target'] ?? '' ) );
if ( false === $crazylinks_target ) {
	return;
}
$ctx->note_boundary( $crazylinks_target );
$crazylinks_left  = max( 0, $crazylinks_target - $ctx->now );
$crazylinks_done  = 0 === $crazylinks_left;
$crazylinks_text  = (string) ( $props['expired_text'] ?? '' );
$crazylinks_url   = $ctx->href( [ 'tracking' => false ], (string) ( $props['url'] ?? '' ) );
$crazylinks_units = [
	'd' => [ (int) floor( $crazylinks_left / DAY_IN_SECONDS ), __( 'Days', 'crazylinks' ) ],
	'h' => [ (int) floor( ( $crazylinks_left % DAY_IN_SECONDS ) / HOUR_IN_SECONDS ), __( 'Hours', 'crazylinks' ) ],
	'm' => [ (int) floor( ( $crazylinks_left % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS ), __( 'Min', 'crazylinks' ) ],
	's' => [ $crazylinks_left % MINUTE_IN_SECONDS, __( 'Sec', 'crazylinks' ) ],
];
if ( $crazylinks_done && '' === $crazylinks_text && '' === $crazylinks_url ) {
	return;
}
?>
<div class="cl-countdown" role="timer" data-cl-countdown="<?php echo esc_attr( gmdate( 'c', $crazylinks_target ) ); ?>">
	<?php if ( ! empty( $props['label'] ) ) : ?>
		<p class="cl-countdown__label"><?php echo esc_html( (string) $props['label'] ); ?></p>
	<?php endif; ?>
	<div class="cl-countdown__live"<?php echo $crazylinks_done ? ' hidden' : ''; ?>>
		<?php foreach ( $crazylinks_units as $crazylinks_key => [ $crazylinks_num, $crazylinks_name ] ) : ?>
			<span class="cl-countdown__unit">
				<span class="cl-countdown__num" data-cl-unit="<?php echo esc_attr( $crazylinks_key ); ?>"><?php echo esc_html( 'd' === $crazylinks_key ? (string) $crazylinks_num : sprintf( '%02d', $crazylinks_num ) ); ?></span>
				<span class="cl-countdown__name"><?php echo esc_html( $crazylinks_name ); ?></span>
			</span>
		<?php endforeach; ?>
	</div>
	<div class="cl-countdown__done"<?php echo $crazylinks_done ? '' : ' hidden'; ?>>
		<p class="cl-countdown__ended"><?php echo esc_html( '' !== $crazylinks_text ? $crazylinks_text : __( 'This countdown has ended.', 'crazylinks' ) ); ?></p>
		<?php if ( '' !== $crazylinks_url ) : ?>
			<a class="cl-link cl-countdown__link" href="<?php echo esc_url( $crazylinks_url, \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES ); ?>"><span class="cl-link__body"><span class="cl-link__title"><?php echo esc_html( '' !== (string) ( $props['link_label'] ?? '' ) ? (string) $props['link_label'] : __( 'Continue', 'crazylinks' ) ); ?></span></span></a>
		<?php endif; ?>
	</div>
</div>
