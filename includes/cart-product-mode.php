<?php
/**
 * Product mode cart handling.
 *
 * AJAX add-to-cart (shared router), bulk discount pricing, and cart display
 * for product mode variable products.
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────────────────────
 * AJAX add-to-cart (shared entry point)
 * ─────────────────────────────────────────── */

add_action( 'wc_ajax_wsg_add_to_cart', 'wsg_product_add_to_cart' );

/**
 * AJAX handler: add items to cart.
 *
 * This function acts as the shared router for both product and bundle modes.
 * If the incoming mode is 'bundle', it delegates to the bundle handler
 * (defined in cart-bundle-mode.php) and returns early.
 *
 * @return void Sends JSON response and terminates.
 */
function wsg_product_add_to_cart() {
	check_ajax_referer( 'wsg_nonce', 'security' );

	$mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';

	// Route bundle mode to its own handler.
	if ( 'bundle' === $mode ) {
		wsg_bundle_add_to_cart_handler();
		return;
	}

	// --- Product mode handling ---

	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$items_json = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
	$items      = json_decode( $items_json, true );

	if ( ! $product_id || ! is_array( $items ) || empty( $items ) ) {
		wp_send_json_error( array( 'message' => __( 'No items selected.', 'wsg' ) ) );
	}

	$product = wc_get_product( $product_id );

	if ( ! $product ) {
		wp_send_json_error( array( 'message' => __( 'Product not found.', 'wsg' ) ) );
	}

	// Calculate total quantity across all items for discount tier lookup.
	$total_qty = 0;

	foreach ( $items as $item ) {
		$total_qty += absint( $item['qty'] );
	}

	$tiers    = get_post_meta( $product_id, '_wsg_discount_tiers', true );
	$tiers    = is_array( $tiers ) ? $tiers : array();
	$discount = wsg_get_discount_for_qty( $tiers, $total_qty );
	$discount = floatval( apply_filters( 'wsg_discount_amount', $discount, $product_id, $total_qty ) );

	$group_id       = 'wsg_' . $product_id . '_' . uniqid();
	$cart_item_keys = array();

	/**
	 * Fires before product-mode items are added to the cart.
	 *
	 * @param int    $product_id Product ID.
	 * @param array  $items      Array of item data from the frontend.
	 * @param string $mode       Always 'product'.
	 */
	do_action( 'wsg_before_add_to_cart', $product_id, $items, 'product' );

	// Detect colour / size attribute taxonomy names.
	$attrs      = wsg_detect_attributes( $product );
	$color_attr = $attrs['color'];
	$size_attr  = $attrs['size'];

	foreach ( $items as $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$qty          = absint( $item['qty'] ?? 0 );
		$variation_id = absint( $item['variation_id'] ?? 0 );

		if ( $qty <= 0 || ! $variation_id ) {
			continue;
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation ) {
			continue;
		}

		// Build variation attributes array for WC.
		$variation_attributes = array();

		if ( $color_attr && ! empty( $item['color_slug'] ) ) {
			$variation_attributes[ 'attribute_' . sanitize_title( $color_attr ) ] = sanitize_text_field( $item['color_slug'] );
		}

		if ( $size_attr && ! empty( $item['size_slug'] ) ) {
			$variation_attributes[ 'attribute_' . sanitize_title( $size_attr ) ] = sanitize_text_field( $item['size_slug'] );
		}

		$cart_item_data = array(
			'_wsg_group_id'   => $group_id,
			'_wsg_discount'   => $discount,
			'_wsg_base_price' => floatval( $variation->get_price() ),
		);

		/**
		 * Filters cart item data before adding to cart.
		 *
		 * @param array $cart_item_data Custom cart item data.
		 * @param array $item          Item data from the frontend.
		 * @param int   $product_id    Product ID.
		 */
		$cart_item_data = apply_filters( 'wsg_cart_item_data', $cart_item_data, $item, $product_id );

		$key = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation_attributes, $cart_item_data );

		if ( $key ) {
			$cart_item_keys[] = $key;
		}
	}

	if ( empty( $cart_item_keys ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not add items to cart.', 'wsg' ) ) );
	}

	/**
	 * Fires after product-mode items have been added to the cart.
	 *
	 * @param int    $product_id     Product ID.
	 * @param array  $cart_item_keys Cart item keys that were added.
	 * @param string $mode           Always 'product'.
	 */
	do_action( 'wsg_after_add_to_cart', $product_id, $cart_item_keys, 'product' );

	$data = array(
		'message'  => __( 'Added to cart', 'wsg' ),
		'cart_url' => wc_get_cart_url(),
		'redirect' => get_option( 'woocommerce_cart_redirect_after_add' ) === 'yes',
	);

	wp_send_json_success( $data );
}

