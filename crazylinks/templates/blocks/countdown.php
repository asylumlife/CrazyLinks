<?php
/**
 * Countdown to a date, once or every week. The server renders the remaining time; the front-end script ticks it.
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
$crazylinks_text   = (string) ( $props['expired_text'] ?? '' );
$crazylinks_url    = $ctx->href( [ 'tracking' => false ], (string) ( $props['url'] ?? '' ) );
$crazylinks_repeat = [];
if ( 'weekly' === ( $props['repeat'] ?? 'none' ) ) {
	// Weekly: count down to the next occurrence; during one, show the after-the-end text and link.
	$crazylinks_repeat = \CrazyLinks\Render\Schedule::countdown_occurrences(
		$crazylinks_target,
		array_map( 'strval', (array) ( $props['repeat_days'] ?? [] ) ),
		(int) ( $props['live_minutes'] ?? 60 ),
		$ctx->now,
		$ctx->tz
	);
	if ( ! $crazylinks_repeat ) {
		return;
	}
	[ $crazylinks_target, $crazylinks_until ] = $crazylinks_repeat[0];
	$ctx->note_boundary( $crazylinks_until );
	if ( '' === $crazylinks_text ) {
		$crazylinks_text = __( 'Happening now', 'crazylinks' );
	}
}
$ctx->note_boundary( $crazylinks_target );
$crazylinks_left  = max( 0, $crazylinks_target - $ctx->now );
$crazylinks_done  = 0 === $crazylinks_left;
$crazylinks_units = [
	'd' => [ (int) floor( $crazylinks_left / DAY_IN_SECONDS ), __( 'Days', 'crazylinks' ) ],
	'h' => [ (int) floor( ( $crazylinks_left % DAY_IN_SECONDS ) / HOUR_IN_SECONDS ), __( 'Hours', 'crazylinks' ) ],
	'm' => [ (int) floor( ( $crazylinks_left % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS ), __( 'Min', 'crazylinks' ) ],
	's' => [ $crazylinks_left % MINUTE_IN_SECONDS, __( 'Sec', 'crazylinks' ) ],
];
if ( $crazylinks_done && '' === $crazylinks_text && '' === $crazylinks_url ) {
	return;
}
$crazylinks_size       = \CrazyLinks\Render\Renderer::choice( $props, 'size', [ 'small', 'medium' ], 'large' );
$crazylinks_link_label = '' !== (string) ( $props['link_label'] ?? '' ) ? (string) $props['link_label'] : __( 'Continue', 'crazylinks' );
// Upcoming occurrences as "start/end" Unix times, so a cached page rolls over without a reload.
$crazylinks_list = implode( ',', array_map( static fn( $o ) => $o[0] . '/' . $o[1], $crazylinks_repeat ) );
?>
<div class="cl-countdown cl-countdown--<?php echo esc_attr( $crazylinks_size ); ?>" role="timer" data-cl-countdown="<?php echo esc_attr( gmdate( 'c', $crazylinks_target ) ); ?>"<?php echo '' !== $crazylinks_list ? ' data-cl-repeat="' . esc_attr( $crazylinks_list ) . '"' : ''; ?>>
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
			<a class="cl-link cl-countdown__link" href="<?php echo esc_url( $crazylinks_url, \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES ); ?>"><span class="cl-link__body"><span class="cl-link__title"><?php echo esc_html( $crazylinks_link_label ); ?></span></span></a>
		<?php endif; ?>
	</div>
</div>
