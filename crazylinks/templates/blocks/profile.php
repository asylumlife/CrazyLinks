<?php
/**
 * Profile header.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed>             $profile
 * @var \CrazyLinks\Render\RenderContext $ctx
 * @var \CrazyLinks\Render\Renderer      $renderer
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_layout = (string) ( $profile['header_layout'] ?? 'centered' );
$crazylinks_style  = (string) ( $profile['avatar_style'] ?? 'circle' );
$crazylinks_name   = (string) ( $profile['name'] ?? '' );
$crazylinks_tag    = $ctx->title_tag();
// Nothing to show: stop before an image is rendered, because the first image rendered on a page is
// marked as its eager, high-priority one.
$crazylinks_may_have_avatar = ! empty( $profile['avatar'] ) && wp_attachment_is_image( (int) $profile['avatar'] );
if ( '' === $crazylinks_name && ! $crazylinks_may_have_avatar && empty( $profile['bio'] ) ) {
	return;
}
$crazylinks_focal        = \CrazyLinks\Render\Renderer::focal_style( $profile['banner_focal_point'] ?? null );
$crazylinks_banner       = in_array( $crazylinks_layout, [ 'banner', 'hero' ], true ) && ! empty( $profile['banner'] )
	? $renderer->image(
		(int) $profile['banner'],
		'cl-banner',
		$ctx,
		[
			'class' => 'cl-profile__banner',
			'alt'   => '',
		] + ( '' !== $crazylinks_focal ? [ 'style' => $crazylinks_focal ] : [] )
	)
	: '';
$crazylinks_avatar_class = 'cl-avatar cl-avatar--' . sanitize_html_class( $crazylinks_style );
if ( 'shape' === $crazylinks_style ) {
	$crazylinks_avatar_class .= ' cl-mask-' . sanitize_html_class( (string) ( $profile['shape_mask'] ?? 'squircle' ) );
}
$crazylinks_avatar = ! empty( $profile['avatar'] )
	? $renderer->image(
		(int) $profile['avatar'],
		'cl-avatar',
		$ctx,
		[
			'class' => $crazylinks_avatar_class,
			'alt'   => $crazylinks_name,
		]
	)
	: '';

if ( '' === $crazylinks_name && '' === $crazylinks_avatar && empty( $profile['bio'] ) ) {
	return;
}
?>
<header class="cl-profile cl-profile--<?php echo esc_attr( sanitize_html_class( $crazylinks_layout ) ); ?>">
	<?php echo $crazylinks_banner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?>
	<?php echo $crazylinks_avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image output. ?>
	<?php if ( '' !== $crazylinks_name ) : ?>
		<<?php echo tag_escape( $crazylinks_tag ); ?> class="cl-profile__name">
			<?php echo esc_html( $crazylinks_name ); ?>
			<?php
			if ( ! empty( $profile['verified_badge'] ) ) {
				echo \CrazyLinks\Render\Icons::verified(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static SVG.
			}
			?>
		</<?php echo tag_escape( $crazylinks_tag ); ?>>
	<?php endif; ?>
	<?php if ( ! empty( $profile['pronouns'] ) || ! empty( $profile['location'] ) ) : ?>
		<p class="cl-profile__meta">
			<?php echo esc_html( implode( ' · ', array_filter( [ (string) ( $profile['pronouns'] ?? '' ), (string) ( $profile['location'] ?? '' ) ] ) ) ); ?>
		</p>
	<?php endif; ?>
	<?php if ( ! empty( $profile['bio'] ) ) : ?>
		<p class="cl-profile__bio"><?php echo nl2br( esc_html( (string) $profile['bio'] ) ); ?></p>
	<?php endif; ?>
</header>
