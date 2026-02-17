<?php
/**
 * Cart handling for Bundle mode.
 *
 * AJAX add-to-cart, bundle pricing, visibility, name overrides,
 * quantity display, sizes breakdown, and cascade removal.
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle bundle add-to-cart.
 *
 * Called from the product-mode router when the product mode is "bundle".
 * This is NOT hooked directly to an AJAX action.
 *
 * @return void Sends JSON response and dies.
 */
function wsg_bundle_add_to_cart_handler() {
	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
	$items_json = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
	$items      = json_decode( $items_json, true );

	if ( ! $product_id || empty( $items ) ) {
		wp_send_json_error( array( 'message' => __( 'No items selected.', 'wsg' ) ) );
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		wp_send_json_error( array( 'message' => __( 'Product not found.', 'wsg' ) ) );
	}

	$required_qty = absint( get_post_meta( $product_id, '_wsg_bundle_qty', true ) );
	$bundle_price = floatval( get_post_meta( $product_id, '_wsg_bundle_price', true ) );
	$display_name = get_post_meta( $product_id, '_wsg_bundle_display_name', true );

	// Validate total qty.
	$total_qty = 0;
	foreach ( $items as $item ) {
		$total_qty += absint( $item['qty'] );
	}

	if ( $required_qty > 0 && $total_qty !== $required_qty ) {
		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: 1: required quantity, 2: selected quantity */
					__( 'Bundle requires exactly %1$d items. You selected %2$d.', 'wsg' ),
					$required_qty,
					$total_qty
				),
			)
		);
	}

	$bundle_id  = 'wsg_bnd_' . $product_id . '_' . uniqid();
	$attrs      = wsg_detect_attributes( $product );
	$color_attr = $attrs['color'];
	$size_attr  = $attrs['size'];

	do_action( 'wsg_before_add_to_cart', $product_id, $items, 'bundle' );

	$index          = 0;
	$cart_item_keys = array();

	foreach ( $items as $item ) {
		$qty          = absint( $item['qty'] );
		$variation_id = absint( $item['variation_id'] );

		if ( $qty <= 0 || ! $variation_id ) {
			continue;
		}

		$variation_attributes = array();
		if ( $color_attr && ! empty( $item['color_slug'] ) ) {
			$variation_attributes[ 'attribute_' . $color_attr ] = sanitize_text_field( $item['color_slug'] );
		}
		if ( $size_attr && ! empty( $item['size_slug'] ) ) {
			$variation_attributes[ 'attribute_' . $size_attr ] = sanitize_text_field( $item['size_slug'] );
		}

		$cart_item_data = array(
			'_wsg_is_bundle'    => true,
			'_wsg_bundle_id'    => $bundle_id,
			'_wsg_bundle_price' => $bundle_price,
			'_wsg_bundle_qty'   => $required_qty,
			'_wsg_bundle_index' => $index,
			'_wsg_color_label'  => sanitize_text_field( $item['color_label'] ?? '' ),
			'_wsg_size_label'   => sanitize_text_field( $item['size_label'] ?? '' ),
			'_wsg_color_hex'    => sanitize_hex_color( $item['color_hex'] ?? '' ) ?: '#cccccc',
		);

		/**
		 * Filter cart item data before adding a bundle item to the cart.
		 *
		 * @param array $cart_item_data Cart item data array.
		 * @param array $item          The individual item being added.
		 * @param int   $product_id    The parent product ID.
		 */
		$cart_item_data = apply_filters( 'wsg_cart_item_data', $cart_item_data, $item, $product_id );

		$key = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $variation_attributes, $cart_item_data );

		if ( $key ) {
			$cart_item_keys[] = $key;
			$index++;
		}
	}

	if ( empty( $cart_item_keys ) ) {
		wp_send_json_error( array( 'message' => __( 'Could not add bundle to cart.', 'wsg' ) ) );
	}

	do_action( 'wsg_after_add_to_cart', $product_id, $cart_item_keys, 'bundle' );

	wp_send_json_success(
		array(
			'message'  => __( 'Bundle added to cart', 'wsg' ),
			'cart_url' => wc_get_cart_url(),
			'redirect' => get_option( 'woocommerce_cart_redirect_after_add' ) === 'yes',
		)
	);
}

/**
 * Apply bundle pricing during cart totals calculation.
 *
 * Index 0 gets the full bundle price; all other indices get 0.
 *
 * @param WC_Cart $cart The WooCommerce cart object.
 * @return void
 */
function wsg_apply_bundle_pricing( $cart ) {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}
	if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
		return;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['_wsg_is_bundle'] ) ) {
			continue;
		}
		if ( 0 === (int) $cart_item['_wsg_bundle_index'] ) {
			// Divide bundle price by this item's qty so WC's price × qty = bundle total.
			$per_unit = floatval( $cart_item['_wsg_bundle_price'] ) / max( 1, $cart_item['quantity'] );
			$cart_item['data']->set_price( $per_unit );
			$cart_item['data']->set_regular_price( $per_unit );
		} else {
			$cart_item['data']->set_price( 0 );
			$cart_item['data']->set_regular_price( 0 );
		}
	}
}
add_action( 'woocommerce_before_calculate_totals', 'wsg_apply_bundle_pricing', 20 );

