<?php
/**
 * Full-page rendering modes (standalone and inside the theme).
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Frontend;

use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\EventLogger;
use CrazyLinks\PageStore;
use CrazyLinks\PostType;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Render\Schedule;
use CrazyLinks\Render\Tokens;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses the template for singular link pages, isolates the standalone document from
 * theme and plugin assets (SPEC §6.5), prints SEO meta, and logs page views.
 */
final class TemplateLoader {

	/**
	 * The page being rendered.
	 *
	 * @var \WP_Post|null
	 */
	private static ?\WP_Post $post = null;

	/**
	 * Whether the current page is password protected and the visitor has not entered the password.
	 *
	 * @var bool
	 */
	private static bool $locked = false;

	/**
	 * The max-age sent for the current schedule-dependent page, or null (tests read it).
	 *
	 * @var int|null
	 */
	private static ?int $max_age = null;

	/**
	 * Hooks.
	 */
	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'template_redirect' ], 1 );
		add_filter( 'template_include', [ $this, 'template_include' ], 999 );
		( new Shortcode() )->register_hooks();
		( new PageBlock() )->register_hooks();
	}

	/**
	 * Effective mode for a page: per-page setting, else the site default.
	 *
	 * @param array<string, mixed> $doc Document.
	 */
	public static function mode_for( array $doc ): string {
		$mode = (string) ( $doc['settings']['render_mode'] ?? 'default' );
		if ( ! in_array( $mode, [ 'standalone', 'theme' ], true ) ) {
			$mode = (string) Settings::get( 'render_mode' );
		}
		return 'theme' === $mode ? 'theme' : 'standalone';
	}

	/**
	 * Handles /go/ redirects and view logging for singular link pages.
	 */
	public function template_redirect(): void {
		self::$post    = null;
		self::$locked  = false;
		self::$max_age = null;
		if ( ! is_singular( PostType::NAME ) ) {
			return; // A stray ?crazylinks_go= on any other URL is ignored (SPEC §12).
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$go = (string) get_query_var( Router::QV_GO );
		if ( '' !== $go ) {
			( new ClickRedirect() )->handle( $post, $go, (string) get_query_var( Router::QV_ITEM ) );
			return; // handle() exits; return only in tests.
		}
		self::$post   = $post;
		self::$locked = post_password_required( $post );
		$doc          = PageStore::get_document( $post ) ?? [];

		if ( self::$locked ) {
			// Only the password form is shown: no view is logged and nothing is cached (SPEC §12).
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache constant.
			}
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		} elseif ( ! is_preview() && 'publish' === $post->post_status ) {
			EventLogger::log_view( $post );
			$this->schedule_cache_policy( $post, $doc );
		}
		if ( 'standalone' === self::mode_for( $doc ) ) {
			$this->isolate();
		}
		if ( ! empty( $doc['settings']['seo']['noindex'] ) ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
		remove_action( 'wp_head', 'rel_canonical' ); // We print our own canonical in head_meta().
		add_filter( 'pre_get_document_title', [ $this, 'document_title' ], 20 );
		add_filter( 'body_class', static fn( $c ) => array_merge( (array) $c, [ 'crazylinks-page' ] ) );
	}

	/**
	 * Full-page caches (WP Super Cache, W3TC, LiteSpeed, Varnish, CDNs) would freeze a scheduled
	 * block in or out of its window. When any visible block or section has a schedule that can still
	 * change, the page opts out of the PHP page cache (DONOTCACHEPAGE) and tells browsers and CDNs to
	 * revalidate at the next boundary. Pages without schedules keep the default behaviour.
	 *
	 * @param \WP_Post             $post Page.
	 * @param array<string, mixed> $doc  Document.
	 */
	private function schedule_cache_policy( \WP_Post $post, array $doc ): void {
		$ttl = self::schedule_max_age( $doc, time() );
		if ( null === $ttl ) {
			return;
		}
		self::no_page_cache();
		self::$max_age = $ttl;
		if ( ! is_user_logged_in() && '' === (string) $post->post_password && ! headers_sent() ) {
			header( 'Cache-Control: public, max-age=' . $ttl );
		}
	}

	/**
	 * Seconds a schedule-dependent page may be cached (60 s to 24 h, until the next schedule
	 * boundary), or null when no visible block has a schedule that can still change.
	 *
	 * @param array<string, mixed> $doc Document.
	 * @param int                  $now Unix time.
	 */
	public static function schedule_max_age( array $doc, int $now ): ?int {
		return Schedule::page_ttl( $doc, $now, wp_timezone() );
	}

	/**
	 * Asks page-cache plugins not to store the current response.
	 */
	public static function no_page_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard page-cache constant.
		}
	}

	/**
	 * The max-age sent for the current page because of schedules, or null when none was sent.
	 */
	public static function schedule_cache_max_age(): ?int {
		return self::$max_age;
	}

	/**
	 * Whether the current page shows only the password form.
	 */
	public static function is_locked(): bool {
		return self::$locked;
	}

	/**
	 * Picks the plugin template.
	 *
	 * @param string $template Theme template.
	 */
	public function template_include( $template ): string {
		if ( ! self::$post ) {
			return (string) $template;
		}
		// Block themes queue their own title and viewport tags when a block template is located; we print ours.
		remove_action( 'wp_head', '_block_template_viewport_meta_tag', 0 );
		remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
		$doc = PageStore::get_document( self::$post ) ?? [];
		return CRAZYLINKS_DIR . 'templates/' . ( 'theme' === self::mode_for( $doc ) ? 'theme.php' : 'standalone.php' );
	}

	/**
	 * Removes theme/plugin assets and core extras that cause external requests.
	 */
	private function isolate(): void {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_head', 'wp_print_font_faces', 50 );
		remove_action( 'wp_head', 'wp_print_font_faces_from_style_variations', 50 );
		remove_action( 'wp_head', 'wp_custom_css_cb', 101 );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
		remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_classic_theme_styles' );
		add_filter( 'print_styles_array', [ $this, 'filter_handles' ], PHP_INT_MAX );
		add_filter( 'print_scripts_array', [ $this, 'filter_handles' ], PHP_INT_MAX );
		add_filter( 'should_load_separate_core_block_assets', '__return_true' );
	}

	/**
	 * Lets only allow-listed handles print on a standalone page.
	 *
	 * @param string[] $handles Handles about to print.
	 * @return string[]
	 */
	public function filter_handles( $handles ): array {
		$allowed = is_admin_bar_showing() ? [ 'admin-bar', 'dashicons', 'hoverintent-js' ] : [];
		/**
		 * Filters the style/script handles allowed on standalone link pages.
		 * Everything else (theme, builders, other plugins) is dropped.
		 *
		 * @param string[] $allowed Handles.
		 */
		$allowed = (array) apply_filters( 'crazylinks_standalone_allowed_handles', $allowed );
		return array_values( array_intersect( (array) $handles, $allowed ) );
	}

	/**
	 * Document title.
	 *
	 * @param string $title Title.
	 */
	public function document_title( $title ): string {
		if ( self::$post && self::$locked ) {
			return get_the_title( self::$post );
		}
		return self::$post ? self::seo_title( self::$post, PageStore::get_document( self::$post ) ?? [] ) : (string) $title;
	}

	/**
	 * SEO title.
	 *
	 * @param \WP_Post             $post Post.
	 * @param array<string, mixed> $doc  Document.
	 */
	public static function seo_title( \WP_Post $post, array $doc ): string {
		$title = (string) ( $doc['settings']['seo']['title'] ?? '' );
		if ( '' === $title ) {
			$title = (string) ( $doc['profile']['name'] ?? '' );
		}
		return '' !== $title ? $title : get_the_title( $post );
	}

	/**
	 * Prints title (when the theme lacks title-tag support), description, canonical, OG,
	 * theme-color and the avatar preload.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function head_meta( \WP_Post $post ): void {
		if ( post_password_required( $post ) ) {
			if ( ! current_theme_supports( 'title-tag' ) ) {
				echo '<title>' . esc_html( get_the_title( $post ) ) . "</title>\n";
			}
			return; // Nothing from the protected document leaks into <head>.
		}
		$doc    = PageStore::get_document( $post ) ?? [];
		$tokens = new Tokens( (array) ( $doc['design'] ?? [] ) );
		$title  = self::seo_title( $post, $doc );
		$desc   = (string) ( $doc['settings']['seo']['description'] ?? '' );
		if ( '' === $desc ) {
			$desc = wp_html_excerpt( (string) ( $doc['profile']['bio'] ?? '' ), 300 );
		}
		$url      = (string) get_permalink( $post );
		$image_id = (int) ( $doc['settings']['seo']['image'] ?? 0 );
		$image_id = $image_id > 0 ? $image_id : (int) ( $doc['profile']['avatar'] ?? 0 );
		$image    = $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'large' ) : '';

		/**
		 * Filters whether CrazyLinks prints its own SEO meta (disable when an SEO plugin handles it).
		 *
		 * @param bool     $print Default true.
		 * @param \WP_Post $post  Page.
		 */
		$print_seo = (bool) apply_filters( 'crazylinks_print_seo_meta', true, $post );

		if ( ! current_theme_supports( 'title-tag' ) ) {
			echo '<title>' . esc_html( $title ) . "</title>\n";
		}
		echo '<meta name="theme-color" content="' . esc_attr( $tokens->theme_color() ) . "\">\n";
		if ( $print_seo ) {
			if ( '' !== $desc ) {
				echo '<meta name="description" content="' . esc_attr( $desc ) . "\">\n";
			}
			echo '<link rel="canonical" href="' . esc_url( $url ) . "\">\n";
			echo '<meta property="og:type" content="profile">' . "\n";
			echo '<meta property="og:title" content="' . esc_attr( $title ) . "\">\n";
			echo '<meta property="og:url" content="' . esc_url( $url ) . "\">\n";
			if ( '' !== $desc ) {
				echo '<meta property="og:description" content="' . esc_attr( $desc ) . "\">\n";
			}
			if ( '' !== $image ) {
				echo '<meta property="og:image" content="' . esc_url( $image ) . "\">\n";
			}
			echo '<meta name="twitter:card" content="' . ( '' !== $image ? 'summary_large_image' : 'summary' ) . "\">\n";
		}

		$avatar = (int) ( $doc['profile']['avatar'] ?? 0 );
		if ( $avatar > 0 ) {
			$src = wp_get_attachment_image_src( $avatar, 'cl-avatar' );
			if ( $src ) {
				$srcset = (string) wp_get_attachment_image_srcset( $avatar, 'cl-avatar' );
				printf(
					'<link rel="preload" as="image" href="%s"%s fetchpriority="high">' . "\n",
					esc_url( $src[0] ),
					'' !== $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="96px"' : ''
				);
			}
		}
	}

	/**
	 * Rendered page HTML for the current request.
	 *
	 * @param string $mode Mode.
	 */
	public static function page_html( string $mode ): string {
		if ( ! self::$post ) {
			return '';
		}
		if ( self::$locked || post_password_required( self::$post ) ) {
			return self::password_shell( self::$post );
		}
		return ( new Renderer() )->render_post( self::$post, $mode );
	}

	/**
	 * Minimal shell around core's password form. Contains nothing from the page document.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function password_shell( \WP_Post $post ): string {
		return '<div class="cl-page cl-page--locked"><div class="cl-inner">' . get_the_password_form( $post ) . '</div></div>';
	}

	/**
	 * Current page post.
	 */
	public static function current(): ?\WP_Post {
		return self::$post;
	}

	/**
	 * Custom CSS of the current page.
	 */
	public static function custom_css(): string {
		if ( ! self::$post || self::$locked ) {
			return '';
		}
		$doc = PageStore::get_document( self::$post ) ?? [];
		return ( new Tokens( (array) ( $doc['design'] ?? [] ) ) )->custom_css();
	}
}
