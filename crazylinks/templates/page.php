<?php
/**
 * The .cl-page root shared by every rendering mode.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>               $doc
 * @var \CrazyLinks\Render\Tokens          $tokens
 * @var \CrazyLinks\Render\RenderContext   $ctx
 * @var array<string, mixed>               $profile
 * @var string                             $body     Pre-escaped block HTML.
 * @var \CrazyLinks\Render\Renderer        $renderer
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_classes = array_merge( [ 'cl-page' ], $tokens->classes() );
?>
<div class="<?php echo esc_attr( implode( ' ', $crazylinks_classes ) ); ?>" id="cl-page-<?php echo esc_attr( (string) $ctx->post_id ); ?>" style="<?php echo esc_attr( $tokens->style() ); ?>">
	<?php if ( ! empty( $ctx->settings['in_app_hint'] ) ) : ?>
		<div class="cl-inapp" data-cl-inapp hidden>
			<p><?php esc_html_e( 'For the best experience, open this page in your browser.', 'crazylinks' ); ?></p>
			<button type="button" class="cl-inapp__close" data-cl-dismiss><?php esc_html_e( 'Dismiss', 'crazylinks' ); ?></button>
		</div>
	<?php endif; ?>
	<div class="cl-inner">
		<?php
		$crazylinks_profile = $renderer->partial(
			'blocks/profile',
			[
				'profile' => $profile,
				'ctx'     => $ctx,
			]
		);
		echo $crazylinks_profile; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Partial escapes its own output.
		?>
		<div class="cl-blocks">
			<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block partials escape their own output. ?>
		</div>
		<?php if ( ! empty( $ctx->settings['footer_credit'] ) ) : ?>
			<?php
			/**
			 * Filters the footer credit text ("Made with CrazyLinks"). Shown only when the page's
			 * `settings.footer_credit` is on.
			 *
			 * @param string $text Link text.
			 */
			$crazylinks_credit_text = (string) apply_filters( 'crazylinks_footer_credit_text', __( 'Made with CrazyLinks', 'crazylinks' ) );
			/**
			 * Filters the footer credit URL.
			 *
			 * @param string $url Link target. Default: the plugin's wordpress.org page.
			 */
			$crazylinks_credit_url = (string) apply_filters( 'crazylinks_footer_credit_url', 'https://wordpress.org/plugins/crazylinks/' );
			?>
			<?php if ( '' !== $crazylinks_credit_text && '' !== $crazylinks_credit_url ) : ?>
				<p class="cl-credit"><a href="<?php echo esc_url( $crazylinks_credit_url, [ 'http', 'https' ] ); ?>" rel="nofollow noopener"><?php echo esc_html( $crazylinks_credit_text ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>
