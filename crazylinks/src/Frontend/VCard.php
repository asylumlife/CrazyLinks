<?php
/**
 * The vCard download for contact blocks.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Frontend;

use CrazyLinks\PageStore;

defined( 'ABSPATH' ) || exit;

/**
 * Serves `/{base}/{slug}/vcard/`: a vCard 3.0 file built from the first reachable `contact` block
 * with `props.vcard` set. Uses only what the page already shows publicly (profile name, phone,
 * email, address, page URL) and applies the same visibility, schedule and password checks as /go/.
 */
final class VCard {

	/**
	 * Sends the file (or a 404) and exits.
	 *
	 * @param mixed $post Queried object.
	 */
	public function handle( $post ): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache constant.
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$result = $this->resolve( $post );
		if ( 200 !== $result['status'] ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return; // WordPress renders the theme's 404 template.
		}
		status_header( 200 );
		header( 'Content-Type: text/vcard; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/vcard body, escaped per RFC 6350 in build().
		exit;
	}

	/**
	 * Decides the response.
	 *
	 * @param mixed $post Page.
	 * @return array{status:int, body:string, filename:string}
	 */
	public function resolve( $post ): array {
		$block = PageStore::reachable_block( $post, '', [ 'contact' ], static fn( array $b ): bool => ! empty( $b['props']['vcard'] ) );
		if ( ! $block || ! $post instanceof \WP_Post ) {
			return [
				'status'   => 404,
				'body'     => '',
				'filename' => '',
			];
		}
		$doc  = PageStore::get_document( $post ) ?? [];
		$name = trim( (string) ( $doc['profile']['name'] ?? '' ) );
		$body = self::build( (array) ( $block['props'] ?? [] ), '' !== $name ? $name : (string) $post->post_name, (string) get_permalink( $post ) );
		return [
			'status'   => 200,
			'body'     => $body,
			'filename' => sanitize_file_name( ( '' !== $post->post_name ? $post->post_name : 'contact' ) . '.vcf' ),
		];
	}

	/**
	 * Builds the vCard text.
	 *
	 * @param array<string, mixed> $props Contact block props.
	 * @param string               $name  Display name.
	 * @param string               $url   Page URL.
	 */
	public static function build( array $props, string $name, string $url ): string {
		$lines = [
			'BEGIN:VCARD',
			'VERSION:3.0',
			'FN:' . self::escape( $name ),
			'N:' . self::escape( $name ) . ';;;;',
		];
		$phone = (string) preg_replace( '/[^0-9+]/', '', (string) ( $props['phone'] ?? '' ) );
		if ( '' !== $phone ) {
			$lines[] = 'TEL;TYPE=VOICE:' . $phone;
		}
		$email = sanitize_email( (string) ( $props['email'] ?? '' ) );
		if ( '' !== $email ) {
			$lines[] = 'EMAIL;TYPE=INTERNET:' . self::escape( $email );
		}
		$address = trim( (string) ( $props['address'] ?? '' ) );
		if ( '' !== $address ) {
			$lines[] = 'ADR:;;' . self::escape( $address ) . ';;;;';
		}
		if ( '' !== $url ) {
			$lines[] = 'URL:' . self::escape( esc_url_raw( $url ) );
		}
		$lines[] = 'END:VCARD';
		return implode( "\r\n", array_map( [ self::class, 'fold' ], $lines ) ) . "\r\n";
	}

	/**
	 * Escapes a text value (RFC 6350 §3.4) and strips control characters.
	 *
	 * @param string $value Value.
	 */
	private static function escape( string $value ): string {
		$value = (string) preg_replace( '/\R/u', ' ', wp_check_invalid_utf8( $value ) );
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
		return str_replace( [ '\\', ';', ',' ], [ '\\\\', '\;', '\,' ], $value );
	}

	/**
	 * Folds a content line at 75 octets without splitting UTF-8 characters.
	 *
	 * @param string $line Line.
	 */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$chunk = '';
		$limit = 75;
		foreach ( (array) preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			$char = (string) $char;
			if ( strlen( $chunk ) + strlen( $char ) > $limit ) {
				$out  .= $chunk . "\r\n ";
				$chunk = '';
				$limit = 74; // Continuation lines start with a space.
			}
			$chunk .= $char;
		}
		return $out . $chunk;
	}
}
