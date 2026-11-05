<?php
/**
 * ULID generator.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Generates 26-character Crockford base32 ULIDs.
 */
final class Ulid {

	private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * New ULID.
	 */
	public static function generate(): string {
		$time = (int) floor( microtime( true ) * 1000 );
		$out  = '';
		for ( $i = 9; $i >= 0; $i-- ) {
			$out  = self::ALPHABET[ $time % 32 ] . $out;
			$time = intdiv( $time, 32 );
		}
		$bytes = random_bytes( 16 );
		for ( $i = 0; $i < 16; $i++ ) {
			$out .= self::ALPHABET[ ord( $bytes[ $i ] ) % 32 ];
		}
		return $out;
	}

	/**
	 * Whether a string is a ULID.
	 *
	 * @param string $id Candidate.
	 */
	public static function is_valid( string $id ): bool {
		return (bool) preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $id );
	}
}
