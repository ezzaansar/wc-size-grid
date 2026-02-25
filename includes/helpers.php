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
