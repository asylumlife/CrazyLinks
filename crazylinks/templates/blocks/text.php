<?php
/**
 * Rich text (p, strong, em, a, br).
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed> $props
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_html = wp_kses( (string) ( $props['content'] ?? '' ), \CrazyLinks\Schema\PageValidator::TEXT_HTML, \CrazyLinks\Schema\UrlPolicy::LINK_SCHEMES );
if ( '' === trim( $crazylinks_html ) ) {
	return;
}
if ( ! str_contains( $crazylinks_html, '<p' ) ) {
	$crazylinks_html = '<p>' . $crazylinks_html . '</p>';
}
$crazylinks_align = in_array( $props['align'] ?? '', [ 'left', 'center', 'right' ], true ) ? $props['align'] : 'center';
?>
<div class="cl-text cl-align-<?php echo esc_attr( $crazylinks_align ); ?>"><?php echo $crazylinks_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses above. ?></div>
