<?php
/**
 * Vertical spacer.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed> $props
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_height = max( 4, min( 200, (int) ( $props['height'] ?? 24 ) ) );
?>
<div class="cl-spacer" style="height:<?php echo esc_attr( (string) $crazylinks_height ); ?>px" aria-hidden="true"></div>
