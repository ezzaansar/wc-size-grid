<?php
/**
 * Test data setup script for Workwear Size Grid.
 *
 * Run via: http://workwear-express.local/wp-content/plugins/wc-size-grid/tests/setup-test-data.php
 * Or via WP-CLI: wp eval-file wp-content/plugins/wc-size-grid/tests/setup-test-data.php
 *
 * Creates:
 * - pa_color attribute with 4 colour terms
 * - pa_size attribute with 5 size terms
 * - "Test Polo Shirt" variable product (Product Mode) with all variations
 * - "Test Bundle Polo" variable product (Bundle Mode) with all variations
 */

// Load WordPress.
require_once dirname( __FILE__, 5 ) . '/wp-load.php';

if ( ! current_user_can( 'manage_options' ) && php_sapi_name() !== 'cli' ) {
	wp_die( 'Admin access required.' );
}

echo "<pre>\n";
echo "=== WSG Test Data Setup ===\n\n";

// ─── 1. Create Color Attribute ───────────────────────────────────────────────

$color_attr_id = wc_attribute_taxonomy_id_by_name( 'color' );
if ( ! $color_attr_id ) {
	$color_attr_id = wc_create_attribute( array(
		'name'         => 'Color',
		'slug'         => 'color',
		'type'         => 'select',
		'order_by'     => 'menu_order',
		'has_archives' => false,
	) );
	// Register the taxonomy so terms can be added immediately.
	register_taxonomy( 'pa_color', 'product', array( 'hierarchical' => false ) );
	echo "Created pa_color attribute (ID: $color_attr_id)\n";
} else {
	if ( ! taxonomy_exists( 'pa_color' ) ) {
		register_taxonomy( 'pa_color', 'product', array( 'hierarchical' => false ) );
	}
	echo "pa_color attribute already exists (ID: $color_attr_id)\n";
}

$colors = array(
	'navy'         => 'Navy',
	'black'        => 'Black',
	'hot-pink'     => 'Hot Pink',
	'heather-grey' => 'Heather Grey',
);

$color_terms = array();
foreach ( $colors as $slug => $name ) {
	$term = get_term_by( 'slug', $slug, 'pa_color' );
	if ( ! $term ) {
		$result = wp_insert_term( $name, 'pa_color', array( 'slug' => $slug ) );
		if ( ! is_wp_error( $result ) ) {
			$color_terms[ $slug ] = $result['term_id'];
			echo "  Created color term: $name ($slug)\n";
		} else {
			echo "  ERROR creating color term $name: " . $result->get_error_message() . "\n";
		}
	} else {
		$color_terms[ $slug ] = $term->term_id;
		echo "  Color term exists: $name ($slug)\n";
	}
}

// ─── 2. Create Size Attribute ────────────────────────────────────────────────

$size_attr_id = wc_attribute_taxonomy_id_by_name( 'size' );
if ( ! $size_attr_id ) {
	$size_attr_id = wc_create_attribute( array(
		'name'         => 'Size',
		'slug'         => 'size',
		'type'         => 'select',
		'order_by'     => 'menu_order',
		'has_archives' => false,
	) );
	register_taxonomy( 'pa_size', 'product', array( 'hierarchical' => false ) );
	echo "Created pa_size attribute (ID: $size_attr_id)\n";
} else {
	if ( ! taxonomy_exists( 'pa_size' ) ) {
		register_taxonomy( 'pa_size', 'product', array( 'hierarchical' => false ) );
	}
	echo "pa_size attribute already exists (ID: $size_attr_id)\n";
}

$sizes = array( 'XS', 'S', 'M', 'L', 'XL' );
$size_terms = array();
foreach ( $sizes as $size_name ) {
	$slug = sanitize_title( $size_name );
	$term = get_term_by( 'slug', $slug, 'pa_size' );
	if ( ! $term ) {
		$result = wp_insert_term( $size_name, 'pa_size', array( 'slug' => $slug ) );
		if ( ! is_wp_error( $result ) ) {
			$size_terms[ $slug ] = $result['term_id'];
			echo "  Created size term: $size_name ($slug)\n";
		}
	} else {
		$size_terms[ $slug ] = $term->term_id;
		echo "  Size term exists: $size_name ($slug)\n";
	}
}

echo "\n";

// ─── Helper: Create variable product with variations ─────────────────────────

