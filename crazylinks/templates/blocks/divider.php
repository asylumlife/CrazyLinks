<?php
/**
 * Divider.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed> $props
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_style = in_array( $props['style'] ?? '', [ 'line', 'space', 'dots' ], true ) ? $props['style'] : 'line';
?>
<hr class="cl-divider cl-divider--<?php echo esc_attr( $crazylinks_style ); ?>">
