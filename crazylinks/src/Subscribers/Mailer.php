<?php
/**
 * Subscriber emails.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Subscribers;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text emails sent with wp_mail(). Every message carries an unsubscribe link.
 */
final class Mailer {

	/**
	 * Confirmation / unsubscribe link for a token.
	 *
	 * @param string $action confirm | unsubscribe.
	 * @param string $token  Token.
	 */
	public static function link( string $action, string $token ): string {
		return add_query_arg( 'token', $token, rest_url( 'crazylinks/v1/subscribe/' . $action ) );
	}

	/**
	 * Sends the double opt-in confirmation.
	 *
	 * @param array<string, mixed> $row   Subscriber row.
	 * @param string               $token Fresh token.
	 */
	public static function send_confirmation( array $row, string $token ): bool {
		$site = self::site_name();
		$body = sprintf(
			/* translators: 1: page name, 2: site name. */
			__( 'Someone, hopefully you, asked to receive email from %1$s on %2$s.', 'crazylinks' ),
			self::page_name( (int) $row['page_id'] ),
			$site
		) . "\n\n"
			. __( 'Please confirm your address by opening this link (it works for 7 days):', 'crazylinks' ) . "\n"
			. self::link( 'confirm', $token ) . "\n\n"
			. __( 'If this was not you, ignore this message and you will not hear from us again.', 'crazylinks' )
			. self::footer( $row, $token );
		/* translators: %s: site name. */
		return self::send( $row, sprintf( __( 'Please confirm your subscription to %s', 'crazylinks' ), $site ), $body, $token );
	}

	/**
	 * Sends the welcome message used when double opt-in is off.
	 *
	 * @param array<string, mixed> $row   Subscriber row.
	 * @param string               $token Token.
	 */
	public static function send_welcome( array $row, string $token ): bool {
		$site = self::site_name();
		$body = sprintf(
			/* translators: 1: page name, 2: site name. */
			__( 'You are subscribed to updates from %1$s on %2$s.', 'crazylinks' ),
			self::page_name( (int) $row['page_id'] ),
			$site
		) . self::footer( $row, $token );
		/* translators: %s: site name. */
		return self::send( $row, sprintf( __( 'You are subscribed to %s', 'crazylinks' ), $site ), $body, $token );
	}

	/**
	 * Shared footer with the unsubscribe link and the consent record.
	 *
	 * @param array<string, mixed> $row   Subscriber row.
	 * @param string               $token Token.
	 */
	private static function footer( array $row, string $token ): string {
		$out = "\n\n--\n" . __( 'Unsubscribe at any time:', 'crazylinks' ) . "\n" . self::link( 'unsubscribe', $token );
		if ( '' !== (string) $row['consent_text'] ) {
			/* translators: %s: the consent wording the visitor agreed to. */
			$out .= "\n\n" . sprintf( __( 'You agreed to: "%s"', 'crazylinks' ), (string) $row['consent_text'] );
		}
		return $out;
	}

	/**
	 * Sends through wp_mail().
	 *
	 * @param array<string, mixed> $row     Subscriber row.
	 * @param string               $subject Subject.
	 * @param string               $body    Plain text body.
	 * @param string               $token   Token (for the List-Unsubscribe header).
	 */
	private static function send( array $row, string $subject, string $body, string $token ): bool {
		$headers = [ 'List-Unsubscribe: <' . self::link( 'unsubscribe', $token ) . '>' ];
		return (bool) wp_mail( (string) $row['email'], $subject, $body, $headers );
	}

	/**
	 * Site name for subjects and bodies.
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}

	/**
	 * Page name for bodies.
	 *
	 * @param int $page_id Page id.
	 */
	private static function page_name( int $page_id ): string {
		$title = wp_specialchars_decode( get_the_title( $page_id ), ENT_QUOTES );
		return '' !== $title ? $title : self::site_name();
	}
}
