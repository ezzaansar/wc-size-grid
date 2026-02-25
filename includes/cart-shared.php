<?php
/**
 * Shared cart/order functionality.
 *
 * Saves size grid metadata to WooCommerce order line items during checkout.
 * Handles both Product mode (bulk discounts) and Bundle mode (grouped items).
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_checkout_create_order_line_item', 'wsg_save_order_item_meta', 10, 4 );

/**
 * Save size grid metadata to order line items during checkout.
 *
 * Uses WC CRUD API ($item->add_meta_data) for HPOS compatibility.
 *
 * @param WC_Order_Item_Product $item           Order line item.
 * @param string                $cart_item_key  Cart item key.
 * @param array                 $values         Cart item data.
 * @param WC_Order              $order          Order instance.
 */
function wsg_save_order_item_meta( $item, $cart_item_key, $values, $order ) {

	// Bundle items — visible parent (index 0).
	if ( ! empty( $values['_wsg_is_bundle'] ) && isset( $values['_wsg_bundle_index'] ) && 0 === (int) $values['_wsg_bundle_index'] ) {

		$item->add_meta_data( '_wsg_is_bundle', 'yes' );
		$item->add_meta_data( '_wsg_bundle_id', sanitize_text_field( $values['_wsg_bundle_id'] ) );
		$item->add_meta_data( '_wsg_bundle_price', floatval( $values['_wsg_bundle_price'] ) );
		$item->add_meta_data( '_wsg_bundle_qty', absint( $values['_wsg_bundle_qty'] ) );

		$display_name = get_post_meta( $values['product_id'], '_wsg_bundle_display_name', true );
		if ( ! empty( $display_name ) ) {
			$item->add_meta_data( '_wsg_bundle_display_name', sanitize_text_field( $display_name ) );
		}

		// Build human-readable sizes breakdown from all bundle items in cart.
		$breakdown = array();
		foreach ( WC()->cart->get_cart() as $other ) {
			if ( isset( $other['_wsg_bundle_id'] ) && $other['_wsg_bundle_id'] === $values['_wsg_bundle_id'] ) {
				$color = isset( $other['_wsg_color_label'] ) ? sanitize_text_field( $other['_wsg_color_label'] ) : '';
				$size  = isset( $other['_wsg_size_label'] ) ? sanitize_text_field( $other['_wsg_size_label'] ) : '';
				$qty   = absint( $other['quantity'] );

				$breakdown[] = $color . ' ' . $size . ' &times;' . $qty;
			}
		}

		$item->add_meta_data( 'Sizes ordered', implode( ', ', $breakdown ) );

		// Logo meta for bundle parent.
		wsg_save_logo_order_meta( $item, $values );

		return;
	}

	// Bundle sub-items (index > 0).
	if ( ! empty( $values['_wsg_is_bundle'] ) && isset( $values['_wsg_bundle_index'] ) && (int) $values['_wsg_bundle_index'] > 0 ) {

		$item->add_meta_data( '_wsg_is_bundle', 'yes' );
		$item->add_meta_data( '_wsg_bundle_id', sanitize_text_field( $values['_wsg_bundle_id'] ) );
		$item->add_meta_data( '_wsg_color_label', isset( $values['_wsg_color_label'] ) ? sanitize_text_field( $values['_wsg_color_label'] ) : '' );
		$item->add_meta_data( '_wsg_size_label', isset( $values['_wsg_size_label'] ) ? sanitize_text_field( $values['_wsg_size_label'] ) : '' );

		return;
	}

	// Product mode items with discount (recalculated dynamically).
	if ( empty( $values['_wsg_is_bundle'] ) && ! empty( $values['_wsg_group_id'] ) ) {

		$pid       = $values['product_id'];
		$total_qty = 0;

		foreach ( WC()->cart->get_cart() as $other ) {
			if ( empty( $other['_wsg_group_id'] ) || ! empty( $other['_wsg_is_bundle'] ) ) {
				continue;
			}
			if ( (int) $other['product_id'] === (int) $pid ) {
				$total_qty += $other['quantity'];
			}
		}

		$tiers = get_post_meta( $pid, '_wsg_discount_tiers', true );
		if ( is_string( $tiers ) ) {
			$tiers = json_decode( $tiers, true );
		}
		$tiers = is_array( $tiers ) ? $tiers : array();
		$discount = wsg_get_discount_for_qty( $tiers, $total_qty );
		$discount = floatval( apply_filters( 'wsg_discount_amount', $discount, $pid, $total_qty ) );

		if ( $discount > 0 ) {
			$item->add_meta_data( '_wsg_discount', $discount );
			$item->add_meta_data(
				'Bulk discount',
				'-' . wc_price( $discount ) . ' ' . __( 'per item', 'wsg' )
			);
		}

		// Logo meta for product mode items.
		wsg_save_logo_order_meta( $item, $values );
	}
}

/**
 * Save logo customization metadata to an order line item.
 *
 * @param WC_Order_Item_Product $item   Order line item.
 * @param array                 $values Cart item data.
 */
function wsg_save_logo_order_meta( $item, $values ) {
	// Determine positions — support both new array and legacy single-position string.
	$logo_positions = array();
	if ( ! empty( $values['_wsg_logo_positions'] ) && is_array( $values['_wsg_logo_positions'] ) ) {
		$logo_positions = $values['_wsg_logo_positions'];
	} elseif ( ! empty( $values['_wsg_logo_position'] ) ) {
		$logo_positions = array( $values['_wsg_logo_position'] );
	}

	if ( empty( $logo_positions ) && empty( $values['_wsg_logo_attachment_id'] ) ) {
		return;
	}

	if ( ! empty( $values['_wsg_logo_attachment_id'] ) ) {
		$item->add_meta_data( '_wsg_logo_attachment_id', absint( $values['_wsg_logo_attachment_id'] ) );
		$item->add_meta_data( '_wsg_logo_url', esc_url_raw( $values['_wsg_logo_url'] ?? '' ) );
	}

	$item->add_meta_data( '_wsg_logo_positions', wp_json_encode( $logo_positions ) );
	$item->add_meta_data( '_wsg_logo_method', sanitize_text_field( $values['_wsg_logo_method'] ?? '' ) );
	$item->add_meta_data( '_wsg_logo_surcharge', floatval( $values['_wsg_logo_surcharge'] ?? 0 ) );

	if ( ! empty( $values['_wsg_logo_notes'] ) ) {
		$item->add_meta_data( '_wsg_logo_notes', sanitize_textarea_field( $values['_wsg_logo_notes'] ) );
		$item->add_meta_data( 'Logo notes', sanitize_textarea_field( $values['_wsg_logo_notes'] ) );
	}

	// Human-readable position + method display.
	$position_labels = wsg_get_logo_position_labels();
	$pos_label_parts = array();
	foreach ( $logo_positions as $logo_pos ) {
		$pos_label_parts[] = isset( $position_labels[ $logo_pos ] ) ? $position_labels[ $logo_pos ] : $logo_pos;
	}
	$method_label = ( 'embroidery' === ( $values['_wsg_logo_method'] ?? '' ) )
		? __( 'Embroidery', 'wsg' )
		: __( 'Print', 'wsg' );

	$item->add_meta_data( 'Logo', implode( ', ', $pos_label_parts ) . ' - ' . $method_label );
}
