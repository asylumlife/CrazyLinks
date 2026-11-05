<?php
/**
 * Custom HTML. The renderer only reaches this partial when the page author has unfiltered_html.
 *
 * @package CrazyLinks
 *
 * @var array<string, mixed> $props
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="cl-html">' . (string) ( $props['html'] ?? '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Author has unfiltered_html (checked in Renderer).
