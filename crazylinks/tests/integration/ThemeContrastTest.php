<?php
/**
 * Theme presets meet WCAG AA.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Render\Contrast;
use CrazyLinks\Render\Themes;
use CrazyLinks\Render\Tokens;
use CrazyLinks\Schema\PageValidator;

/**
 * Asserts the contrast of every text/background pair in every preset and its dark variant.
 */
class ThemeContrastTest extends TestCase {

	public function test_ratio_matches_known_values(): void {
		$this->assertEqualsWithDelta( 21.0, Contrast::ratio( '#000000', '#ffffff' ), 0.01 );
		$this->assertEqualsWithDelta( 1.0, Contrast::ratio( '#777777', '#777777' ), 0.001 );
		$this->assertEqualsWithDelta( 4.48, Contrast::ratio( '#777777', '#ffffff' ), 0.02 );
		$this->assertEqualsWithDelta( 21.0, Contrast::ratio( '#000', '#fff' ), 0.01 );
		$this->assertSame( '#ffffff', Contrast::ink_for( '#1d4ed8' ) );
		$this->assertSame( '#000000', Contrast::ink_for( '#fde68a' ) );
	}

	public function test_there_are_at_least_twelve_presets(): void {
		$this->assertGreaterThanOrEqual( 12, count( Themes::all() ) );
	}

	/**
	 * Preset slugs.
	 *
	 * @return array<string, array{string}>
	 */
	public function theme_slugs(): array {
		$out = [];
		foreach ( array_keys( Themes::all() ) as $slug ) {
			$out[ $slug ] = [ $slug ];
		}
		return $out;
	}

	/**
	 * @dataProvider theme_slugs
	 * @param string $slug Preset.
	 */
	public function test_light_variant_meets_aa( string $slug ): void {
		$preset = Themes::get( $slug );
		unset( $preset['dark'] );
		$this->assert_pairs( $slug, $preset );
	}

	/**
	 * @dataProvider theme_slugs
	 * @param string $slug Preset.
	 */
	public function test_dark_variant_meets_aa( string $slug ): void {
		$preset = Themes::get( $slug );
		if ( empty( $preset['dark'] ) ) {
			$this->assertTrue( true );
			return;
		}
		$dark = $preset['dark'];
		unset( $preset['dark'] );
		$this->assert_pairs( $slug . ' (dark)', array_replace_recursive( $preset, $dark ) );
	}

	/**
	 * @dataProvider theme_slugs
	 * @param string $slug Preset.
	 */
	public function test_preset_validates_and_resolves( string $slug ): void {
		$doc           = $this->doc();
		$doc['design'] = [ 'theme' => $slug ];
		$result        = ( new PageValidator() )->validate( $doc, 0 );
		$this->assertTrue( $result->is_valid(), wp_json_encode( $result->errors() ) );
		$tokens = new Tokens( [ 'theme' => $slug ] );
		$this->assertStringContainsString( '--cl-accent-ink:', $tokens->style() );
		$preset = Themes::get( $slug );
		$this->assertNotEmpty( $preset['label'] );
		$this->assertNotEmpty( $preset['group'] );
	}

	/**
	 * Asserts every pair of a resolved design.
	 *
	 * @param string               $name   Label.
	 * @param array<string, mixed> $design Resolved design.
	 */
	private function assert_pairs( string $name, array $design ): void {
		foreach ( Contrast::pairs( $design ) as $pair ) {
			$ratio = Contrast::ratio( $pair['fg'], $pair['bg'] );
			$this->assertGreaterThanOrEqual(
				$pair['min'],
				$ratio,
				sprintf( '%s: %s %s on %s is %.2f:1', $name, $pair['label'], $pair['fg'], $pair['bg'], $ratio )
			);
		}
	}
}
