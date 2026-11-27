<?php
/**
 * Divider.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed> $props
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_style = \CrazyLinks\Render\Renderer::choice( $props, 'style', [ 'line', 'space', 'dots' ], 'line' );
?>
<hr class="cl-divider cl-divider--<?php echo esc_attr( $crazylinks_style ); ?>">
