<?php
/**
 * Helper / utility functions for the WSG plugin.
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detect colour and size attributes on a variable product.
 *
 * @param WC_Product_Variable $product Variable product instance.
 * @return array { 'color' => 'pa_color'|null, 'size' => 'pa_size'|null }
 */
function wsg_detect_attributes( $product ) {
	$variation_attributes = $product->get_variation_attributes();
	$color_attr           = null;
	$size_attr            = null;

	foreach ( array_keys( $variation_attributes ) as $attr_name ) {
		$label    = strtolower( wc_attribute_label( $attr_name ) );
		$slug_lc  = strtolower( $attr_name );

		// Check for colour attribute.
		if ( false !== strpos( $label, 'color' ) || false !== strpos( $label, 'colour' )
			|| false !== strpos( $slug_lc, 'color' ) || false !== strpos( $slug_lc, 'colour' ) ) {
			$color_attr = $attr_name;
		}

		// Check for size attribute.
		if ( false !== strpos( $label, 'size' ) || false !== strpos( $slug_lc, 'size' ) ) {
			$size_attr = $attr_name;
		}
	}

	if ( is_null( $color_attr ) || is_null( $size_attr ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'WSG: Could not detect color/size attributes for product #' . $product->get_id() . '. Found attributes: ' . implode( ', ', array_keys( $variation_attributes ) ) );
		}
	}

	return array(
		'color' => $color_attr,
		'size'  => $size_attr,
	);
}

/**
 * Resolve a colour attribute term to a hex value.
 *
 * Resolution order:
 * 1. Term meta (product_attribute_color, color, _swatches_color)
 * 2. Built-in colour map (filterable via 'wsg_color_map')
 * 3. Fallback grey (#cccccc)
 *
 * @param string $term_name Term name, e.g. "Navy".
 * @param string $term_slug Term slug, e.g. "navy".
 * @param int    $term_id   Term ID for meta lookups.
 * @return string Hex colour code, e.g. "#1e3a5f".
 */
function wsg_get_color_hex( $term_name, $term_slug = '', $term_id = 0 ) {
	// 1. Check term meta if we have a term ID.
	if ( $term_id > 0 ) {
		$meta_keys = array( 'product_attribute_color', 'color', '_swatches_color' );

		foreach ( $meta_keys as $meta_key ) {
			$value = get_term_meta( $term_id, $meta_key, true );

			if ( ! empty( $value ) && 0 === strpos( $value, '#' ) ) {
				return $value;
			}
		}
	}

	// 2. Built-in colour map (filterable).
	$map = apply_filters(
		'wsg_color_map',
		array(
			'black'        => '#000000',
			'white'        => '#ffffff',
			'red'          => '#ff0000',
			'blue'         => '#0000ff',
			'navy'         => '#1e3a5f',
			'royal-blue'   => '#4169e1',
			'sky-blue'     => '#87ceeb',
			'light-blue'   => '#add8e6',
			'green'        => '#008000',
			'lime'         => '#00ff00',
			'forest-green' => '#228b22',
			'yellow'       => '#ffff00',
			'gold'         => '#ffd700',
			'orange'       => '#ffa500',
			'pink'         => '#ffc0cb',
			'hot-pink'     => '#ff69b4',
			'purple'       => '#800080',
			'violet'       => '#ee82ee',
			'brown'        => '#8b4513',
			'tan'          => '#d2b48c',
			'beige'        => '#f5f5dc',
			'cream'        => '#fffdd0',
			'grey'         => '#808080',
			'gray'         => '#808080',
			'light-grey'   => '#d3d3d3',
			'dark-grey'    => '#a9a9a9',
			'charcoal'     => '#36454f',
			'silver'       => '#c0c0c0',
			'maroon'       => '#800000',
			'teal'         => '#008080',
			'cyan'         => '#00ffff',
			'coral'        => '#ff7f50',
			'salmon'       => '#fa8072',
			'burgundy'     => '#800020',
			'khaki'        => '#c3b091',
			'olive'        => '#808000',
			'heather-grey' => '#b6b6b4',
		)
	);

	// 3. Try slug first, then name (lowercased, spaces → hyphens).
	$slug_key = strtolower( $term_slug );
	if ( ! empty( $slug_key ) && isset( $map[ $slug_key ] ) ) {
		return $map[ $slug_key ];
	}

	$name_key = str_replace( ' ', '-', strtolower( $term_name ) );
	if ( isset( $map[ $name_key ] ) ) {
		return $map[ $name_key ];
	}

	// 4. Fallback.
	return '#cccccc';
}

