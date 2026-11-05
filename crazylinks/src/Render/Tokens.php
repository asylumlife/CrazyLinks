<?php
/**
 * Design tokens → CSS custom properties.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a page's design (preset + overrides) and emits CSS custom properties and classes.
 */
final class Tokens {

	public const FONTS = [
		'system-sans'    => 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
		'system-serif'   => 'ui-serif, Georgia, Cambria, "Times New Roman", serif',
		'system-rounded' => 'ui-rounded, "SF Pro Rounded", system-ui, sans-serif',
		'system-mono'    => 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
	];

	public const SIZES = [
		'sm' => '15px',
		'md' => '16px',
		'lg' => '18px',
	];

	/**
	 * Resolved design.
	 *
	 * @var array<string, mixed>
	 */
	private array $design;

	/**
	 * Dark-mode overrides from the preset (only when the page did not override colours).
	 *
	 * @var array<string, mixed>
	 */
	private array $dark = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $design Page design object.
	 */
	public function __construct( array $design ) {
		$preset = Themes::get( (string) ( $design['theme'] ?? 'default' ) );
		$base   = [
			'layout'    => 'list',
			'dark_mode' => 'auto',
		];
		$dark   = (array) ( $preset['dark'] ?? [] );
		unset( $preset['dark'], $preset['label'], $preset['group'] );
		$this->design = self::merge( self::merge( $base, $preset ), $design );

		$overrides_colour = isset( $design['background'] ) || isset( $design['text']['color'] ) || isset( $design['button']['color'] );
		if ( $dark && ! $overrides_colour ) {
			if ( 'dark' === $this->design['dark_mode'] ) {
				$this->design = self::merge( $this->design, $dark );
			} elseif ( 'auto' === $this->design['dark_mode'] ) {
				$this->dark = self::merge( $this->design, $dark );
			}
		}
	}

	/**
	 * Recursive merge where $b wins.
	 *
	 * @param array<string, mixed> $a Base.
	 * @param array<string, mixed> $b Overrides.
	 * @return array<string, mixed>
	 */
	private static function merge( array $a, array $b ): array {
		foreach ( $b as $k => $v ) {
			$a[ $k ] = ( is_array( $v ) && isset( $a[ $k ] ) && is_array( $a[ $k ] ) ) ? self::merge( $a[ $k ], $v ) : $v;
		}
		return $a;
	}

	/**
	 * A resolved value by dot path.
	 *
	 * @param string $path    e.g. "button.shape".
	 * @param mixed  $fallback Default.
	 * @return mixed
	 */
	public function get( string $path, $fallback = null ) {
		$node = $this->design;
		foreach ( explode( '.', $path ) as $part ) {
			if ( ! is_array( $node ) || ! array_key_exists( $part, $node ) ) {
				return $fallback;
			}
			$node = $node[ $part ];
		}
		return $node;
	}

	/**
	 * Inline style value for the .cl-page root.
	 */
	public function style(): string {
		$vars = $this->vars( $this->design, '--cl-' );
		if ( $this->dark ) {
			$dark = array_intersect_key( $this->vars( $this->dark, '--cl-d-' ), array_flip( [ '--cl-d-bg', '--cl-d-text', '--cl-d-muted', '--cl-d-btn', '--cl-d-btn-text', '--cl-d-accent', '--cl-d-accent-ink' ] ) );
			$vars = array_merge( $vars, $dark );
		}
		$out = '';
		foreach ( $vars as $name => $value ) {
			$out .= $name . ':' . $value . ';';
		}
		return $out;
	}