/* ───────────────────────────────────────────
 * Bulk discount pricing
 * ─────────────────────────────────────────── */

add_action( 'woocommerce_before_calculate_totals', 'wsg_apply_bulk_discount', 20 );

/**
 * Apply bulk discount to product-mode cart items.
 *
 * Adjusts the price of each cart item that carries a `_wsg_discount` value.
 * Bundle items are skipped — they use their own pricing logic.
 *
 * @param WC_Cart $cart Cart instance.
 * @return void
 */
function wsg_apply_bulk_discount( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	// First pass: sum quantities per product for product-mode WSG items.
	$product_qtys = array();

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['_wsg_group_id'] ) || ! empty( $cart_item['_wsg_is_bundle'] ) ) {
			continue;
		}

		$pid = $cart_item['product_id'];

		if ( ! isset( $product_qtys[ $pid ] ) ) {
			$product_qtys[ $pid ] = 0;
		}

		$product_qtys[ $pid ] += $cart_item['quantity'];
	}

	if ( empty( $product_qtys ) ) {
		return;
	}

	// Look up the current discount tier for each product.
	$product_discounts = array();

	foreach ( $product_qtys as $pid => $total_qty ) {
		$tiers    = get_post_meta( $pid, '_wsg_discount_tiers', true );
		$tiers    = is_array( $tiers ) ? $tiers : array();
		$discount = wsg_get_discount_for_qty( $tiers, $total_qty );

		$product_discounts[ $pid ] = floatval( apply_filters( 'wsg_discount_amount', $discount, $pid, $total_qty ) );
	}

	// Second pass: apply discount using the stored base price for idempotency.
	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['_wsg_group_id'] ) || ! empty( $cart_item['_wsg_is_bundle'] ) ) {
			continue;
		}

		$pid      = $cart_item['product_id'];
		$discount = isset( $product_discounts[ $pid ] ) ? $product_discounts[ $pid ] : 0;

		if ( $discount <= 0 ) {
			continue;
		}

		// Use stored base price for idempotency; fall back to current price for legacy items.
		$base_price = isset( $cart_item['_wsg_base_price'] )
			? floatval( $cart_item['_wsg_base_price'] )
			: floatval( $cart_item['data']->get_price() );

		$cart_item['data']->set_price( max( 0, $base_price - $discount ) );
	}
}

/* ───────────────────────────────────────────
 * Cart display — discount note
 * ─────────────────────────────────────────── */

add_filter( 'woocommerce_get_item_data', 'wsg_product_cart_item_data', 10, 2 );

/**
 * Show a "Bulk discount" note beneath cart items that received a discount.
 *
 * @param array $item_data Existing item data rows.
 * @param array $cart_item Cart item.
 * @return array Modified item data rows.
 */
function wsg_product_cart_item_data( $item_data, $cart_item ) {
	if ( empty( $cart_item['_wsg_group_id'] ) || ! empty( $cart_item['_wsg_is_bundle'] ) ) {
		return $item_data;
	}

	// Dynamically calculate discount based on all WSG items for this product in cart.
	$pid       = $cart_item['product_id'];
	$total_qty = 0;

	foreach ( WC()->cart->get_cart() as $other ) {
		if ( empty( $other['_wsg_group_id'] ) || ! empty( $other['_wsg_is_bundle'] ) ) {
			continue;
		}
		if ( (int) $other['product_id'] === (int) $pid ) {
			$total_qty += $other['quantity'];
		}
	}

	$tiers    = get_post_meta( $pid, '_wsg_discount_tiers', true );
	$tiers    = is_array( $tiers ) ? $tiers : array();
	$discount = wsg_get_discount_for_qty( $tiers, $total_qty );
	$discount = floatval( apply_filters( 'wsg_discount_amount', $discount, $pid, $total_qty ) );

	if ( $discount > 0 ) {
		$item_data[] = array(
			'key'   => __( 'Bulk discount', 'wsg' ),
			'value' => '-' . wp_kses_post( wc_price( $discount ) ) . ' ' . esc_html__( 'per item', 'wsg' ),
		);
	}

	return $item_data;
}
