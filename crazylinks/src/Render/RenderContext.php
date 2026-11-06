<?php
/**
 * Per-render state.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Render;

use CrazyLinks\Analytics\Utm;
use CrazyLinks\Frontend\Router;
use CrazyLinks\Schema\UrlPolicy;
use CrazyLinks\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Carries what the partials need: page identity, mode, tracking, time.
 */
final class RenderContext {

	/**
	 * Whether the first image (LCP candidate) has been emitted.
	 *
	 * @var bool
	 */
	public bool $first_image_done = false;

	/**
	 * Earliest future schedule boundary seen (unix time), for cache expiry.
	 *
	 * @var int|null
	 */
	public ?int $next_boundary = null;

	/**
	 * Page-level layout (list | grid | featured), set by the renderer before blocks are drawn.
	 *
	 * @var string
	 */
	public string $layout = 'list';

	/**
	 * Id of the first top-level link, the "lead" in the featured layout.
	 *
	 * @var string
	 */
	public string $lead_id = '';

	/**
	 * Resolved page text colour, set by the renderer (sections pick a readable text colour from it).
	 *
	 * @var string
	 */
	public string $page_text = '#111111';

	/**
	 * Zone for weekly schedule windows (the site timezone unless a test sets another).
	 *
	 * @var \DateTimeZone
	 */
	public \DateTimeZone $tz;

	/**
	 * Constructor.
	 *
	 * @param int                  $post_id   Page post id (0 for unsaved previews).
	 * @param string               $slug      Page slug.
	 * @param int                  $author_id Author whose capabilities gate the html block.
	 * @param string               $mode      standalone | theme | embed | preview.
	 * @param bool                 $track     Route web links through /go/ for click logging.
	 * @param array<string, mixed> $settings  Page settings object.
	 * @param int                  $now       Unix time used for schedules.
	 * @param \DateTimeZone|null   $tz        Zone for weekly windows; default wp_timezone().
	 */
	public function __construct(
		public int $post_id,
		public string $slug,
		public int $author_id,
		public string $mode,
		public bool $track,
		public array $settings,
		public int $now,
		?\DateTimeZone $tz = null
	) {
		$this->tz = $tz ?? wp_timezone();
	}

	/**
	 * Context for a saved page.
	 *
	 * @param \WP_Post             $post Post.
	 * @param string               $mode Mode.
	 * @param array<string, mixed> $doc  Document.
	 */
	public static function for_post( \WP_Post $post, string $mode, array $doc ): self {
		$track = 'publish' === $post->post_status && 'preview' !== $mode && Settings::analytics_enabled();
		return new self( (int) $post->ID, (string) $post->post_name, (int) $post->post_author, $mode, $track, (array) ( $doc['settings'] ?? [] ), time() );
	}

	/**
	 * Heading level for the profile name: h1 on full pages, h2 when embedded in other content.
	 */
	public function title_tag(): string {
		return in_array( $this->mode, [ 'standalone', 'theme', 'preview' ], true ) ? 'h1' : 'h2';
	}

	/**
	 * Whether the custom HTML block may render.
	 */
	public function allows_html(): bool {
		$user = 'preview' === $this->mode && 0 === $this->post_id ? get_current_user_id() : $this->author_id;
		return $user > 0 && user_can( $user, 'unfiltered_html' );
	}

	/**
	 * The href for a block's link: the root-relative /go/ redirect for tracked web links, otherwise the URL itself.
	 *
	 * @param array<string, mixed> $block Block.
	 * @param string               $url   Stored URL.
	 */
	public function href( array $block, string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( $this->track && ! empty( $block['tracking'] ) && UrlPolicy::is_web( $url ) && '' !== $this->slug ) {
			return Router::go_path( $this->slug, (string) $block['id'] );
		}
		return Utm::apply( $url, $this->settings, $this->slug );
	}

	/**
	 * The href for one item inside a block (a social icon): /go/{block}/{item}/ for tracked web
	 * URLs of items that have an id, otherwise the URL itself.
	 *
	 * @param array<string, mixed> $block   Block.
	 * @param string               $item_id Item id ('' for legacy items without one).
	 * @param string               $url     Stored URL.
	 */
	public function item_href( array $block, string $item_id, string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( $this->track && '' !== $item_id && ! empty( $block['tracking'] ) && UrlPolicy::is_web( $url ) && '' !== $this->slug ) {
			return Router::go_path( $this->slug, (string) $block['id'], $item_id );
		}
		return Utm::apply( $url, $this->settings, $this->slug );
	}

	/**
	 * The Buy link of a Product block: /go/{block}/ (single) or /go/{block}/{product_id}/ (newest
	 * products) when clicks are counted, otherwise the store's product page itself.
	 *
	 * @param array<string, mixed> $block      Product block.
	 * @param int                  $product_id Product id.
	 * @param string               $url        Product page URL from the store.
	 */
	public function product_href( array $block, int $product_id, string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		if ( $this->track && ! empty( $block['tracking'] ) && '' !== $this->slug ) {
			$latest = 'latest' === ( $block['props']['mode'] ?? 'single' );
			return Router::go_path( $this->slug, (string) $block['id'], $latest ? (string) $product_id : '' );
		}
		return Utm::apply( $url, $this->settings, $this->slug );
	}

	/**
	 * Records a schedule boundary in the future.
	 *
	 * @param int $ts Unix time.
	 */
	public function note_boundary( int $ts ): void {
		if ( $ts > $this->now && ( null === $this->next_boundary || $ts < $this->next_boundary ) ) {
			$this->next_boundary = $ts;
		}
	}
}
