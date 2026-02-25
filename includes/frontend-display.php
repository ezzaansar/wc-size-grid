<?php
/**
 * Frontend display: asset loading, variation map, container, form hiding, bundle price.
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────────────────────
 * Hook registrations
 * ─────────────────────────────────────────── */

add_action( 'wp_enqueue_scripts', 'wsg_enqueue_assets' );
add_action( 'wp_head', 'wsg_hide_default_form' );
add_action( 'woocommerce_before_add_to_cart_form', 'wsg_render_container', 15 );
add_filter( 'woocommerce_get_price_html', 'wsg_bundle_price_html', 10, 2 );

/* ───────────────────────────────────────────
 * 1. Asset Loading
 * ─────────────────────────────────────────── */

/**
 * Enqueue frontend CSS and JS on enabled variable product pages.
 *
 * Builds the variation map and localizes all data the JS app needs.
 *
 * @return void
 */
function wsg_enqueue_assets() {
	if ( ! is_product() ) {
		return;
	}

	$product = wc_get_product( get_the_ID() );

	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return;
	}

	$enabled = get_post_meta( $product->get_id(), '_wsg_enabled', true ) === 'yes';
	$enabled = apply_filters( 'wsg_is_enabled', $enabled, $product );

	if ( ! $enabled ) {
		return;
	}

	/* --- Styles --- */
	wp_enqueue_style(
		'wsg-frontend-css',
		WSG_PLUGIN_URL . 'assets/frontend.css',
		array(),
		WSG_VERSION
	);

	/* --- Script --- */
	wp_enqueue_script(
		'wsg-frontend-js',
		WSG_PLUGIN_URL . 'assets/frontend.js',
		array( 'jquery' ),
		WSG_VERSION,
		true
	);

	/* --- Variation map --- */
	$color_size_map = wsg_build_variation_map( $product );

	/* --- Mode & settings --- */
	$mode         = get_post_meta( $product->get_id(), '_wsg_mode', true ) ?: 'product';
	$tiers        = get_post_meta( $product->get_id(), '_wsg_discount_tiers', true ) ?: array();
	if ( is_string( $tiers ) ) {
		$tiers = json_decode( $tiers, true ) ?: array();
	}
	$bundle_qty   = absint( get_post_meta( $product->get_id(), '_wsg_bundle_qty', true ) );
	$bundle_price = floatval( get_post_meta( $product->get_id(), '_wsg_bundle_price', true ) );

	/* --- Localized data --- */
	$data = array(
		'ajaxUrl'           => WC_AJAX::get_endpoint( 'wsg_add_to_cart' ),
		'nonce'             => wp_create_nonce( 'wsg_nonce' ),
		'productId'         => $product->get_id(),
		'mode'              => $mode,
		'bundleQty'         => $bundle_qty,
		'bundlePrice'       => $bundle_price,
		'tiers'             => $mode === 'product' ? array_values( $tiers ) : array(),
		'currencySymbol'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
		'priceFormat'       => get_woocommerce_price_format(),
		'decimalSeparator'  => wc_get_price_decimal_separator(),
		'thousandSeparator' => wc_get_price_thousand_separator(),
		'decimals'          => wc_get_price_decimals(),
		'colorSizeMap'      => $color_size_map,
		'i18n'              => array(
			'addToCart'       => __( 'Add to Cart', 'wsg' ),
			'addBundle'       => __( 'Add Bundle to Cart', 'wsg' ),
			'adding'          => __( 'Adding...', 'wsg' ),
			'added'           => __( 'Added to cart', 'wsg' ),
			'outOfStock'      => __( 'Out of stock', 'wsg' ),
			'youSave'         => __( 'You save %s per item', 'wsg' ),
			'totalItems'      => __( 'Total: %d items', 'wsg' ),
			'itemsOf'         => __( '%1$d of %2$d items', 'wsg' ),
			'remaining'       => __( '%d remaining', 'wsg' ),
			'bundlePrice'     => __( 'Bundle price: %s', 'wsg' ),
			'selectColour'    => __( 'Select a colour', 'wsg' ),
			'selectSize'      => __( 'Select sizes and quantities', 'wsg' ),
			'size'            => __( 'Size', 'wsg' ),
			'price'           => __( 'Price', 'wsg' ),
			'qty'             => __( 'Qty', 'wsg' ),
			'total'           => __( 'Total: %s', 'wsg' ),
			'error'           => __( 'An error occurred. Please try again.', 'wsg' ),
			'logoTitle'       => __( 'Logo Customization', 'wsg' ),
			'logoOptional'    => __( '(Optional)', 'wsg' ),
			'uploadLogo'      => __( 'Upload Logo', 'wsg' ),
			'changeLogo'      => __( 'Change Logo', 'wsg' ),
			'removeLogo'      => __( 'Remove', 'wsg' ),
			'uploading'       => __( 'Uploading...', 'wsg' ),
			'position'        => __( 'Position', 'wsg' ),
			'method'          => __( 'Method', 'wsg' ),
			'print'           => __( 'Print', 'wsg' ),
			'embroidery'      => __( 'Embroidery', 'wsg' ),
			'logoSurcharge'   => __( '+%s per item for logo', 'wsg' ),
			'fileTooLarge'    => __( 'File is too large. Maximum size is 5 MB.', 'wsg' ),
			'invalidFileType' => __( 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.', 'wsg' ),
			'selectPosition'  => __( '-- Select position --', 'wsg' ),
			'dragDrop'        => __( 'or drag and drop', 'wsg' ),
			'logoUploaded'    => __( 'Logo uploaded', 'wsg' ),
			'stepPosition'    => __( 'Position', 'wsg' ),
			'stepMethod'      => __( 'Method', 'wsg' ),
			'stepLogo'        => __( 'Logo', 'wsg' ),
			'selectPositions' => __( 'Select where you want your logo applied', 'wsg' ),
			'back'            => __( 'Back', 'wsg' ),
			'continue'        => __( 'Continue', 'wsg' ),
			'finish'          => __( 'Finish', 'wsg' ),
			'noLogoOption'    => __( "I don't have a logo yet", 'wsg' ),
			'addNotes'        => __( 'Add notes (optional)', 'wsg' ),
			'notesPlaceholder' => __( 'Any special instructions for your logo...', 'wsg' ),
			'printDesc'       => __( 'Vibrant colours printed directly onto the garment', 'wsg' ),
			'embroideryDesc'  => __( 'Premium stitched design for a professional look', 'wsg' ),
			'printAvailable'  => __( 'Print', 'wsg' ),
			'embAvailable'    => __( 'Embroidery', 'wsg' ),
			'perItem'         => __( 'per item', 'wsg' ),
			'addLogo'         => __( 'Add your logo', 'wsg' ),
			'editLogo'        => __( 'Edit logo', 'wsg' ),
			'removeAll'       => __( 'Remove logo', 'wsg' ),
		),
	);

	/* --- Logo customization data --- */
	$logo_enabled = get_post_meta( $product->get_id(), '_wsg_logo_enabled', true ) === 'yes';
	$data['logoEnabled'] = $logo_enabled;

	if ( $logo_enabled ) {
		$positions_raw = get_post_meta( $product->get_id(), '_wsg_logo_positions', true );
		$positions     = is_array( $positions_raw ) ? $positions_raw : array();
		$all_labels    = wsg_get_logo_position_labels();

		$logo_positions = array();
		foreach ( $positions as $slug ) {
			if ( isset( $all_labels[ $slug ] ) ) {
				$logo_positions[] = array(
					'slug'  => $slug,
					'label' => $all_labels[ $slug ],
				);
			}
		}

		$data['logoPositions']       = $logo_positions;
		$data['logoPrintPrice']      = floatval( get_post_meta( $product->get_id(), '_wsg_logo_print_price', true ) );
		$data['logoEmbroideryPrice'] = floatval( get_post_meta( $product->get_id(), '_wsg_logo_embroidery_price', true ) );
		$data['logoUploadUrl']       = WC_AJAX::get_endpoint( 'wsg_upload_logo' );
		$data['logoMaxSize']         = 5 * MB_IN_BYTES;
		$data['logoAllowedTypes']    = 'image/jpeg,image/png,image/gif,image/webp';
	}

	$data = apply_filters( 'wsg_localized_data', $data, $product );

	wp_localize_script( 'wsg-frontend-js', 'wsgData', $data );
}