/**
 * Hide bundle sub-items in the cart and mini-cart.
 *
 * Only the item with _wsg_bundle_index === 0 is visible.
 *
 * @param bool   $visible       Whether the item is visible.
 * @param array  $cart_item     Cart item data.
 * @param string $cart_item_key Cart item key.
 * @return bool
 */
function wsg_bundle_item_visible( $visible, $cart_item, $cart_item_key ) {
	if ( ! empty( $cart_item['_wsg_is_bundle'] ) && (int) $cart_item['_wsg_bundle_index'] > 0 ) {
		return false;
	}
	return $visible;
}
add_filter( 'woocommerce_cart_item_visible', 'wsg_bundle_item_visible', 10, 3 );
add_filter( 'woocommerce_widget_cart_item_visible', 'wsg_bundle_item_visible', 10, 3 );
add_filter( 'woocommerce_checkout_cart_item_visible', 'wsg_bundle_item_visible', 10, 3 );

/**
 * Override the cart item name for the visible bundle parent item.
 *
 * Uses the custom display name if set, otherwise "qty x Product Title".
 *
 * @param string $name          The default cart item name.
 * @param array  $cart_item     Cart item data.
 * @param string $cart_item_key Cart item key.
 * @return string
 */
function wsg_bundle_cart_item_name( $name, $cart_item, $cart_item_key ) {
	if ( empty( $cart_item['_wsg_is_bundle'] ) || 0 !== (int) $cart_item['_wsg_bundle_index'] ) {
		return $name;
	}

	$display_name = get_post_meta( $cart_item['product_id'], '_wsg_bundle_display_name', true );

	if ( ! empty( $display_name ) ) {
		$label = esc_html( $display_name );
	} else {
		$label = esc_html( $cart_item['_wsg_bundle_qty'] ) . ' &times; ' . esc_html( $cart_item['data']->get_title() );
	}

	// Preserve the product permalink if available.
	$permalink = get_permalink( $cart_item['product_id'] );
	if ( $permalink ) {
		$name = sprintf( '<a href="%s">%s</a>', esc_url( $permalink ), $label );
	} else {
		$name = $label;
	}

	/**
	 * Filter the bundle display name shown in the cart.
	 *
	 * @param string     $name     The display name.
	 * @param WC_Product $product  The product object.
	 * @param array      $cart_item Cart item data.
	 */
	$name = apply_filters( 'wsg_bundle_display_name', $name, $cart_item['data'], $cart_item );

	return $name;
}
add_filter( 'woocommerce_cart_item_name', 'wsg_bundle_cart_item_name', 10, 3 );

/**
 * Show a non-editable quantity of "1" for bundle parent items.
 *
 * @param string $quantity      The default quantity HTML.
 * @param string $cart_item_key Cart item key.
 * @param array  $cart_item     Cart item data.
 * @return string
 */
function wsg_bundle_cart_item_qty( $quantity, $cart_item_key, $cart_item ) {
	if ( ! empty( $cart_item['_wsg_is_bundle'] ) && 0 === (int) $cart_item['_wsg_bundle_index'] ) {
		return '<span class="wsg-bundle-qty">1</span>';
	}
	return $quantity;
}
add_filter( 'woocommerce_cart_item_quantity', 'wsg_bundle_cart_item_qty', 10, 3 );

/**
 * Hide the "× qty" suffix on checkout order review for bundle parent items.
 *
 * The checkout template appends "× qty" separately via this filter.
 *
 * @param string $quantity_html   The default quantity HTML (e.g. "<strong>× 10</strong>").
 * @param array  $cart_item       Cart item data.
 * @param string $cart_item_key   Cart item key.
 * @return string
 */
function wsg_bundle_checkout_item_qty( $quantity_html, $cart_item, $cart_item_key ) {
	if ( ! empty( $cart_item['_wsg_is_bundle'] ) && 0 === (int) $cart_item['_wsg_bundle_index'] ) {
		return '';
	}
	return $quantity_html;
}
add_filter( 'woocommerce_checkout_cart_item_quantity', 'wsg_bundle_checkout_item_qty', 10, 3 );

/**
 * Show the full bundle price in the cart price column for the parent item.
 *
 * Because set_price uses bundle_price / qty for correct totals math,
 * we override the displayed unit price to show the full bundle price.
 *
 * @param string $price_html      The formatted price HTML.
 * @param array  $cart_item       Cart item data.
 * @param string $cart_item_key   Cart item key.
 * @return string
 */
