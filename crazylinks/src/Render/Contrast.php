<?php
/**
 * WCAG contrast maths.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Relative luminance and contrast ratio per WCAG 2.1, plus helpers to check a design preset.
 */
final class Contrast {

	public const AA_BODY  = 4.5;
	public const AA_LARGE = 3.0;

	/**
	 * Hex colour to [r, g, b] (0-255). Alpha is ignored. Returns null for anything that is not a hex colour.
	 *
	 * @param string $hex #rgb, #rgba, #rrggbb or #rrggbbaa.
	 * @return int[]|null
	 */
	public static function rgb( string $hex ): ?array {
		if ( ! preg_match( '/^#([0-9a-fA-F]{3,8})$/', $hex, $m ) ) {
			return null;
		}
		$h = $m[1];
		if ( 3 === strlen( $h ) || 4 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		} elseif ( 6 !== strlen( $h ) && 8 !== strlen( $h ) ) {
			return null;
		}
		return [ (int) hexdec( substr( $h, 0, 2 ) ), (int) hexdec( substr( $h, 2, 2 ) ), (int) hexdec( substr( $h, 4, 2 ) ) ];
	}

	/**
	 * Relative luminance (0 black .. 1 white).
	 *
	 * @param int[] $rgb Colour.
	 */
	public static function luminance( array $rgb ): float {
		$lin = static function ( int $c ): float {
			$s = $c / 255;
			return $s <= 0.03928 ? $s / 12.92 : pow( ( $s + 0.055 ) / 1.055, 2.4 );
		};
		return 0.2126 * $lin( $rgb[0] ) + 0.7152 * $lin( $rgb[1] ) + 0.0722 * $lin( $rgb[2] );
	}

	/**
	 * Contrast ratio between two hex colours (1 to 21). Unparseable input yields 1.
	 *
	 * @param string $a Colour.
	 * @param string $b Colour.
	 */
	public static function ratio( string $a, string $b ): float {
		$ca = self::rgb( $a );
		$cb = self::rgb( $b );
		if ( null === $ca || null === $cb ) {
			return 1.0;
		}
		$la = self::luminance( $ca );
		$lb = self::luminance( $cb );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * Mixes $top over $bottom at the given opacity, returning #rrggbb.
	 *
	 * @param string $top     Foreground colour.
	 * @param string $bottom  Background colour.
	 * @param float  $opacity Foreground opacity 0..1.
	 */
	public static function blend( string $top, string $bottom, float $opacity ): string {
		$t = self::rgb( $top ) ?? [ 0, 0, 0 ];
		$b = self::rgb( $bottom ) ?? [ 255, 255, 255 ];
		$o = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$o .= sprintf( '%02x', (int) round( $t[ $i ] * $opacity + $b[ $i ] * ( 1 - $opacity ) ) );
		}
		return $o;
	}

	/**
	 * Black or white, whichever reads better on $bg.
	 *
	 * @param string $bg Background colour.
	 */
	public static function ink_for( string $bg ): string {
		return self::ratio( '#000000', $bg ) >= self::ratio( '#ffffff', $bg ) ? '#000000' : '#ffffff';
	}

	/**
	 * Colours the page background can take: the solid colour, or five samples along a gradient.
	 *
	 * @param array<string, mixed> $bg Resolved background token.
	 * @return string[]
	 */
	public static function background_samples( array $bg ): array {
		if ( 'gradient' === ( $bg['type'] ?? '' ) ) {
			$from = (string) ( $bg['from'] ?? '#ffffff' );
			$to   = (string) ( $bg['to'] ?? '#ffffff' );
			$out  = [];
			foreach ( [ 0, 0.25, 0.5, 0.75, 1 ] as $t ) {
				$out[] = self::blend( $to, $from, $t );
			}
			return $out;
		}
		return [ (string) ( $bg['color'] ?? '#ffffff' ) ];
	}