/* ───────────────────────────────────────────
 * 2. Hide Default WC Form
 * ─────────────────────────────────────────── */

/**
 * Inject CSS and JS into <head> to hide the default WooCommerce variations form.
 *
 * Uses a class on <html> so styles apply instantly before the page renders,
 * preventing a flash of the default dropdowns.
 *
 * @return void
 */
function wsg_hide_default_form() {
	if ( ! is_product() ) {
		return;
	}

	$product = wc_get_product( get_the_ID() );

	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return;
	}

	$enabled = get_post_meta( $product->get_id(), '_wsg_enabled', true ) === 'yes';
	$enabled = apply_filters( 'wsg_is_enabled', $enabled, $product );

	if ( ! $enabled ) {
		return;
	}
	?>
	<script>document.documentElement.classList.add('wsg-active');</script>
	<style>
	.wsg-active .variations_form .variations { display: none !important; }
	.wsg-active .variations_form .single_add_to_cart_button { display: none !important; }
	.wsg-active .variations_form .woocommerce-variation-add-to-cart { display: none !important; }
	</style>
	<?php
}

/* ───────────────────────────────────────────
 * 3. Build Variation Map
 * ─────────────────────────────────────────── */

/**
 * Build the colour-to-sizes variation map for a variable product.
 *
 * Returns an associative array keyed by colour slug (or 'default' when no
 * colour attribute exists). Each entry contains the colour label, hex code,
 * and an array of size objects with variation details.
 *
 * @param WC_Product_Variable $product Variable product instance.
 * @return array Variation map keyed by colour slug.
 */
