<?php
/**
 * CSV output helpers.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Analytics;

defined( 'ABSPATH' ) || exit;

/**
 * Builds CSV lines. Text cells that begin with = + - @ (or a tab or carriage return) get a leading
 * apostrophe so spreadsheets do not run them as formulas.
 */
final class Csv {

	public const SUBSCRIBER_HEADER = [ 'email', 'status', 'page_id', 'page', 'block_id', 'consent_text', 'consent_at', 'confirmed_at', 'created_at' ];

	/**
	 * One safe cell value.
	 *
	 * @param mixed $value Value.
	 */
	public static function cell( $value ): string {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$text = (string) $value;
		if ( '' !== $text && in_array( $text[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			$text = "'" . $text;
		}
		return $text;
	}

	/**
	 * One CSV line (RFC 4180 quoting, CRLF).
	 *
	 * @param array<int, mixed> $cells Cells.
	 */
	public static function line( array $cells ): string {
		$out = [];
		foreach ( $cells as $cell ) {
			$text  = self::cell( $cell );
			$out[] = ( '' !== $text && preg_match( '/[",\r\n]/', $text ) ) ? '"' . str_replace( '"', '""', $text ) . '"' : $text;
		}
		return implode( ',', $out ) . "\r\n";
	}

	/**
	 * A subscriber export row matching SUBSCRIBER_HEADER.
	 *
	 * @param array<string, mixed> $row Subscriber row.
	 * @return array<int, mixed>
	 */
	public static function subscriber_row( array $row ): array {
		return [
			(string) $row['email'],
			(string) $row['status'],
			(int) $row['page_id'],
			wp_specialchars_decode( get_the_title( (int) $row['page_id'] ), ENT_QUOTES ),
			(string) $row['block_id'],
			(string) $row['consent_text'],
			(string) $row['consent_at'],
			(string) $row['confirmed_at'],
			(string) $row['created_at'],
		];
	}

	/**
	 * Starts a CSV download response (headers only).
	 *
	 * @param string $filename File name.
	 */
	public static function send_headers( string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel reads accents correctly.
	}
}