function wsg_bundle_cart_item_price( $price_html, $cart_item, $cart_item_key ) {
	if ( ! empty( $cart_item['_wsg_is_bundle'] ) && 0 === (int) $cart_item['_wsg_bundle_index'] ) {
		return wc_price( floatval( $cart_item['_wsg_bundle_price'] ) );
	}
	return $price_html;
}
add_filter( 'woocommerce_cart_item_price', 'wsg_bundle_cart_item_price', 10, 3 );

/**
 * Show the full bundle price in the cart subtotal column for the parent item.
 *
 * @param string $subtotal        The formatted subtotal HTML.
 * @param array  $cart_item       Cart item data.
 * @param string $cart_item_key   Cart item key.
 * @return string
 */
function wsg_bundle_cart_item_subtotal( $subtotal, $cart_item, $cart_item_key ) {
	if ( ! empty( $cart_item['_wsg_is_bundle'] ) && 0 === (int) $cart_item['_wsg_bundle_index'] ) {
		return wc_price( floatval( $cart_item['_wsg_bundle_price'] ) );
	}
	return $subtotal;
}
add_filter( 'woocommerce_cart_item_subtotal', 'wsg_bundle_cart_item_subtotal', 10, 3 );

/**
 * Display the sizes breakdown for the visible bundle parent item.
 *
 * Collects all cart items sharing the same _wsg_bundle_id and lists
 * each colour/size combination with a colour dot.
 *
 * @param array $item_data Array of item data for display.
 * @param array $cart_item Cart item data.
 * @return array
 */
function wsg_bundle_item_data( $item_data, $cart_item ) {
	if ( empty( $cart_item['_wsg_is_bundle'] ) || 0 !== (int) $cart_item['_wsg_bundle_index'] ) {
		return $item_data;
	}

	$breakdown = array();

	foreach ( WC()->cart->get_cart() as $other ) {
		if ( isset( $other['_wsg_bundle_id'] ) && $other['_wsg_bundle_id'] === $cart_item['_wsg_bundle_id'] ) {
			$color_hex   = esc_attr( $other['_wsg_color_hex'] ?? '#cccccc' );
			$color_label = esc_html( $other['_wsg_color_label'] ?? '' );
			$size_label  = esc_html( $other['_wsg_size_label'] ?? '' );
			$qty         = $other['quantity'];

			$breakdown[] = '<span class="wsg-breakdown-dot" style="background-color:' . $color_hex . ';"></span> '
				. $color_label . ' &mdash; ' . $size_label . ' &times; ' . $qty;
		}
	}

	if ( ! empty( $breakdown ) ) {
		$item_data[] = array(
			'key'   => __( 'Sizes ordered', 'wsg' ),
			'value' => implode( '<br>', $breakdown ),
		);
	}

	return $item_data;
}
add_filter( 'woocommerce_get_item_data', 'wsg_bundle_item_data', 10, 2 );

/**
 * Cascade removal of all bundle items when any one is removed.
 *
 * Temporarily unhooks itself to prevent infinite recursion, then
 * removes every cart item sharing the same _wsg_bundle_id.
 *
 * @param string  $cart_item_key The key of the removed cart item.
 * @param WC_Cart $cart          The WooCommerce cart object.
 * @return void
 */
function wsg_cascade_bundle_removal( $cart_item_key, $cart ) {
	$removed = $cart->removed_cart_contents[ $cart_item_key ] ?? null;

	if ( ! $removed || empty( $removed['_wsg_is_bundle'] ) ) {
		return;
	}

	$bundle_id = $removed['_wsg_bundle_id'];

	// Unhook to prevent recursion.
	remove_action( 'woocommerce_remove_cart_item', 'wsg_cascade_bundle_removal', 10 );

	foreach ( $cart->get_cart() as $key => $item ) {
		if ( isset( $item['_wsg_bundle_id'] ) && $item['_wsg_bundle_id'] === $bundle_id ) {
			$cart->remove_cart_item( $key );
		}
	}

	// Re-hook.
	add_action( 'woocommerce_remove_cart_item', 'wsg_cascade_bundle_removal', 10, 2 );
}
add_action( 'woocommerce_remove_cart_item', 'wsg_cascade_bundle_removal', 10, 2 );

/**
 * Allow adding bundle items to cart without standard variation form validation.
 *
 * When our plugin adds bundle items programmatically, WooCommerce may reject
 * them because the variation wasn't selected through the standard form.
 * This filter allows our items through.
 *
 * @param bool  $passed          Whether validation passed.
 * @param int   $product_id      The product ID.
 * @param int   $quantity        The quantity being added.
 * @param int   $variation_id    The variation ID (optional).
 * @param array $variations      The variation attributes (optional).
 * @param array $cart_item_data  The cart item data (optional).
 * @return bool
 */
function wsg_bundle_add_to_cart_validation( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
	if ( ! empty( $cart_item_data['_wsg_is_bundle'] ) ) {
		return true;
	}
	return $passed;
}
add_filter( 'woocommerce_add_to_cart_validation', 'wsg_bundle_add_to_cart_validation', 10, 6 );