	/**
	 * Modifier classes for the .cl-page root.
	 *
	 * @return string[]
	 */
	public function classes(): array {
		return [
			'cl-layout-' . sanitize_html_class( (string) $this->get( 'layout', 'list' ) ),
			'cl-shape-' . sanitize_html_class( (string) $this->get( 'button.shape', 'rounded' ) ),
			'cl-fill-' . sanitize_html_class( (string) $this->get( 'button.fill', 'solid' ) ),
			'cl-shadow-' . sanitize_html_class( (string) $this->get( 'button.shadow', 'none' ) ),
			'cl-hover-' . sanitize_html_class( (string) $this->get( 'button.hover', 'lift' ) ),
			$this->dark ? 'cl-scheme-auto' : 'cl-scheme-fixed',
		];
	}

	/**
	 * Resolved page text colour (light scheme), for section contrast.
	 */
	public function text_color(): string {
		$c = $this->get( 'text.color', '#111111' );
		return is_string( $c ) && null !== Contrast::rgb( $c ) ? $c : '#111111';
	}

	/**
	 * Colours the page background can take (light scheme), for section contrast.
	 *
	 * @return string[]
	 */
	public function background_samples(): array {
		return Contrast::background_samples( (array) $this->get( 'background', [] ) );
	}

	/**
	 * The first background colour (for the theme-color meta tag).
	 */
	public function theme_color(): string {
		$bg = (array) $this->get( 'background', [] );
		return (string) ( 'gradient' === ( $bg['type'] ?? '' ) ? ( $bg['from'] ?? '#ffffff' ) : ( $bg['color'] ?? '#ffffff' ) );
	}

	/**
	 * Custom CSS (already stripped of `</` at save time).
	 */
	public function custom_css(): string {
		return str_replace( '</', '<\/', (string) $this->get( 'custom_css', '' ) );
	}

	/**
	 * CSS-only pattern layers over a base colour. $ink defaults to a faint tint of the text colour, which
	 * resolves on .cl-page and so follows the dark scheme. The noise tile is an inline SVG data URI, not a request.
	 *
	 * @param string $pattern Pattern slug.
	 * @param int    $size    Tile size in px.
	 * @param string $ink     Validated hex colour or ''.
	 * @param string $base    Validated hex base colour.
	 */
	private static function pattern( string $pattern, int $size, string $ink, string $base ): string {
		$ink = '' !== $ink ? $ink : 'color-mix(in srgb,var(--cl-text) 14%,transparent)';
		$s   = $size . 'px';
		$s2  = ( $size * 2 ) . 'px';
		switch ( $pattern ) {
			case 'grid':
				$layers = "linear-gradient($ink 1px,transparent 1px) 0 0/$s $s,linear-gradient(90deg,$ink 1px,transparent 1px) 0 0/$s $s";
				break;
			case 'stripes':
				$layers = "repeating-linear-gradient(45deg,transparent 0,transparent $s,$ink $s,$ink $s2)";
				break;
			case 'lines':
				$layers = "repeating-linear-gradient(180deg,transparent 0,transparent calc($s - 2px),$ink calc($s - 2px),$ink $s)";
				break;
			case 'checks':
				$layers = "conic-gradient($ink 25%,transparent 0 50%,$ink 0 75%,transparent 0) 0 0/$s2 $s2";
				break;
			case 'noise':
				$layers = 'url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%27160%27 height=%27160%27%3E%3Cfilter id=%27n%27%3E%3CfeTurbulence type=%27fractalNoise%27 baseFrequency=%27.8%27 numOctaves=%272%27 stitchTiles=%27stitch%27/%3E%3CfeColorMatrix values=%270 0 0 0 .5 0 0 0 0 .5 0 0 0 0 .5 0 0 0 .22 0%27/%3E%3C/filter%3E%3Crect width=%27160%27 height=%27160%27 filter=%27url(%23n)%27/%3E%3C/svg%3E") 0 0/160px 160px';
				break;
			default:
				$layers = "radial-gradient(circle,$ink 1.5px,transparent 1.5px) 0 0/$s $s";
		}
		return $layers . ',' . $base;
	}

