<?php
/**
 * "Inside my theme" mode: the link page wrapped in the active theme's header and footer.
 *
 * @package CrazyLinks
 */

defined( 'ABSPATH' ) || exit;

$crazylinks_post = \CrazyLinks\Frontend\TemplateLoader::current();
if ( ! $crazylinks_post ) {
	return;
}
$crazylinks_html = \CrazyLinks\Frontend\TemplateLoader::page_html( 'theme' );
$crazylinks_page = \CrazyLinks\Frontend\Assets::style_tag( \CrazyLinks\Frontend\TemplateLoader::custom_css() ) . $crazylinks_html . \CrazyLinks\Frontend\Assets::script_tag();

if ( wp_is_block_theme() ) {
	// Render template parts before wp_head() so their block styles are enqueued in time (as core's template canvas does).
	$crazylinks_header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
	$crazylinks_footer = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php \CrazyLinks\Frontend\TemplateLoader::head_meta( $crazylinks_post ); ?>
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'cl-body-theme' ); ?>>
	<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo $crazylinks_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core block output. ?>
<main id="cl-main" class="cl-main cl-main--theme">
	<?php echo $crazylinks_page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes its output. ?>
</main>
	<?php echo $crazylinks_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core block output. ?>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
	return;
}

add_action( 'wp_head', static fn() => \CrazyLinks\Frontend\TemplateLoader::head_meta( $crazylinks_post ), 2 );
get_header();
?>
<main id="cl-main" class="cl-main cl-main--theme">
<?php echo $crazylinks_page; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes its output. ?>
</main>
<?php
get_footer();
