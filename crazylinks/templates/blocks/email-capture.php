<?php
/**
 * Email capture form. Works without JavaScript (plain POST, 303 back to the page with a message
 * revealed through :target); frontend.js upgrades it to fetch with an inline message.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $block
 * @var array<string, mixed>             $props
 * @var \CrazyLinks\Render\RenderContext $ctx
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_uid     = 'cl-ec-' . (string) $block['id'];
$crazylinks_consent = (string) ( $props['consent_text'] ?? '' );
// The block's own wording replaces the site defaults for the pending (double opt-in) and success messages.
// A block routed to Mailchimp defaults to "Check your email to confirm your subscription." (Mailchimp sends it).
$crazylinks_custom = [
	'pending'   => trim( (string) ( $props['pending_text'] ?? '' ) ),
	'confirmed' => trim( (string) ( $props['success_text'] ?? '' ) ),
	'done'      => trim( (string) ( $props['success_text'] ?? '' ) ),
];
?>
<form class="cl-capture" method="post" action="<?php echo esc_url( wp_make_link_relative( rest_url( 'crazylinks/v1/subscribe' ) ) ); ?>" data-cl-capture data-cl-uid="<?php echo esc_attr( $crazylinks_uid ); ?>">
	<?php if ( ! empty( $props['heading'] ) ) : ?>
		<p class="cl-capture__heading" id="<?php echo esc_attr( $crazylinks_uid ); ?>-h"><?php echo esc_html( (string) $props['heading'] ); ?></p>
	<?php endif; ?>
	<?php foreach ( \CrazyLinks\Subscribers\Service::messages_for( $props ) as $crazylinks_key => $crazylinks_msg ) : ?>
		<p class="cl-capture__msg" id="<?php echo esc_attr( $crazylinks_uid . '-m-' . $crazylinks_key ); ?>" role="status" tabindex="-1"><?php echo esc_html( '' !== ( $crazylinks_custom[ $crazylinks_key ] ?? '' ) ? $crazylinks_custom[ $crazylinks_key ] : $crazylinks_msg ); ?></p>
	<?php endforeach; ?>
	<input type="hidden" name="page_id" value="<?php echo esc_attr( (string) $ctx->post_id ); ?>">
	<input type="hidden" name="block_id" value="<?php echo esc_attr( (string) $block['id'] ); ?>">
	<div class="cl-hp" aria-hidden="true">
		<label for="<?php echo esc_attr( $crazylinks_uid ); ?>-w"><?php esc_html_e( 'Leave this field empty', 'crazylinks' ); ?></label>
		<input type="text" id="<?php echo esc_attr( $crazylinks_uid ); ?>-w" name="website" tabindex="-1" autocomplete="off">
	</div>
	<div class="cl-capture__row">
		<label class="cl-sr" for="<?php echo esc_attr( $crazylinks_uid ); ?>-e"><?php esc_html_e( 'Email address', 'crazylinks' ); ?></label>
		<input class="cl-capture__input" type="email" id="<?php echo esc_attr( $crazylinks_uid ); ?>-e" name="email" required autocomplete="email" inputmode="email" placeholder="<?php echo esc_attr( (string) ( $props['placeholder'] ?? __( 'you@example.com', 'crazylinks' ) ) ); ?>">
		<button class="cl-capture__button" type="submit"><?php echo esc_html( (string) ( $props['button_text'] ?? __( 'Subscribe', 'crazylinks' ) ) ); ?></button>
	</div>
	<?php if ( '' !== $crazylinks_consent ) : ?>
		<p class="cl-capture__consent">
			<input type="checkbox" id="<?php echo esc_attr( $crazylinks_uid ); ?>-c" name="consent" value="1" required>
			<label for="<?php echo esc_attr( $crazylinks_uid ); ?>-c"><?php echo esc_html( $crazylinks_consent ); ?></label>
		</p>
	<?php endif; ?>
</form>