/**
 * Detect the logo position attribute taxonomy.
 *
 * Searches all registered WooCommerce product attributes for one whose
 * slug or label contains both "logo" and "position". Returns the
 * taxonomy name (e.g. 'pa_logo-position') or null.
 *
 * @return string|null Taxonomy name, or null if not found.
 */
function wsg_detect_logo_position_attribute() {
	$taxonomies = wc_get_attribute_taxonomies();

	foreach ( $taxonomies as $tax ) {
		$slug_lc  = strtolower( $tax->attribute_name );
		$label_lc = strtolower( $tax->attribute_label );

		if (
			( false !== strpos( $slug_lc, 'logo' ) && false !== strpos( $slug_lc, 'position' ) ) ||
			( false !== strpos( $label_lc, 'logo' ) && false !== strpos( $label_lc, 'position' ) )
		) {
			return wc_attribute_taxonomy_name( $tax->attribute_name );
		}
	}

	return null;
}

/**
 * Get logo position labels.
 *
 * If a WooCommerce attribute containing "logo" and "position" exists,
 * its terms are used as positions. Otherwise falls back to a default list.
 * Manage positions via Products → Attributes → "Logo Position".
 *
 * @return array Associative array of slug => label.
 */
function wsg_get_logo_position_labels() {
	$taxonomy = wsg_detect_logo_position_attribute();

	if ( $taxonomy ) {
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		// Apply WooCommerce custom ordering if set.
		if ( ! is_wp_error( $terms ) && function_exists( 'wc_get_attribute' ) ) {
			usort(
				$terms,
				function ( $a, $b ) {
					$a_order = (int) get_term_meta( $a->term_id, 'order', true );
					$b_order = (int) get_term_meta( $b->term_id, 'order', true );
					return $a_order - $b_order;
				}
			);
		}
		$labels = array();

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$labels[ $term->slug ] = $term->name;
			}
		}

		if ( ! empty( $labels ) ) {
			return apply_filters( 'wsg_logo_position_labels', $labels );
		}
	}

	// Fallback: hardcoded defaults.
	return apply_filters(
		'wsg_logo_position_labels',
		array(
			'left-chest'   => __( 'Left Chest', 'wsg' ),
			'right-chest'  => __( 'Right Chest', 'wsg' ),
			'left-arm'     => __( 'Left Arm', 'wsg' ),
			'right-arm'    => __( 'Right Arm', 'wsg' ),
			'back'         => __( 'Back', 'wsg' ),
			'front-center' => __( 'Front Centre', 'wsg' ),
		)
	);
}

/**
 * Check if a product belongs to a category with auto-enabled logo customization.
 *
 * Returns false if no category match, or a config array if matched:
 *   [ 'positions' => [...slugs], 'print_price' => float, 'embroidery_price' => float ]
 *
 * Per-product _wsg_logo_enabled always takes priority over this.
 *
 * @param int $product_id Product ID.
 * @return array|false Logo config or false.
 */
