<?php
/**
 * Inline SVG chart.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the daily views / clicks chart as plain SVG (no library, no script). All text is escaped here.
 */
final class Chart {

	private const W = 720;
	private const H = 240;
	private const L = 44;
	private const R = 12;
	private const T = 12;
	private const B = 30;

	/**
	 * A "nice" axis maximum at or above the value.
	 *
	 * @param int $max Largest value.
	 */
	public static function nice_max( int $max ): int {
		if ( $max <= 4 ) {
			return 4;
		}
		$pow = 10 ** (int) floor( log10( $max ) );
		foreach ( [ 1, 2, 2.5, 5, 10 ] as $step ) {
			if ( $max <= $step * $pow ) {
				return (int) ceil( $step * $pow );
			}
		}
		return (int) ( 10 * $pow );
	}

	/**
	 * Builds the SVG.
	 *
	 * @param array<int, array{date:string, views:int, clicks:int}> $series Daily rows (oldest first).
	 * @return string Trusted markup.
	 */
	public static function svg( array $series ): string {
		$n    = count( $series );
		$peak = 1;
		foreach ( $series as $d ) {
			$peak = max( $peak, (int) $d['views'], (int) $d['clicks'] );
		}
		$top    = self::nice_max( $peak );
		$plot_w = self::W - self::L - self::R;
		$plot_h = self::H - self::T - self::B;
		$x      = static fn( int $i ): float => self::L + ( $n > 1 ? $i / ( $n - 1 ) : 0.5 ) * $plot_w;
		$y      = static fn( int $v ): float => self::T + $plot_h - ( $v / $top ) * $plot_h;

		$views  = [];
		$clicks = [];
		foreach ( array_values( $series ) as $i => $d ) {
			$views[]  = round( $x( $i ), 1 ) . ',' . round( $y( (int) $d['views'] ), 1 );
			$clicks[] = round( $x( $i ), 1 ) . ',' . round( $y( (int) $d['clicks'] ), 1 );
		}

		$out  = '<svg class="cl-chart" viewBox="0 0 ' . self::W . ' ' . self::H . '" role="img" aria-labelledby="cl-chart-title cl-chart-desc" preserveAspectRatio="xMidYMid meet">';
		$out .= '<title id="cl-chart-title">' . esc_html__( 'Daily page views and link clicks', 'crazylinks' ) . '</title>';
		$out .= '<desc id="cl-chart-desc">' . esc_html(
			sprintf(
				/* translators: 1: first date, 2: last date, 3: highest value on the axis. */
				__( 'Line chart from %1$s to %2$s. The vertical axis runs from 0 to %3$s. The same numbers are in the data table below the chart.', 'crazylinks' ),
				$n ? $series[0]['date'] : '',
				$n ? $series[ $n - 1 ]['date'] : '',
				number_format_i18n( $top )
			)
		) . '</desc>';

		foreach ( [ 0, 0.5, 1 ] as $f ) {
			$value = (int) round( $top * $f );
			$gy    = round( $y( $value ), 1 );
			$out  .= '<line class="cl-chart__grid" x1="' . self::L . '" x2="' . ( self::W - self::R ) . '" y1="' . $gy . '" y2="' . $gy . '"/>';
			$out  .= '<text class="cl-chart__label" x="' . ( self::L - 6 ) . '" y="' . ( $gy + 4 ) . '" text-anchor="end">' . esc_html( number_format_i18n( $value ) ) . '</text>';
		}
		if ( $n ) {
			$ticks = array_unique( [ 0, intdiv( $n - 1, 2 ), $n - 1 ] );
			foreach ( $ticks as $i ) {
				$anchor = 0 === $i ? 'start' : ( $n - 1 === $i ? 'end' : 'middle' );
				$out   .= '<text class="cl-chart__label" x="' . round( $x( $i ), 1 ) . '" y="' . ( self::H - 8 ) . '" text-anchor="' . $anchor . '">' . esc_html( $series[ $i ]['date'] ) . '</text>';
			}
			$base = self::T + $plot_h;
			$out .= '<polygon class="cl-chart__area" points="' . round( $x( 0 ), 1 ) . ',' . $base . ' ' . implode( ' ', $views ) . ' ' . round( $x( $n - 1 ), 1 ) . ',' . $base . '"/>';
			$out .= '<polyline class="cl-chart__line cl-chart__line--views" points="' . implode( ' ', $views ) . '"/>';
			$out .= '<polyline class="cl-chart__line cl-chart__line--clicks" points="' . implode( ' ', $clicks ) . '"/>';
			if ( 1 === $n ) {
				$out .= '<circle class="cl-chart__dot cl-chart__dot--views" cx="' . round( $x( 0 ), 1 ) . '" cy="' . round( $y( (int) $series[0]['views'] ), 1 ) . '" r="3"/>';
				$out .= '<circle class="cl-chart__dot cl-chart__dot--clicks" cx="' . round( $x( 0 ), 1 ) . '" cy="' . round( $y( (int) $series[0]['clicks'] ), 1 ) . '" r="3"/>';
			}
		}
		return $out . '</svg>';
	}
}
