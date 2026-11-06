<?php
/**
 * Page renderer.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

use CrazyLinks\PageStore;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the same .cl-page HTML for every rendering mode (SPEC §6.3).
 * Walks the flat block list and dispatches each block to templates/blocks/{type}.php.
 */
final class Renderer {

	public const IMPLEMENTED = [ 'link', 'socials', 'heading', 'text', 'divider', 'image', 'spacer', 'group', 'email_capture', 'embed', 'html', 'gallery', 'latest_posts', 'product', 'video', 'contact', 'countdown', 'map', 'payment' ];

	/**
	 * Renders a saved page, using the transient cache.
	 *
	 * @param \WP_Post $post Post.
	 * @param string   $mode standalone | theme | embed.
	 */
	public function render_post( \WP_Post $post, string $mode ): string {
		$doc = PageStore::get_document( $post ) ?? [];
		if ( 'embed' === $mode && null !== Schedule::page_ttl( $doc, time(), wp_timezone() ) ) {
			// Embedded in other content (shortcode, block): keep page caches from freezing the schedule.
			\CrazyLinks\Frontend\TemplateLoader::no_page_cache();
		}
		// home_url() and is_ssl() are part of the signature so a request with a forged Host header
		// cannot plant HTML for other visitors on sites that derive WP_HOME from the request (SPEC §12).
		$sig = md5( implode( '|', [ $post->post_modified_gmt, $post->post_status, $post->post_name, md5( $post->post_content ), CRAZYLINKS_VERSION, Cache::salt(), Settings::base(), home_url( '/' ), is_ssl() ? 'https' : 'http' ] ) );

		// Password-protected pages are never cached, whoever is looking (SPEC §12).
		$cacheable = 'publish' === $post->post_status && ! is_preview() && '' === (string) $post->post_password;
		if ( $cacheable ) {
			$hit = Cache::get( (int) $post->ID, $mode, $sig );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		$ctx  = RenderContext::for_post( $post, is_preview() ? 'preview' : $mode, $doc );
		$html = $this->render( $doc, $ctx );
		if ( $cacheable ) {
			$ttl = null === $ctx->next_boundary ? Cache::MAX_TTL : $ctx->next_boundary - $ctx->now;
			Cache::set( (int) $post->ID, $mode, $sig, $html, $ttl );
		}
		return $html;
	}

	/**
	 * Renders a document.
	 *
	 * @param array<string, mixed> $doc Validated document.
	 * @param RenderContext        $ctx Context.
	 */
	public function render( array $doc, RenderContext $ctx ): string {
		$tokens = new Tokens( (array) ( $doc['design'] ?? [] ) );
		$blocks = $this->visible_blocks( (array) ( $doc['blocks'] ?? [] ), $ctx );

		$children = [];
		$top      = [];
		foreach ( $blocks as $block ) {
			if ( null === $block['parent_id'] ) {
				$top[] = $block;
			} else {
				$children[ (string) $block['parent_id'] ][] = $block;
			}
		}
		// Pinned top-level blocks float to the top, keeping their relative order.
		usort( $top, static fn( $a, $b ) => (int) ! empty( $b['pinned'] ) <=> (int) ! empty( $a['pinned'] ) );

		$ctx->layout    = (string) $tokens->get( 'layout', 'list' );
		$ctx->page_text = $tokens->text_color();
		$ctx->lead_id   = '';
		foreach ( $top as $block ) {
			if ( 'link' === $block['type'] ) {
				$ctx->lead_id = (string) $block['id'];
				break;
			}
		}

		$body = '';
		foreach ( $top as $block ) {
			$inner = '';
			if ( 'group' === $block['type'] ) {
				foreach ( $children[ $block['id'] ] ?? [] as $child ) {
					$inner .= $this->render_block( $child, $ctx, '' );
				}
			}
			$body .= $this->render_block( $block, $ctx, $inner );
		}

		return $this->partial(
			'page',
			[
				'doc'     => $doc,
				'tokens'  => $tokens,
				'ctx'     => $ctx,
				'profile' => (array) ( $doc['profile'] ?? [] ),
				'body'    => $body,
			]
		);
	}

	/**
	 * Drops hidden, unscheduled and orphaned blocks; records schedule boundaries.
	 *
	 * @param array<int, mixed> $blocks Blocks.
	 * @param RenderContext     $ctx    Context.
	 * @return array<int, array<string, mixed>>
	 */
	private function visible_blocks( array $blocks, RenderContext $ctx ): array {
		$out     = [];
		$visible = [];
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['visible'] ) ) {
				continue;
			}
			if ( ! self::in_schedule( $block, $ctx ) ) {
				continue;
			}
			if ( null !== ( $block['parent_id'] ?? null ) && ! isset( $visible[ $block['parent_id'] ] ) ) {
				continue;
			}
			$visible[ (string) $block['id'] ] = true;
			$out[]                            = $block + [ 'parent_id' => null ];
		}
		return $out;
	}

	/**
	 * Whether a block is inside its schedule (one-off range and weekly window) at render time, and
	 * records the schedule's next boundary for cache expiry. Blocks outside their window are omitted
	 * from the HTML entirely so they cannot leak early.
	 *
	 * @param array<string, mixed> $block Block.
	 * @param RenderContext        $ctx   Context (receives boundaries).
	 */
	public static function in_schedule( array $block, RenderContext $ctx ): bool {
		$schedule = is_array( $block['schedule'] ?? null ) ? $block['schedule'] : [];
		$next     = Schedule::next_boundary( $schedule, $ctx->now, $ctx->tz );
		if ( null !== $next ) {
			$ctx->note_boundary( $next );
		}
		return Schedule::is_active( $schedule, $ctx->now, $ctx->tz );
	}

	/**
	 * Renders one block through its partial.
	 *
	 * @param array<string, mixed> $block Block.
	 * @param RenderContext        $ctx   Context.
	 * @param string               $inner Rendered children (groups).
	 */
	public function render_block( array $block, RenderContext $ctx, string $inner ): string {
		$type = (string) $block['type'];
		if ( 'html' === $type && ! $ctx->allows_html() ) {
			$html = '';
		} elseif ( in_array( $type, self::IMPLEMENTED, true ) ) {
			$html = $this->partial(
				'blocks/' . str_replace( '_', '-', $type ),
				[
					'block' => $block,
					'props' => (array) ( $block['props'] ?? [] ),
					'ctx'   => $ctx,
					'inner' => $inner,
				]
			);
			$html = $this->wrap( $block, $html, $ctx );
		} else {
			$html = '<!-- crazylinks: ' . esc_html( $type ) . ' block is not available yet -->';
		}
		/**
		 * Filters a block's rendered HTML.
		 *
		 * @param string               $html  Rendered HTML.
		 * @param array<string, mixed> $block Block.
		 * @param RenderContext        $ctx   Render context.
		 */
		return (string) apply_filters( 'crazylinks_render_block', $html, $block, $ctx );
	}

	/**
	 * Common block wrapper (id, type class, animation, schedule end for client-side expiry).
	 *
	 * @param array<string, mixed> $block Block.
	 * @param string               $html  Inner HTML.
	 * @param RenderContext        $ctx   Context.
	 */
	private function wrap( array $block, string $html, RenderContext $ctx ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		$classes = [ 'cl-block', 'cl-block--' . sanitize_html_class( str_replace( '_', '-', (string) $block['type'] ) ) ];
		if ( ! empty( $block['pinned'] ) ) {
			$classes[] = 'cl-pinned';
		}
		if ( 'link' === $block['type'] && 'featured' === ( $block['props']['display'] ?? '' ) ) {
			$classes[] = 'cl-block--featured';
		}
		if ( 'link' === $block['type'] && in_array( $block['props']['tile_size'] ?? '', [ '2x1', '2x2' ], true ) ) {
			$classes[] = 'cl-tile-' . (string) $block['props']['tile_size'];
		}
		if ( 'featured' === $ctx->layout && (string) $block['id'] === $ctx->lead_id ) {
			$classes[] = 'cl-block--lead';
		}
		if ( ! empty( $block['animation'] ) && 'none' !== $block['animation'] ) {
			$classes[] = 'cl-anim-' . sanitize_html_class( (string) $block['animation'] );
		}
		// When the block's window closes (one-off end or the end of today's weekly window), so the
		// front-end script can hide it if a full-page cache serves this HTML past that moment.
		$end = Schedule::closes_at( is_array( $block['schedule'] ?? null ) ? $block['schedule'] : [], $ctx->now, $ctx->tz );
		$out = '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" id="cl-b-' . esc_attr( (string) $block['id'] ) . '"';
		if ( null !== $end ) {
			$out .= ' data-cl-end="' . esc_attr( gmdate( 'c', $end ) ) . '"';
		}
		return $out . '>' . $html . '</div>';
	}

	/**
	 * Includes a template partial and returns its output.
	 *
	 * @param string               $name Template name relative to templates/, without .php.
	 * @param array<string, mixed> $vars Variables for the template.
	 */
	public function partial( string $name, array $vars ): string {
		/**
		 * Filters the path of a CrazyLinks template partial.
		 *
		 * @param string $path Absolute path.
		 * @param string $name Template name.
		 */
		$file = (string) apply_filters( 'crazylinks_template_path', CRAZYLINKS_DIR . 'templates/' . $name . '.php', $name );
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$vars['renderer'] = $this;
		ob_start();
		( static function ( string $__file, array $__vars ): void {
			extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Template variables.
			include $__file;
		} )( $file, $vars );
		return (string) ob_get_clean();
	}

	/**
	 * Responsive attachment image with LCP handling: the first image on the page is eager + high priority.
	 *
	 * @param int                  $id    Attachment id.
	 * @param string               $size  Size name.
	 * @param RenderContext        $ctx   Context.
	 * @param array<string, mixed> $attr  Extra attributes.
	 */
	public function image( int $id, string $size, RenderContext $ctx, array $attr = [] ): string {
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) ) {
			return '';
		}
		if ( ! $ctx->first_image_done ) {
			$attr                 += [
				'loading'       => 'eager',
				'fetchpriority' => 'high',
			];
			$ctx->first_image_done = true;
		} else {
			$attr += [ 'loading' => 'lazy' ];
		}
		$attr += [ 'decoding' => 'async' ];
		return wp_get_attachment_image( $id, $size, false, $attr );
	}

	/**
	 * The alternative text for an image: the author's text, else the Media Library's, and an empty
	 * string for images marked decorative.
	 *
	 * @param mixed $alt        Alt text stored on the block.
	 * @param int   $id         Attachment id.
	 * @param bool  $decorative Marked decorative.
	 */
	public function alt( $alt, int $id, bool $decorative = false ): string {
		if ( $decorative ) {
			return '';
		}
		$alt = trim( (string) $alt );
		if ( '' === $alt && $id > 0 ) {
			$alt = trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );
		}
		return $alt;
	}

	/**
	 * Inline `object-position` for a focal point `{x, y}` (0 to 1), or '' for the default centre.
	 *
	 * @param mixed $focal Focal point prop.
	 */
	public static function focal_style( $focal ): string {
		if ( ! is_array( $focal ) || ! isset( $focal['x'], $focal['y'] ) || ! is_numeric( $focal['x'] ) || ! is_numeric( $focal['y'] ) ) {
			return '';
		}
		$x = max( 0.0, min( 1.0, (float) $focal['x'] ) );
		$y = max( 0.0, min( 1.0, (float) $focal['y'] ) );
		if ( 0.5 === $x && 0.5 === $y ) {
			return '';
		}
		return sprintf( 'object-position:%s%% %s%%', round( $x * 100, 1 ), round( $y * 100, 1 ) );
	}
}