function wsg_build_variation_map( $product ) {
	$attrs      = wsg_detect_attributes( $product );
	$color_attr = $attrs['color'];
	$size_attr  = $attrs['size'];

	if ( ! $color_attr && ! $size_attr ) {
		return array();
	}

	$map        = array();
	$variations = $product->get_available_variations();

	foreach ( $variations as $v ) {
		$variation = wc_get_product( $v['variation_id'] );

		if ( ! $variation ) {
			continue;
		}

		// Get colour info.
		$color_slug  = 'default';
		$color_label = '';
		$color_hex   = '#cccccc';

		if ( $color_attr ) {
			$color_slug = $v['attributes'][ 'attribute_' . sanitize_title( $color_attr ) ] ?? '';

			if ( $color_slug ) {
				$term        = get_term_by( 'slug', $color_slug, $color_attr );
				$color_label = $term ? $term->name : $color_slug;
				$color_hex   = wsg_get_color_hex( $color_label, $color_slug, $term ? $term->term_id : 0 );
			}
		}

		// Get size info.
		$size_slug  = '';
		$size_label = '';

		if ( $size_attr ) {
			$size_slug = $v['attributes'][ 'attribute_' . sanitize_title( $size_attr ) ] ?? '';

			if ( $size_slug ) {
				$term       = get_term_by( 'slug', $size_slug, $size_attr );
				$size_label = $term ? $term->name : $size_slug;
			}
		}

		// If no colour, use 'default'.
		if ( ! isset( $map[ $color_slug ] ) ) {
			$map[ $color_slug ] = array(
				'label' => $color_label ? $color_label : __( 'Default', 'wsg' ),
				'hex'   => $color_hex,
				'sizes' => array(),
			);
		}

		$map[ $color_slug ]['sizes'][] = array(
			'slug'         => $size_slug ? $size_slug : 'default',
			'label'        => $size_label ? $size_label : __( 'Default', 'wsg' ),
			'variation_id' => $v['variation_id'],
			'price'        => floatval( $variation->get_price() ),
			'in_stock'     => $variation->is_in_stock(),
			'max_qty'      => $variation->get_stock_quantity() !== null
				? $variation->get_stock_quantity()
				: '',
		);
	}

	/**
	 * Filter the variation map before it is passed to JavaScript.
	 *
	 * @param array              $map     Variation map keyed by colour slug.
	 * @param WC_Product_Variable $product Variable product instance.
	 */
	return apply_filters( 'wsg_variation_map', $map, $product );
}

/* ───────────────────────────────────────────
 * 4. Render Container
 * ─────────────────────────────────────────── */

/**
 * Output the root container element for the size grid UI.
 *
 * Hooked to `woocommerce_before_add_to_cart_form` at priority 15.
 * JavaScript will mount the swatch/grid interface inside this div.
 *
 * @return void
 */
function wsg_render_container() {
	if ( ! is_product() ) {
		return;
	}

	$product = wc_get_product( get_the_ID() );

	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return;
	}

	$enabled = get_post_meta( $product->get_id(), '_wsg_enabled', true ) === 'yes';
	$enabled = apply_filters( 'wsg_is_enabled', $enabled, $product );

	if ( ! $enabled ) {
		return;
	}

	$mode = get_post_meta( get_the_ID(), '_wsg_mode', true ) ?: 'product';

	echo '<div id="wsg-root" data-mode="' . esc_attr( $mode ) . '"></div>';
}

/* ───────────────────────────────────────────
 * 5. Bundle Price Display
 * ─────────────────────────────────────────── */

/**
 * Override the price HTML for bundle-mode products on their single product page.
 *
 * Replaces the default variation price range with the fixed bundle price.
 *
 * @param string     $price_html Default price HTML.
 * @param WC_Product $product    Product instance.
 * @return string Modified or original price HTML.
 */
function wsg_bundle_price_html( $price_html, $product ) {
	if ( ! is_product() ) {
		return $price_html;
	}

	if ( (int) get_queried_object_id() !== $product->get_id() ) {
		return $price_html;
	}

	$mode = get_post_meta( $product->get_id(), '_wsg_mode', true );

	if ( 'bundle' !== $mode ) {
		return $price_html;
	}

	$enabled = get_post_meta( $product->get_id(), '_wsg_enabled', true );

	if ( 'yes' !== $enabled ) {
		return $price_html;
	}

	$bundle_price = get_post_meta( $product->get_id(), '_wsg_bundle_price', true );

	if ( ! $bundle_price ) {
		return $price_html;
	}

	return wc_price( floatval( $bundle_price ) );
}