function wsg_get_category_logo_config( $product_id ) {
	// Categories that get ALL logo positions.
	$all_position_cats = array(
		'personalised-hoodies',
		'personalised-jackets',
		'personalised-knitwear',
		'personalised-loungewear',
		'personalised-polo-shirts',
		'personalised-t-shirts',
	);

	// Categories that only get left/right breast positions.
	$limited_cats = array(
		'personalised-aprons',
	);

	$product_cats = wc_get_product_cat_ids( $product_id );
	if ( empty( $product_cats ) ) {
		return false;
	}

	// Batch-fetch category slugs in a single query.
	$terms     = get_terms(
		array(
			'taxonomy' => 'product_cat',
			'include'  => $product_cats,
			'fields'   => 'slugs',
		)
	);
	$cat_slugs = is_wp_error( $terms ) ? array() : $terms;

	$all_labels = wsg_get_logo_position_labels();

	// Check "all positions" categories first.
	if ( array_intersect( $cat_slugs, $all_position_cats ) ) {
		return array(
			'positions'       => array_keys( $all_labels ),
			'print_price'     => 5.0,
			'embroidery_price' => 8.0,
		);
	}

	// Check "limited positions" categories.
	if ( array_intersect( $cat_slugs, $limited_cats ) ) {
		// Only breast/chest positions.
		$limited_slugs = array();
		foreach ( array_keys( $all_labels ) as $slug ) {
			if ( strpos( $slug, 'breast' ) !== false || strpos( $slug, 'chest' ) !== false ) {
				// Only left and right, not center/centre.
				if ( strpos( $slug, 'left' ) !== false || strpos( $slug, 'right' ) !== false ) {
					$limited_slugs[] = $slug;
				}
			}
		}
		if ( ! empty( $limited_slugs ) ) {
			return array(
				'positions'        => $limited_slugs,
				'print_price'      => 5.0,
				'embroidery_price' => 8.0,
			);
		}
	}

	return false;
}

/**
 * Look up the discount amount for a given quantity from a set of tiers.
 *
 * @param array $tiers Array of tier arrays with 'min', 'max', 'discount' keys.
 * @param int   $qty   Total quantity to match against tiers.
 * @return float Discount amount, or 0 if no matching tier.
 */
function wsg_get_discount_for_qty( $tiers, $qty ) {
	if ( empty( $tiers ) || $qty <= 0 ) {
		return 0;
	}

	foreach ( $tiers as $tier ) {
		$min = isset( $tier['min'] ) ? absint( $tier['min'] ) : 0;
		$max = isset( $tier['max'] ) ? absint( $tier['max'] ) : 0;

		if ( $qty >= $min && ( empty( $max ) || $qty <= $max ) ) {
			return floatval( $tier['discount'] );
		}
	}

	return 0;
}

/**
 * Parse and validate logo customization data from $_POST.
 *
 * Validates the logo attachment, positions, and method against the product's
 * allowed settings. Returns an array of logo data suitable for cart item meta,
 * or null if no logo was submitted.
 *
 * Sends a JSON error response and terminates if validation fails.
 *
 * @param int $product_id Product ID for looking up allowed positions/prices.
 * @return array|null Logo data array, or null if no logo data submitted.
 */