function wsg_create_test_product( $title, $color_slugs, $size_slugs, $base_price = 10.00 ) {
	// Check if product already exists.
	$existing = get_posts( array(
		'post_type'  => 'product',
		'title'      => $title,
		'post_status'=> 'publish',
		'numberposts'=> 1,
	) );
	if ( ! empty( $existing ) ) {
		echo "Product '$title' already exists (ID: {$existing[0]->ID})\n";
		return $existing[0]->ID;
	}

	$product = new WC_Product_Variable();
	$product->set_name( $title );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( '' );

	// Set attributes.
	$attributes = array();

	$color_attr = new WC_Product_Attribute();
	$color_attr->set_name( 'pa_color' );
	$color_attr->set_options( array_values( $color_slugs ) );
	$color_attr->set_position( 0 );
	$color_attr->set_visible( true );
	$color_attr->set_variation( true );
	$attributes[] = $color_attr;

	$size_attr = new WC_Product_Attribute();
	$size_attr->set_name( 'pa_size' );
	$size_attr->set_options( array_values( $size_slugs ) );
	$size_attr->set_position( 1 );
	$size_attr->set_visible( true );
	$size_attr->set_variation( true );
	$attributes[] = $size_attr;

	$product->set_attributes( $attributes );
	$product_id = $product->save();

	echo "Created product: $title (ID: $product_id)\n";

	// Create variations for each color × size combination.
	$count = 0;
	foreach ( $color_slugs as $color_slug ) {
		foreach ( $size_slugs as $size_slug ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product_id );
			$variation->set_attributes( array(
				'pa_color' => $color_slug,
				'pa_size'  => $size_slug,
			) );
			$variation->set_regular_price( $base_price );
			$variation->set_stock_status( 'instock' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 50 );
			$variation->save();
			$count++;
		}
	}

	echo "  Created $count variations\n";

	// Sync variable product data.
	WC_Product_Variable::sync( $product_id );

	return $product_id;
}

// ─── 3. Create Product Mode Test Product ─────────────────────────────────────

echo "--- Product Mode Test ---\n";
$product_id = wsg_create_test_product(
	'Test Polo Shirt (Product Mode)',
	array_keys( $color_terms ),
	array_keys( $size_terms ),
	12.50
);

// Enable WSG + set product mode with discount tiers.
update_post_meta( $product_id, '_wsg_enabled', 'yes' );
update_post_meta( $product_id, '_wsg_mode', 'product' );
update_post_meta( $product_id, '_wsg_discount_tiers', array(
	array( 'min' => 5,  'max' => 9,  'discount' => 0.50 ),
	array( 'min' => 10, 'max' => 24, 'discount' => 1.00 ),
	array( 'min' => 25, 'max' => '',  'discount' => 2.00 ),
) );
echo "  Enabled Size Grid (product mode) with 3 discount tiers\n";
echo "  URL: " . get_permalink( $product_id ) . "\n\n";

// ─── 4. Create Bundle Mode Test Product ──────────────────────────────────────

echo "--- Bundle Mode Test ---\n";
$bundle_id = wsg_create_test_product(
	'Test Polo Shirt (Bundle Mode)',
	array_keys( $color_terms ),
	array_keys( $size_terms ),
	8.00
);

// Enable WSG + set bundle mode.
update_post_meta( $bundle_id, '_wsg_enabled', 'yes' );
update_post_meta( $bundle_id, '_wsg_mode', 'bundle' );
update_post_meta( $bundle_id, '_wsg_bundle_qty', 16 );
update_post_meta( $bundle_id, '_wsg_bundle_price', 99.99 );
update_post_meta( $bundle_id, '_wsg_bundle_display_name', '16 × Best Workwear Polo Shirts' );
echo "  Enabled Size Grid (bundle mode): 16 items for £99.99\n";
echo "  URL: " . get_permalink( $bundle_id ) . "\n\n";

// ─── 5. Set one variation out of stock ───────────────────────────────────────

// Find first variation of bundle product and set it out of stock.
$bundle_product = wc_get_product( $bundle_id );
$children = $bundle_product->get_children();
if ( ! empty( $children ) ) {
	$oos_variation = wc_get_product( $children[0] );
	if ( $oos_variation ) {
		$oos_variation->set_stock_status( 'outofstock' );
		$oos_variation->set_stock_quantity( 0 );
		$oos_variation->save();
		echo "Set variation #{$children[0]} to out-of-stock for testing disabled rows\n";
	}
}

echo "\n=== Setup Complete ===\n";
echo "Now visit the product URLs above to test the Size Grid.\n";
echo "</pre>\n";
