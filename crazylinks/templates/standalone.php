<?php
/**
 * Standalone link page document: no theme header, footer, CSS or JS (SPEC §6.5).
 * wp_head()/wp_footer() stay for SEO and analytics plugins; their assets are filtered out.
 *
 * @package CrazyLinks
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_post = \CrazyLinks\Frontend\TemplateLoader::current();
if ( ! $crazylinks_post ) {
	return;
}
// Render first so image sizes and schedule state are known before <head> is printed.
$crazylinks_html = \CrazyLinks\Frontend\TemplateLoader::page_html( 'standalone' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<?php \CrazyLinks\Frontend\TemplateLoader::head_meta( $crazylinks_post ); ?>
<?php wp_head(); ?>
<?php echo \CrazyLinks\Frontend\Assets::style_tag( \CrazyLinks\Frontend\TemplateLoader::custom_css() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin CSS; custom CSS gated by unfiltered_html. ?>
</head>
<body <?php body_class( 'cl-body' ); ?>>
<?php wp_body_open(); ?>
<main id="cl-main" class="cl-main">
<?php echo $crazylinks_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes its output. ?>
</main>
<?php wp_footer(); ?>
<?php echo \CrazyLinks\Frontend\Assets::script_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plugin JS. ?>
</body>
</html>
