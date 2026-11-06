<?php
/**
 * Minimal WooCommerce signatures for static analysis (WooCommerce is optional at run time).
 *
 * @package CrazyLinks
 */

// phpcs:ignoreFile

class WC_Product {
	public function get_id(): int {}
	public function get_name(): string {}
	public function get_status(): string {}
	public function get_catalog_visibility(): string {}
	public function get_price(): string {}
	public function get_regular_price(): string {}
	public function get_image_id(): int {}
	public function is_on_sale(): bool {}
	public function is_type( string $type ): bool {}
}

/**
 * @param int $id Product id.
 * @return WC_Product|false
 */
function wc_get_product( $id ) {}

/**
 * @param array<string, mixed> $args Query args.
 * @return array<int, WC_Product>
 */
function wc_get_products( array $args ) {}

function wc_price( float $price ): string {}

function get_woocommerce_currency(): string {}