function wsg_parse_logo_data_from_post( $product_id ) {
	$logo_attachment_id  = isset( $_POST['logo_attachment_id'] ) ? absint( $_POST['logo_attachment_id'] ) : 0;
	$logo_positions_json = isset( $_POST['logo_positions'] ) ? wp_unslash( $_POST['logo_positions'] ) : '[]';
	$logo_positions      = json_decode( $logo_positions_json, true );
	$logo_positions      = is_array( $logo_positions ) ? array_map( 'sanitize_text_field', $logo_positions ) : array();
	$logo_method         = isset( $_POST['logo_method'] ) ? sanitize_text_field( wp_unslash( $_POST['logo_method'] ) ) : '';
	$logo_notes          = isset( $_POST['logo_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['logo_notes'] ) ) : '';

	if ( empty( $logo_positions ) || ! $logo_method ) {
		return null;
	}

	if ( $logo_attachment_id && ! wp_attachment_is_image( $logo_attachment_id ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid logo file.', 'wsg' ) ) );
	}

	$allowed_positions = get_post_meta( $product_id, '_wsg_logo_positions', true );
	$allowed_positions = is_array( $allowed_positions ) ? $allowed_positions : array();
	foreach ( $logo_positions as $logo_pos ) {
		if ( ! in_array( $logo_pos, $allowed_positions, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid logo position.', 'wsg' ) ) );
		}
	}

	if ( ! in_array( $logo_method, array( 'print', 'embroidery' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid logo method.', 'wsg' ) ) );
	}

	$logo_surcharge = ( 'embroidery' === $logo_method )
		? floatval( get_post_meta( $product_id, '_wsg_logo_embroidery_price', true ) )
		: floatval( get_post_meta( $product_id, '_wsg_logo_print_price', true ) );

	$data = array(
		'attachment_id' => $logo_attachment_id,
		'positions'     => $logo_positions,
		'method'        => $logo_method,
		'surcharge'     => $logo_surcharge,
		'notes'         => $logo_notes,
	);

	if ( $logo_attachment_id ) {
		$data['url'] = wp_get_attachment_url( $logo_attachment_id );
	}

	return $data;
}

/**
 * Add logo customization fields to a cart item data array.
 *
 * @param array      $cart_item_data Cart item data to augment.
 * @param array|null $logo_data      Logo data from wsg_parse_logo_data_from_post(), or null.
 * @return array Modified cart item data.
 */
function wsg_add_logo_to_cart_item_data( $cart_item_data, $logo_data ) {
	if ( ! $logo_data ) {
		return $cart_item_data;
	}

	if ( ! empty( $logo_data['attachment_id'] ) ) {
		$cart_item_data['_wsg_logo_attachment_id'] = $logo_data['attachment_id'];
		$cart_item_data['_wsg_logo_url']           = $logo_data['url'] ?? '';
	}
	$cart_item_data['_wsg_logo_positions'] = $logo_data['positions'];
	$cart_item_data['_wsg_logo_method']    = $logo_data['method'];
	$cart_item_data['_wsg_logo_surcharge'] = $logo_data['surcharge'];
	if ( ! empty( $logo_data['notes'] ) ) {
		$cart_item_data['_wsg_logo_notes'] = $logo_data['notes'];
	}

	return $cart_item_data;
}

/**
 * Build logo display rows for woocommerce_get_item_data.
 *
 * Returns an array of item data rows showing logo position, method,
 * thumbnail, surcharge, and notes. Returns an empty array if the
 * cart item has no logo data.
 *
 * @param array $cart_item Cart item data.
 * @return array Array of item data rows (each with 'key' and 'value').
 */
function wsg_get_logo_item_display_data( $cart_item ) {
	$logo_positions_raw = isset( $cart_item['_wsg_logo_positions'] ) ? $cart_item['_wsg_logo_positions'] : array();
	if ( ! is_array( $logo_positions_raw ) ) {
		// Backward compat: single position string.
		$logo_positions_raw = array( $logo_positions_raw );
	}

	if ( empty( $logo_positions_raw ) || empty( $cart_item['_wsg_logo_method'] ) ) {
		return array();
	}

	$rows = array();

	$position_labels = wsg_get_logo_position_labels();
	$pos_label_parts = array();
	foreach ( $logo_positions_raw as $logo_pos ) {
		$pos_label_parts[] = isset( $position_labels[ $logo_pos ] ) ? $position_labels[ $logo_pos ] : $logo_pos;
	}
	$method_label = ( 'embroidery' === $cart_item['_wsg_logo_method'] )
		? __( 'Embroidery', 'wsg' )
		: __( 'Print', 'wsg' );

	$logo_display = esc_html( implode( ', ', $pos_label_parts ) ) . ' &mdash; ' . esc_html( $method_label );

	if ( ! empty( $cart_item['_wsg_logo_url'] ) ) {
		$logo_display .= '<br><img src="' . esc_url( $cart_item['_wsg_logo_url'] ) . '" alt="'
			. esc_attr__( 'Logo', 'wsg' ) . '" style="max-width:40px;max-height:40px;vertical-align:middle;margin-top:4px;">';
	}

	$rows[] = array(
		'key'   => __( 'Logo', 'wsg' ),
		'value' => $logo_display,
	);

	$surcharge = isset( $cart_item['_wsg_logo_surcharge'] ) ? floatval( $cart_item['_wsg_logo_surcharge'] ) : 0;
	if ( $surcharge > 0 ) {
		$rows[] = array(
			'key'   => __( 'Logo surcharge', 'wsg' ),
			'value' => '+' . wp_kses_post( wc_price( $surcharge ) ) . ' ' . esc_html__( 'per item', 'wsg' ),
		);
	}

	if ( ! empty( $cart_item['_wsg_logo_notes'] ) ) {
		$rows[] = array(
			'key'   => __( 'Logo notes', 'wsg' ),
			'value' => esc_html( $cart_item['_wsg_logo_notes'] ),
		);
	}

	return $rows;
}