	/**
	 * Every text/background pair a resolved design produces, with the minimum ratio each must meet.
	 *
	 * @param array<string, mixed> $design Resolved design (preset merged with any variant).
	 * @return array<int, array{label:string, fg:string, bg:string, min:float}>
	 */
	public static function pairs( array $design ): array {
		$bg    = (array) ( $design['background'] ?? [] );
		$text  = (array) ( $design['text'] ?? [] );
		$btn   = (array) ( $design['button'] ?? [] );
		$fill  = (string) ( $btn['fill'] ?? 'solid' );
		$pairs = [];
		foreach ( self::background_samples( $bg ) as $i => $sample ) {
			$n       = '@' . $i;
			$pairs[] = [
				'label' => 'text on page' . $n,
				'fg'    => (string) $text['color'],
				'bg'    => $sample,
				'min'   => self::AA_BODY,
			];
			$pairs[] = [
				'label' => 'muted text on page' . $n,
				'fg'    => (string) ( $text['muted'] ?? $text['color'] ),
				'bg'    => $sample,
				'min'   => self::AA_BODY,
			];
			// Cards (posts, contact rows, countdown) tint the page with up to 14% of the text colour.
			$pairs[] = [
				'label' => 'muted text on card' . $n,
				'fg'    => (string) ( $text['muted'] ?? $text['color'] ),
				'bg'    => self::blend( (string) $text['color'], $sample, 0.14 ),
				'min'   => self::AA_BODY,
			];
			$pairs[] = [
				'label' => 'accent on page' . $n,
				'fg'    => (string) $design['accent'],
				'bg'    => $sample,
				'min'   => self::AA_BODY,
			];
			switch ( $fill ) {
				case 'outline':
					$pairs[] = [
						'label' => 'outline button on page' . $n,
						'fg'    => (string) $btn['color'],
						'bg'    => $sample,
						'min'   => self::AA_BODY,
					];
					break;
				case 'glass':
					$pairs[] = [
						'label' => 'glass button text' . $n,
						'fg'    => (string) $text['color'],
						'bg'    => self::blend( '#ffffff', $sample, 0.18 ),
						'min'   => self::AA_BODY,
					];
					break;
				case 'soft':
					$pairs[] = [
						'label' => 'soft button text' . $n,
						'fg'    => (string) $btn['text_color'],
						'bg'    => self::blend( (string) $btn['color'], $sample, 0.88 ),
						'min'   => self::AA_BODY,
					];
					break;
			}
		}
		// The solid button colours are also used by the email form's submit button, whatever the link fill.
		$pairs[] = [
			'label' => 'solid button text',
			'fg'    => (string) $btn['text_color'],
			'bg'    => (string) $btn['color'],
			'min'   => self::AA_BODY,
		];
		return $pairs;
	}

	/**
	 * Colours a section background can take: the overlay of an image background (or its base colour),
	 * the base colour of a pattern, the solid colour, or samples along a gradient.
	 *
	 * @param array<string, mixed> $bg Section background (must have a type).
	 * @return string[]
	 */
	public static function section_samples( array $bg ): array {
		$hex = static fn( $v ): bool => is_string( $v ) && null !== self::rgb( $v );
		switch ( $bg['type'] ?? 'solid' ) {
			case 'gradient':
				return self::background_samples(
					[
						'type' => 'gradient',
						'from' => $hex( $bg['from'] ?? null ) ? $bg['from'] : '#ffffff',
						'to'   => $hex( $bg['to'] ?? null ) ? $bg['to'] : '#eeeeee',
					]
				);
			case 'image':
				if ( $hex( $bg['overlay'] ?? null ) ) {
					return [ (string) $bg['overlay'] ];
				}
				return [ $hex( $bg['color'] ?? null ) ? (string) $bg['color'] : '#111111' ];
			default:
				return [ $hex( $bg['color'] ?? null ) ? (string) $bg['color'] : '#ffffff' ];
		}
	}

	/**
	 * Whether a section paints its own background.
	 *
	 * @param array<string, mixed> $props Section props.
	 */
	public static function section_has_background( array $props ): bool {
		return is_array( $props['background'] ?? null ) && isset( $props['background']['type'] );
	}

	/**
	 * The text colour a section's children use, or '' when the section inherits the page colours:
	 * the author's text_color, else (for a section with its own background) the page text colour when
	 * it reads well on that background, otherwise black or white, whichever reads better.
	 *
	 * @param array<string, mixed> $props     Section props.
	 * @param string               $page_text Resolved page text colour.
	 */
	public static function section_text( array $props, string $page_text ): string {
		$own = (string) ( $props['text_color'] ?? '' );
		if ( null !== self::rgb( $own ) ) {
			return $own;
		}
		if ( ! self::section_has_background( $props ) ) {
			return '';
		}
		$samples = self::section_samples( (array) $props['background'] );
		$worst   = static function ( string $fg ) use ( $samples ): float {
			return min( array_map( static fn( string $bg ): float => self::ratio( $fg, $bg ), $samples ) );
		};
		if ( null !== self::rgb( $page_text ) && $worst( $page_text ) >= self::AA_BODY ) {
			return $page_text;
		}
		return $worst( '#000000' ) >= $worst( '#ffffff' ) ? '#000000' : '#ffffff';
	}

	/**
	 * The text/background pairs of a section (its text colour on each background sample, or on the
	 * page background when only the text colour is overridden). Empty when the section inherits both.
	 *
	 * @param array<string, mixed> $props        Section props.
	 * @param string               $page_text    Resolved page text colour.
	 * @param string[]             $page_samples Page background samples.
	 * @return array<int, array{label:string, fg:string, bg:string, min:float}>
	 */
	public static function section_pairs( array $props, string $page_text, array $page_samples ): array {
		$fg = self::section_text( $props, $page_text );
		if ( '' === $fg ) {
			return [];
		}
		$samples = self::section_has_background( $props ) ? self::section_samples( (array) $props['background'] ) : $page_samples;
		$out     = [];
		foreach ( $samples as $i => $bg ) {
			$out[] = [
				'label' => 'section text@' . $i,
				'fg'    => $fg,
				'bg'    => $bg,
				'min'   => self::AA_BODY,
			];
		}
		return $out;
	}
}