	/**
	 * CSS `background` value for a background token (page design or a section). Values come from
	 * schema-validated hex colours, enums and ints; image URLs are escaped.
	 *
	 * @param array<string, mixed> $bg Background object.
	 */
	public static function background_css( array $bg ): string {
		$hex = static fn( $v, string $fallback ): string => ( is_string( $v ) && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $v ) ) ? $v : $fallback;
		switch ( $bg['type'] ?? 'solid' ) {
			case 'gradient':
				$from       = $hex( $bg['from'] ?? null, '#ffffff' );
				$to         = $hex( $bg['to'] ?? null, '#eeeeee' );
				$background = ! empty( $bg['radial'] )
					? sprintf( 'radial-gradient(circle at top, %s, %s)', $from, $to )
					: sprintf( 'linear-gradient(%ddeg, %s, %s)', (int) ( $bg['angle'] ?? 180 ), $from, $to );
				break;
			case 'image':
				$url        = ! empty( $bg['image'] ) ? wp_get_attachment_image_url( (int) $bg['image'], 'full' ) : '';
				$color      = $hex( $bg['color'] ?? null, '#111111' );
				$overlay    = $hex( $bg['overlay'] ?? null, '' );
				$layers     = [];
				$layers[]   = '' !== $overlay ? sprintf( 'linear-gradient(%1$s, %1$s)', $overlay ) : '';
				$layers[]   = $url ? 'url("' . esc_url( $url ) . '") center / cover no-repeat' : '';
				$layers     = array_filter( $layers );
				$background = ( $layers ? implode( ', ', $layers ) . ', ' : '' ) . $color;
				break;
			case 'pattern':
				$background = self::pattern(
					(string) ( $bg['pattern'] ?? 'dots' ),
					max( 8, min( 80, (int) ( $bg['pattern_size'] ?? 24 ) ) ),
					$hex( $bg['pattern_color'] ?? null, '' ),
					$hex( $bg['color'] ?? null, '#ffffff' )
				);
				break;
			default:
				$background = $hex( $bg['color'] ?? null, '#ffffff' );
		}
		return $background;
	}

	/**
	 * Builds the CSS variable map. Values come from schema-validated hex colours, enums and ints.
	 *
	 * @param array<string, mixed> $d      Design.
	 * @param string               $prefix Variable prefix.
	 * @return array<string, string>
	 */
	private function vars( array $d, string $prefix ): array {
		$hex = static fn( $v, string $fallback ): string => ( is_string( $v ) && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $v ) ) ? $v : $fallback;
		$bg  = (array) ( $d['background'] ?? [] );
		$txt = (array) ( $d['text'] ?? [] );
		$btn = (array) ( $d['button'] ?? [] );

		$background = self::background_css( $bg );

		$accent = $hex( $d['accent'] ?? null, '#4f46e5' );
		$font   = self::FONTS[ $txt['font'] ?? 'system-sans' ] ?? self::FONTS['system-sans'];
		return [
			$prefix . 'bg'         => $background,
			$prefix . 'text'       => $hex( $txt['color'] ?? null, '#111111' ),
			$prefix . 'muted'      => $hex( $txt['muted'] ?? ( $txt['color'] ?? null ), '#555555' ),
			$prefix . 'font'       => $font,
			$prefix . 'size'       => self::SIZES[ $txt['size'] ?? 'md' ] ?? '16px',
			$prefix . 'btn'        => $hex( $btn['color'] ?? null, '#111111' ),
			$prefix . 'btn-text'   => $hex( $btn['text_color'] ?? null, '#ffffff' ),
			$prefix . 'btn-border' => max( 0, min( 8, (int) ( $btn['border_width'] ?? 0 ) ) ) . 'px',
			$prefix . 'accent'     => $accent,
			$prefix . 'accent-ink' => Contrast::ink_for( $accent ),
			$prefix . 'radius'     => max( 0, min( 48, (int) ( $d['radius'] ?? 14 ) ) ) . 'px',
		];
	}
}
