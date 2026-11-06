<?php
/**
 * Product block: store client, setting, rendering, /go/ resolution, picker route and ability.
 *
 * @package CrazyLinks
 */

namespace CrazyLinks\Tests;

use CrazyLinks\Ai\Abilities;
use CrazyLinks\Analytics\ClickRedirect;
use CrazyLinks\Analytics\Visitor;
use CrazyLinks\Render\RenderContext;
use CrazyLinks\Render\Renderer;
use CrazyLinks\Schema\PageValidator;
use CrazyLinks\Settings;
use CrazyLinks\Woo\Store;

/**
 * A fake HTTP layer (pre_http_request) stands in for the store; no test touches the network.
 */
class ProductBlockTest extends TestCase {

	// A public address literal, so wp_http_validate_url() needs no DNS.
	private const STORE = 'https://93.184.216.34';
	private const BLOCK = '01HZZZZZZZZZZZZZZZZZZZZZZP';

	/**
	 * Requested URLs.
	 *
	 * @var string[]
	 */
	private array $requests = [];

	/**
	 * What the fake store answers: a callable( string $url ) returning a response, WP_Error or null (404).
	 *
	 * @var callable|null
	 */
	private $answer = null;

	public function set_up(): void {
		parent::set_up();
		$this->requests = [];
		$this->answer   = fn( string $url ) => $this->ok( [ $this->product( 7 ), $this->product( 8, 'Second' ) ] );
		add_filter( 'pre_http_request', [ $this, 'fake_http' ], 10, 3 );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), [ 'store_url' => self::STORE ] ) );
		Store::flush();
	}

	public function tear_down(): void {
		remove_all_filters( 'crazylinks_store_allow_http' );
		remove_filter( 'pre_http_request', [ $this, 'fake_http' ], 10 );
		delete_option( Settings::OPTION );
		Store::flush();
		parent::tear_down();
	}

	/**
	 * The fake transport.
	 *
	 * @param mixed                $pre  Preempt.
	 * @param array<string, mixed> $args Request args.
	 * @param string               $url  URL.
	 * @return mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		unset( $args );
		if ( 0 !== strpos( $url, self::STORE ) && 0 !== strpos( $url, 'http' ) ) {
			return $pre;
		}
		$this->requests[] = $url;
		$answer           = $this->answer;
		return $answer ? $answer( $url ) : new \WP_Error( 'http_request_failed', 'down' );
	}

	/**
	 * A 200 JSON response.
	 *
	 * @param mixed $body Body (encoded unless a string).
	 * @return array<string, mixed>
	 */
	private function ok( $body ): array {
		return [
			'headers'  => [],
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * A Store API product.
	 *
	 * @param int                  $id    Id.
	 * @param string               $name  Name.
	 * @param array<string, mixed> $extra Overrides.
	 * @return array<string, mixed>
	 */
	private function product( int $id, string $name = 'Logo Tee', array $extra = [] ): array {
		return array_replace_recursive(
			[
				'id'        => $id,
				'name'      => $name,
				'permalink' => self::STORE . '/product/p-' . $id . '/',
				'on_sale'   => false,
				'images'    => [ [ 'src' => 'https://93.184.216.35/img-' . $id . '.jpg' ] ],
				'prices'    => [
					'price'                       => '1850',
					'regular_price'               => '1850',
					'sale_price'                  => '1850',
					'currency_code'               => 'USD',
					'currency_symbol'             => '$',
					'currency_minor_unit'         => 2,
					'currency_decimal_separator'  => '.',
					'currency_thousand_separator' => ',',
					'currency_prefix'             => '$',
					'currency_suffix'             => '',
				],
			],
			$extra
		);
	}

	/**
	 * A page with one product block, published.
	 *
	 * @param array<string, mixed> $props Props.
	 */
	private function page( array $props ): \WP_Post {
		return $this->make_page(
			$this->doc(
				[
					[
						'id'       => self::BLOCK,
						'type'     => 'product',
						'tracking' => true,
						'props'    => $props,
					],
				]
			)
		);
	}

	private function render( array $props, string $mode = 'standalone', bool $track = false ): string {
		$doc = $this->doc( [ [ 'id' => self::BLOCK, 'type' => 'product', 'tracking' => true, 'props' => $props ] ] );
		$res = ( new PageValidator() )->validate( $doc, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $res->is_valid(), wp_json_encode( $res->errors() ) );
		$ctx = new RenderContext( 0, 'p', 1, $mode, $track, [], time() );
		return ( new Renderer() )->render( $res->document(), $ctx );
	}

	// ---------------------------------------------------------------------------------------------
	// Store client.

	public function test_single_product_is_normalized_and_cached(): void {
		$this->answer = fn() => $this->ok( [ $this->product( 7, 'Logo &amp; Tee <b>X</b>' ) ] );
		$p            = Store::product( 7 );
		$this->assertSame( 7, $p['id'] );
		$this->assertSame( 'Logo & Tee X', $p['name'] );
		$this->assertSame( self::STORE . '/product/p-7/', $p['url'] );
		$this->assertSame( 'https://93.184.216.35/img-7.jpg', $p['image'] );
		$this->assertSame( '$18.50', $p['price'] );
		$this->assertSame( '18.50', $p['amount'] );
		$this->assertSame( 'USD', $p['currency'] );
		$this->assertFalse( $p['on_sale'] );
		$this->assertSame( '', $p['regular_price'] );
		Store::product( 7 );
		$this->assertCount( 1, $this->requests, 'The second read comes from the transient.' );
		$this->assertStringStartsWith( self::STORE . '/wp-json/wc/store/v1/products?', $this->requests[0] );
	}

	public function test_prices_use_the_stores_symbol_separators_and_minor_units(): void {
		$sale         = $this->product(
			7,
			'Euro',
			[
				'on_sale' => true,
				'prices'  => [
					'price'                       => '123456',
					'regular_price'               => '150000',
					'currency_code'               => 'EUR',
					'currency_prefix'             => '',
					'currency_suffix'             => ' €',
					'currency_decimal_separator'  => ',',
					'currency_thousand_separator' => '.',
				],
			]
		);
		$this->answer = fn() => $this->ok( [ $sale ] );
		$p            = Store::product( 7 );
		$this->assertSame( '1.234,56 €', $p['price'] );
		$this->assertSame( '1.500,00 €', $p['regular_price'] );
		$this->assertTrue( $p['on_sale'] );
		$yen          = $this->product( 9, 'Yen', [ 'prices' => [ 'price' => '1800', 'currency_minor_unit' => 0, 'currency_prefix' => '¥' ] ] );
		$this->answer = fn() => $this->ok( [ $yen ] );
		$this->assertSame( '¥1,800', Store::product( 9 )['price'] );
	}

	public function test_variable_product_shows_its_lowest_price(): void {
		$var          = $this->product( 7, 'Var', [ 'prices' => [ 'price_range' => [ 'min_amount' => '1850', 'max_amount' => '2850' ] ] ] );
		$this->answer = fn() => $this->ok( [ $var ] );
		$p            = Store::product( 7 );
		$this->assertSame( '$18.50', $p['price'] );
		$this->assertTrue( $p['price_from'] );
	}

	public function test_latest_sends_count_order_and_category_slug_only(): void {
		$list = Store::latest( 3, 'Desk_Dwellers' );
		$this->assertCount( 2, $list );
		parse_str( (string) wp_parse_url( $this->requests[0], PHP_URL_QUERY ), $q );
		$this->assertSame( '3', $q['per_page'] );
		$this->assertSame( 'date', $q['orderby'] );
		$this->assertSame( 'desc', $q['order'] );
		$this->assertSame( 'desk_dwellers', $q['category'] );
		$this->requests = [];
		Store::latest( 3, 'bad slug/../' );
		parse_str( (string) wp_parse_url( $this->requests[0], PHP_URL_QUERY ), $q );
		$this->assertArrayNotHasKey( 'category', $q, 'An invalid slug is dropped.' );
	}

	public function test_timeout_falls_back_to_the_last_good_result(): void {
		$this->assertSame( 7, Store::product( 7 )['id'] );
		// Expire the short cache only; the last good copy has no expiry.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%crazylinks\\_store\\_%'" );
		wp_cache_flush();
		$this->answer = static fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$p            = Store::product( 7 );
		$this->assertNotNull( $p );
		$this->assertSame( 'Logo Tee', $p['name'] );
		$this->assertNull( Store::product( 99 ), 'Unknown products have no stale copy.' );
	}

	public function test_a_failing_store_is_not_asked_again_for_a_while(): void {
		$this->answer = static fn() => new \WP_Error( 'http_request_failed', 'down' );
		$this->assertSame( [], Store::latest( 3 ) );
		$count = count( $this->requests );
		$this->assertSame( [], Store::latest( 4 ) );
		$this->assertCount( $count, $this->requests, 'The back-off stops a slow store from stalling every render.' );
	}

	public function test_malformed_json_and_error_codes_give_nothing(): void {
		$this->answer = fn() => $this->ok( '{not json' );
		$this->assertSame( [], Store::latest( 3 ) );
		$this->assertNull( Store::product( 7 ) );
		Store::flush();
		$this->answer = static fn() => [
			'headers'  => [],
			'body'     => '[]',
			'response' => [
				'code'    => 500,
				'message' => 'Error',
			],
			'cookies'  => [],
			'filename' => null,
		];
		$this->assertSame( [], Store::latest( 3 ) );
	}

	public function test_http_store_urls_are_rejected_outside_local_environments(): void {
		add_filter( 'crazylinks_store_allow_http', '__return_false' );
		$this->assertSame( '', Store::sanitize_url( 'http://store.example.com' ) );
		$this->assertSame( 'https://store.example.com', Store::sanitize_url( 'https://Store.Example.com/' ) );
		remove_all_filters( 'crazylinks_store_allow_http' );
		add_filter( 'crazylinks_store_allow_http', '__return_true' );
		$this->assertSame( 'http://store.test', Store::sanitize_url( 'http://store.test' ) );
		foreach ( [ 'ftp://store.example.com', 'javascript:alert(1)', 'https://user:pw@store.example.com', 'https://store.example.com/?a=1', 'https://store.example.com/#x', '//store.example.com', 'store.example.com', 'https://', '' ] as $bad ) {
			$this->assertSame( '', Store::sanitize_url( $bad ), $bad );
		}
		$this->assertSame( 'https://example.com/shop', Store::sanitize_url( 'https://example.com/shop/' ) );
	}

	public function test_host_is_pinned_to_the_setting(): void {
		// A product id (or category) can only fill a query value, never change the host or path.
		Store::product( 7 );
		$this->answer   = fn() => $this->ok( [ $this->product( 12 ) ] );
		$this->requests = [];
		Store::product( 12 );
		Store::latest( 2, 'x' );
		Store::search( 'https://evil.example/', 5 );
		foreach ( $this->requests as $url ) {
			$this->assertSame( 'https://93.184.216.34/wp-json/wc/store/v1/products', strtok( $url, '?' ), $url );
		}
		// A store reply cannot point the Buy link at another host.
		$evil         = $this->product( 13, 'Evil', [ 'permalink' => 'https://evil.example/steal' ] );
		$this->answer = fn() => $this->ok( [ $evil ] );
		$this->assertNull( Store::product( 13 ) );
		// Images must be https.
		$plain        = $this->product( 14, 'Plain', [ 'images' => [ [ 'src' => 'http://93.184.216.35/a.jpg' ] ] ] );
		$this->answer = fn() => $this->ok( [ $plain ] );
		$this->assertSame( '', Store::product( 14 )['image'] );
		// Redirects are never followed.
		$captured = null;
		add_filter(
			'http_request_args',
			static function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args;
			}
		);
		Store::flush();
		Store::product( 15 );
		$this->assertSame( 0, $captured['redirection'] );
		$this->assertSame( 5, $captured['timeout'] );
	}

	public function test_changing_the_store_never_serves_the_old_stores_products(): void {
		$this->assertNotNull( Store::product( 7 ) );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), [ 'store_url' => 'https://93.184.216.40' ] ) );
		$this->answer = static fn() => new \WP_Error( 'http_request_failed', 'down' );
		$this->assertNull( Store::product( 7 ) );
	}

	public function test_no_store_and_no_woocommerce_means_none(): void {
		update_option( Settings::OPTION, Settings::defaults() );
		$this->assertSame( 'none', Store::mode() );
		$this->assertSame( [], Store::latest( 3 ) );
		$this->assertNull( Store::product( 7 ) );
		$this->requests = [];
		Store::search( 'tee' );
		$this->assertSame( [], $this->requests );
	}

	public function test_status_reports_answer_and_store_name_cached(): void {
		$this->answer = fn( string $url ) => str_contains( $url, '/wc/store/' ) ? $this->ok( [] ) : $this->ok( [ 'name' => 'Asylum <i>Store</i>' ] );
		$status       = Store::status();
		$this->assertTrue( $status['ok'] );
		$this->assertSame( 'Asylum Store', $status['name'] );
		$n = count( $this->requests );
		Store::status();
		$this->assertCount( $n, $this->requests, 'Cached.' );
		$this->assertStringStartsWith( self::STORE . '/wp-json/?_fields=name', end( $this->requests ) );
		$this->answer = static fn() => new \WP_Error( 'http_request_failed', 'down' );
		$this->assertFalse( Store::status( true )['ok'] );
	}

	public function test_cache_ttl_filter(): void {
		add_filter( 'crazylinks_store_cache_ttl', static fn() => 60 );
		$this->assertSame( 60, Store::ttl( 'product' ) );
	}

	// ---------------------------------------------------------------------------------------------
	// Setting.

	public function test_settings_sanitizer_drops_invalid_urls_and_is_idempotent(): void {
		add_filter( 'crazylinks_store_allow_http', '__return_false' );
		update_option( Settings::OPTION, Settings::defaults() );
		$out = Settings::sanitize( [ 'store_url' => 'javascript:alert(1)' ] );
		$this->assertSame( '', $out['store_url'] );
		$out = Settings::sanitize( [ 'store_url' => 'http://insecure.example.com' ] );
		$this->assertSame( '', $out['store_url'] );

		$out = Settings::sanitize( [ 'store_url' => ' https://Store.Example.com/ ' ] );
		$this->assertSame( 'https://store.example.com', $out['store_url'] );
		$this->assertSame( $out, Settings::sanitize( $out ), 'A second pass changes nothing.' );

		update_option( Settings::OPTION, $out );
		$kept = Settings::sanitize( [ 'store_url' => 'not a url' ] );
		$this->assertSame( 'https://store.example.com', $kept['store_url'], 'An invalid value keeps the previous one.' );
		$this->assertSame( 'https://store.example.com', Settings::sanitize( [ 'analytics' => '1' ] )['store_url'], 'A form without the field leaves it alone.' );
		$this->assertSame( '', Settings::sanitize( [ 'store_url' => '' ] )['store_url'], 'Empty clears it.' );
	}

	// ---------------------------------------------------------------------------------------------
	// Rendering.

	public function test_card_renders_image_name_price_and_buy_link(): void {
		$html = $this->render(
			[
				'product_id'  => 7,
				'subtitle'    => 'Soft cotton',
				'button_text' => 'Get it',
			]
		);
		$this->assertStringContainsString( 'cl-product', $html );
		$this->assertStringContainsString( 'src="https://93.184.216.35/img-7.jpg"', $html );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringContainsString( 'Logo Tee', $html );
		$this->assertStringContainsString( 'Soft cotton', $html );
		$this->assertStringContainsString( '$18.50', $html );
		$this->assertStringContainsString( '>Get it</a>', $html );
		$this->assertStringContainsString( 'href="' . self::STORE . '/product/p-7/"', $html );
		$this->assertStringNotContainsString( 'cl-product__sale', $html );
		$this->assertStringNotContainsString( 'cart', strtolower( $html ), 'The link is the product page, never the cart.' );
	}

	public function test_title_override_and_hidden_price(): void {
		$html = $this->render(
			[
				'product_id' => 7,
				'title'      => 'Our best seller',
				'show_price' => false,
			]
		);
		$this->assertStringContainsString( 'Our best seller', $html );
		$this->assertStringNotContainsString( '$18.50', $html );
		$this->assertStringContainsString( '>Buy</a>', $html );
	}

	public function test_sale_shows_badge_and_struck_regular_price(): void {
		$sale         = $this->product( 7, 'Tee', [ 'on_sale' => true, 'prices' => [ 'price' => '1500', 'regular_price' => '2000' ] ] );
		$this->answer = fn() => $this->ok( [ $sale ] );
		$html         = $this->render( [ 'product_id' => 7 ] );
		$this->assertStringContainsString( 'cl-product__sale">Sale<', $html );
		$this->assertMatchesRegularExpression( '#<del class="cl-product__was">.*\$20\.00</del>#s', $html );
		$this->assertMatchesRegularExpression( '#<ins class="cl-product__now">.*\$15\.00</ins>#s', $html );
		$button = $this->render( [ 'product_id' => 7, 'display' => 'button' ] );
		$this->assertStringContainsString( 'cl-badge">Sale<', $button );
	}

	public function test_button_display_reuses_the_thumbnail_link_classes(): void {
		$html = $this->render( [ 'product_id' => 7, 'display' => 'button' ] );
		$this->assertStringContainsString( 'cl-link cl-link--classic cl-link--thumb', $html );
		$this->assertStringContainsString( 'class="cl-link__thumb"', $html );
		$this->assertStringContainsString( 'cl-link__title">Logo Tee<', $html );
		$this->assertStringContainsString( 'cl-link__subtitle">$18.50<', $html );
	}

	public function test_product_name_is_escaped(): void {
		$name         = '"><script>alert(1)</script><img src=x onerror=alert(2)>';
		$entity       = '&lt;script&gt;alert(3)&lt;/script&gt;';
		$this->answer = fn() => $this->ok( [ $this->product( 7, $name ), $this->product( 8, $entity ) ] );
		foreach ( [ 'card', 'button' ] as $display ) {
			foreach ( [ [ 'product_id' => 8 ], [ 'mode' => 'latest' ] ] as $extra ) {
				$html = $this->render( [ 'display' => $display ] + $extra );
				$this->assertStringNotContainsString( '<script', $html );
				$this->assertStringNotContainsString( '<img src=x', $html );
			}
		}
		$html = $this->render( [ 'product_id' => 8 ] );
		$this->assertStringContainsString( '&lt;script&gt;alert(3)&lt;/script&gt;', $html, 'Text that looks like markup is shown as text.' );
		$title = $this->render( [ 'product_id' => 8, 'title' => '<script>alert(4)</script>' ] );
		$this->assertStringNotContainsString( '<script>alert(4)', $title );
	}

	public function test_latest_mode_lists_products_with_product_id_links_and_caps_cache(): void {
		$doc  = $this->doc( [ [ 'id' => self::BLOCK, 'type' => 'product', 'tracking' => true, 'props' => [ 'mode' => 'latest', 'count' => 2 ] ] ] );
		$res  = ( new PageValidator() )->validate( $doc, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$now  = time();
		$ctx  = new RenderContext( 0, 'p', 1, 'standalone', true, [], $now );
		$html = ( new Renderer() )->render( $res->document(), $ctx );
		$this->assertSame( 2, substr_count( $html, 'class="cl-products__item"' ) );
		$this->assertMatchesRegularExpression( '#(/go/' . self::BLOCK . '/7|crazylinks_go=' . self::BLOCK . '&\#038;crazylinks_item=7)"#', $html );
		$this->assertMatchesRegularExpression( '#(/go/' . self::BLOCK . '/8|crazylinks_go=' . self::BLOCK . '&\#038;crazylinks_item=8)"#', $html );
		$this->assertSame( $now + 15 * MINUTE_IN_SECONDS, $ctx->next_boundary );
	}

	public function test_single_tracked_link_goes_through_go(): void {
		$doc  = $this->doc( [ [ 'id' => self::BLOCK, 'type' => 'product', 'tracking' => true, 'props' => [ 'product_id' => 7 ] ] ] );
		$res  = ( new PageValidator() )->validate( $doc, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$html = ( new Renderer() )->render( $res->document(), new RenderContext( 0, 'p', 1, 'standalone', true, [], time() ) );
		$this->assertMatchesRegularExpression( '#href="[^"]*(/go/|crazylinks_go=)' . self::BLOCK . '/?"#', $html );
		$this->assertStringNotContainsString( self::STORE . '/product/p-7', $html );
	}

	public function test_nothing_renders_without_a_product_but_the_editor_preview_explains(): void {
		$this->answer = static fn() => new \WP_Error( 'http_request_failed', 'down' );
		$this->assertStringNotContainsString( 'cl-product', $this->render( [ 'product_id' => 7 ] ) );
		$this->assertStringNotContainsString( 'cl-product', $this->render( [ 'product_id' => 0 ] ) );
		$this->assertStringContainsString( 'cl-product__empty', $this->render( [ 'product_id' => 0 ], 'preview' ) );
	}

	public function test_schema_accepts_the_props_and_rejects_others(): void {
		$validate = fn( array $props ) => ( new PageValidator() )->validate( $this->doc( [ [ 'type' => 'product', 'props' => $props ] ] ), self::factory()->user->create( [ 'role' => 'administrator' ] ) )->is_valid();
		$this->assertTrue( $validate( [ 'mode' => 'latest', 'count' => 12, 'category' => 'desk_dwellers', 'display' => 'button', 'button_text' => str_repeat( 'a', 40 ) ] ) );
		$this->assertFalse( $validate( [ 'count' => 13 ] ) );
		$this->assertFalse( $validate( [ 'mode' => 'all' ] ) );
		$this->assertFalse( $validate( [ 'category' => 'Bad Slug' ] ) );
		$this->assertFalse( $validate( [ 'button_text' => str_repeat( 'a', 41 ) ] ) );
		$this->assertFalse( $validate( [ 'store_url' => 'https://evil.example' ] ), 'The store address is a setting, never a prop.' );
		$this->assertFalse( $validate( [ 'title' => str_repeat( 'a', 121 ) ] ) );
		$this->assertFalse( $validate( [ 'subtitle' => str_repeat( 'a', 161 ) ] ) );
	}

	public function test_a_section_accepts_a_product_child(): void {
		$doc = $this->doc(
			[
				[
					'id'    => '01HZZZZZZZZZZZZZZZZZZZZZZG',
					'type'  => 'group',
					'props' => [ 'heading' => 'Shop' ],
				],
				[
					'id'        => self::BLOCK,
					'type'      => 'product',
					'parent_id' => '01HZZZZZZZZZZZZZZZZZZZZZZG',
					'props'     => [ 'product_id' => 7 ],
				],
			]
		);
		$html = $this->render_doc( $doc );
		$this->assertStringContainsString( 'cl-block--product', $html );
		$this->assertLessThan( strpos( $html, 'cl-block--product' ), strpos( $html, 'Shop' ) );
	}

	/**
	 * Renders a whole document.
	 *
	 * @param array<string, mixed> $doc Document.
	 */
	private function render_doc( array $doc ): string {
		$res = ( new PageValidator() )->validate( $doc, self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->assertTrue( $res->is_valid(), wp_json_encode( $res->errors() ) );
		return ( new Renderer() )->render( $res->document(), new RenderContext( 0, 'p', 1, 'standalone', false, [], time() ) );
	}

	// ---------------------------------------------------------------------------------------------
	// /go/.

	private function go( \WP_Post $post, string $item = '' ): array {
		return ( new ClickRedirect() )->resolve( $post, self::BLOCK, new Visitor( '203.0.113.7', 'Mozilla/5.0', '' ), $item );
	}

	public function test_go_redirects_a_single_product_to_its_store_page(): void {
		$post = $this->page( [ 'product_id' => 7 ] );
		Store::product( 7 ); // The render path caches it.
		$res = $this->go( $post );
		$this->assertSame( 302, $res['status'] );
		$this->assertSame( self::STORE . '/product/p-7/', $res['location'] );
		$this->assertSame( 'logged', $res['event'] );
		$this->assertSame( 404, $this->go( $post, '7' )['status'], 'A single block takes no product id.' );
	}

	public function test_go_uses_cached_data_only_and_404s_without_it(): void {
		$post = $this->page( [ 'product_id' => 7 ] );
		$this->requests = [];
		$this->assertSame( 404, $this->go( $post )['status'], 'Nothing cached yet.' );
		$this->assertSame( [], $this->requests, '/go/ never asks the store.' );
		Store::product( 7 );
		$this->assertSame( 302, $this->go( $post )['status'] );
		Store::flush();
		$this->assertSame( 404, $this->go( $post )['status'], 'Flushed: nothing to redirect to.' );
	}

	public function test_go_serves_the_stale_copy_when_the_short_cache_expired(): void {
		$post = $this->page( [ 'product_id' => 7 ] );
		Store::product( 7 );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%crazylinks\\_store\\_%'" );
		wp_cache_flush();
		$this->assertSame( 302, $this->go( $post )['status'] );
	}

	public function test_go_in_latest_mode_needs_a_listed_product_id(): void {
		$post = $this->page( [ 'mode' => 'latest', 'count' => 2 ] );
		Store::latest( 2 );
		$res = $this->go( $post, '8' );
		$this->assertSame( 302, $res['status'] );
		$this->assertSame( self::STORE . '/product/p-8/', $res['location'] );
		$this->assertSame( 404, $this->go( $post, '999' )['status'], 'An id the block does not list is refused.' );
		$this->assertSame( 404, $this->go( $post )['status'], 'Latest mode needs an id.' );
		$this->assertSame( 404, $this->go( $post, 'abc' )['status'] );
	}

	// ---------------------------------------------------------------------------------------------
	// Picker route and ability.

	private function route( string $search = '' ): \WP_REST_Response {
		$req = new \WP_REST_Request( 'GET', '/crazylinks/v1/store/products' );
		$req->set_query_params( [ 'search' => $search, 'per_page' => 5 ] );
		return rest_do_request( $req );
	}

	public function test_picker_route_permissions(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->route()->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertSame( 403, $this->route()->get_status() );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$res = $this->route( 'tee' );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['available'] );
		$this->assertSame( [ 7, 8 ], array_column( $data['products'], 'id' ) );
		$this->assertSame( [ 'id', 'name', 'price', 'url', 'image', 'on_sale' ], array_keys( $data['products'][0] ) );
		parse_str( (string) wp_parse_url( end( $this->requests ), PHP_URL_QUERY ), $q );
		$this->assertSame( 'tee', $q['search'] );
		$this->assertSame( '5', $q['per_page'] );
	}

	public function test_picker_route_can_resolve_one_id_and_reports_no_store(): void {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$req = new \WP_REST_Request( 'GET', '/crazylinks/v1/store/products' );
		$req->set_query_params( [ 'include' => 7 ] );
		$this->assertSame( [ 7 ], array_column( rest_do_request( $req )->get_data()['products'], 'id' ) );
		update_option( Settings::OPTION, Settings::defaults() );
		$data = $this->route()->get_data();
		$this->assertFalse( $data['available'] );
		$this->assertSame( [], $data['products'] );
	}

	public function test_search_products_ability(): void {
		$this->assertContains( 'crazylinks/search-products', Abilities::NAMES );
		update_option( Settings::OPTION, array_merge( Settings::defaults(), [ 'store_url' => self::STORE, 'ai_abilities' => Abilities::NAMES ] ) );
		$ability = wp_get_ability( 'crazylinks/search-products' );
		$this->assertNotNull( $ability );
		$a = $ability->get_meta_item( 'annotations' );
		$this->assertTrue( $a['readonly'] );
		$this->assertFalse( $a['destructive'] );
		$req = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/crazylinks/search-products/run' );
		$req->set_query_params( [ 'input' => [ 'search' => 'tee', 'limit' => 2 ] ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$this->assertContains( rest_do_request( $req )->get_status(), [ 401, 403 ] );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$this->assertSame(
			[
				[
					'id'    => 7,
					'name'  => 'Logo Tee',
					'price' => '$18.50',
					'url'   => self::STORE . '/product/p-7/',
				],
				[
					'id'    => 8,
					'name'  => 'Second',
					'price' => '$18.50',
					'url'   => self::STORE . '/product/p-8/',
				],
			],
			$res->get_data()
		);
	}
}
