<?php
/**
 * Admin Product Settings — Size Grid tab in WooCommerce Product Data.
 *
 * Adds a "Size Grid" tab to the product edit screen with fields for
 * enabling the grid, choosing mode (product vs bundle), configuring
 * discount tiers (product mode), and setting bundle parameters.
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────────────────────
 * 1. Tab Registration
 * ─────────────────────────────────────────── */

add_filter( 'woocommerce_product_data_tabs', 'wsg_product_data_tab' );

/**
 * Register the Size Grid tab in the Product Data metabox.
 *
 * @param array $tabs Existing product data tabs.
 * @return array Modified tabs.
 */
function wsg_product_data_tab( $tabs ) {

	$tabs['wsg_size_grid'] = array(
		'label'    => __( 'Size Grid', 'wsg' ),
		'target'   => 'wsg_size_grid_panel',
		'class'    => array( 'show_if_variable' ),
		'priority' => 70,
	);

	return $tabs;
}

/* ───────────────────────────────────────────
 * 2. Tab Icon
 * ─────────────────────────────────────────── */

add_action( 'admin_head', 'wsg_admin_tab_icon' );

/**
 * Output inline CSS to set the tab icon (dashicons grid icon).
 *
 * Only loads on the product edit screen.
 *
 * @return void
 */
function wsg_admin_tab_icon() {

	$screen = get_current_screen();

	if ( ! $screen || 'product' !== $screen->id ) {
		return;
	}

	?>
	<style>
		#woocommerce-product-data ul.wc-tabs li.wsg_size_grid_options a::before {
			content: "\f163";
		}
		#wsg_size_grid_panel .wsg-position-chips {
			display: flex;
			flex-wrap: wrap;
			gap: 6px;
		}
		#wsg_size_grid_panel .wsg-position-chip {
			display: inline-block;
			margin: 0;
			float: none;
			width: auto;
			padding: 0;
			cursor: pointer;
		}
		#wsg_size_grid_panel .wsg-position-chip input[type="checkbox"] {
			position: absolute;
			opacity: 0;
			width: 0;
			height: 0;
		}
		#wsg_size_grid_panel .wsg-position-chip__label {
			display: inline-block;
			padding: 5px 14px;
			border: 1px solid #c3c4c7;
			border-radius: 16px;
			font-size: 13px;
			line-height: 1.4;
			background: #f0f0f1;
			color: #50575e;
			transition: all 0.15s ease;
			user-select: none;
		}
		#wsg_size_grid_panel .wsg-position-chip input:checked + .wsg-position-chip__label {
			background: #2271b1;
			border-color: #2271b1;
			color: #fff;
		}
		#wsg_size_grid_panel .wsg-position-chip:hover .wsg-position-chip__label {
			border-color: #2271b1;
		}
	</style>
	<?php
}

/* ───────────────────────────────────────────
 * 3. Tab Panel Content
 * ─────────────────────────────────────────── */

add_action( 'woocommerce_product_data_panels', 'wsg_product_data_panel' );

/**
 * Render the Size Grid panel inside the Product Data metabox.
 *
 * @return void
 */
function wsg_product_data_panel() {

	global $post;

	$post_id = $post->ID;

	/* --- Existing meta values --- */
	$enabled      = get_post_meta( $post_id, '_wsg_enabled', true );
	$mode         = get_post_meta( $post_id, '_wsg_mode', true );
	$tiers        = get_post_meta( $post_id, '_wsg_discount_tiers', true );
	$bundle_qty   = get_post_meta( $post_id, '_wsg_bundle_qty', true );
	$bundle_price = get_post_meta( $post_id, '_wsg_bundle_price', true );
	$display_name = get_post_meta( $post_id, '_wsg_bundle_display_name', true );

	/* Logo customization meta. */
	$logo_enabled         = get_post_meta( $post_id, '_wsg_logo_enabled', true );
	$logo_positions       = get_post_meta( $post_id, '_wsg_logo_positions', true );
	$logo_print_price     = get_post_meta( $post_id, '_wsg_logo_print_price', true );
	$logo_embroidery_price = get_post_meta( $post_id, '_wsg_logo_embroidery_price', true );

	if ( ! is_array( $logo_positions ) ) {
		$logo_positions = array();
	}

	if ( is_string( $tiers ) && ! empty( $tiers ) ) {
		$tiers = json_decode( $tiers, true );
	}
	if ( ! is_array( $tiers ) ) {
		$tiers = array();
	}

	if ( ! $mode ) {
		$mode = 'product';
	}

	?>
	<div id="wsg_size_grid_panel" class="panel woocommerce_options_panel">

		<?php wp_nonce_field( 'wsg_save_settings', 'wsg_settings_nonce' ); ?>

		<?php
		/* --- a) Enable checkbox --- */
		woocommerce_wp_checkbox(
			array(
				'id'    => '_wsg_enabled',
				'label' => __( 'Enable Size Grid', 'wsg' ),
				'value' => $enabled,
			)
		);

		/* --- b) Mode select --- */
		woocommerce_wp_select(
			array(
				'id'      => '_wsg_mode',
				'label'   => __( 'Mode', 'wsg' ),
				'options' => array(
					'product' => __( 'Product (individual items)', 'wsg' ),
					'bundle'  => __( 'Bundle (fixed price group)', 'wsg' ),
				),
				'value'   => $mode,
			)
		);
		?>

		<!-- c) Product mode fields -->
		<div id="wsg_product_fields" class="wsg-mode-fields">

			<div class="options_group">
				<h4 style="padding-left:12px;">
					<?php esc_html_e( 'Discount Tiers', 'wsg' ); ?>
				</h4>
				<p style="padding-left:12px;">
					<?php
					esc_html_e(
						'Define quantity-based discount tiers. Customers ordering within a tier range receive the specified discount per item. Set Max Qty to 0 for unlimited.',
						'wsg'
					);
					?>
				</p>

				<table id="wsg_tiers_table" class="widefat" style="margin:0 12px 12px; width:calc(100% - 24px);">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Min Qty', 'wsg' ); ?></th>
							<th><?php esc_html_e( 'Max Qty', 'wsg' ); ?></th>
							<th><?php esc_html_e( 'Discount (&pound;)', 'wsg' ); ?></th>
							<th><?php esc_html_e( 'Remove', 'wsg' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( ! empty( $tiers ) ) : ?>
							<?php foreach ( $tiers as $tier ) : ?>
								<tr>
									<td>
										<input
											type="number"
											name="_wsg_tier_min[]"
											min="1"
											value="<?php echo esc_attr( $tier['min'] ); ?>"
										/>
									</td>
									<td>
										<input
											type="number"
											name="_wsg_tier_max[]"
											min="0"
											value="<?php echo esc_attr( $tier['max'] ); ?>"
										/>
									</td>
									<td>
										<input
											type="text"
											name="_wsg_tier_discount[]"
											value="<?php echo esc_attr( $tier['discount'] ); ?>"
										/>
									</td>
									<td>
										<button type="button" class="button wsg-remove-tier">&times;</button>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>

				<p style="padding-left:12px;">
					<button type="button" id="wsg_add_tier" class="button">
						<?php esc_html_e( 'Add Tier', 'wsg' ); ?>
					</button>
				</p>
			</div>

		</div><!-- #wsg_product_fields -->

		<!-- d) Bundle mode fields -->
		<div id="wsg_bundle_fields" class="wsg-mode-fields">

			<div class="options_group">
				<?php
				woocommerce_wp_text_input(
					array(
						'id'                => '_wsg_bundle_qty',
						'label'             => __( 'Required Total Qty', 'wsg' ),
						'type'              => 'number',
						'value'             => $bundle_qty ? $bundle_qty : '',
						'custom_attributes' => array(
							'min' => '1',
						),
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_wsg_bundle_price',
						'label'       => __( 'Bundle Price (&pound;)', 'wsg' ),
						'type'        => 'text',
						'value'       => $bundle_price ? $bundle_price : '',
						'description' => __( 'Total price for the bundle.', 'wsg' ),
						'desc_tip'    => true,
					)
				);

				woocommerce_wp_text_input(
					array(
						'id'          => '_wsg_bundle_display_name',
						'label'       => __( 'Bundle Display Name', 'wsg' ),
						'type'        => 'text',
						'value'       => $display_name ? $display_name : '',
						'description' => __( 'Optional. Overrides cart item title.', 'wsg' ),
						'desc_tip'    => true,
					)
				);
				?>
			</div>

		</div><!-- #wsg_bundle_fields -->

		<!-- e) Logo Customization fields -->
		<div id="wsg_logo_fields">

			<div class="options_group">
				<h4 style="padding-left:12px;">
					<span class="dashicons dashicons-format-image" style="vertical-align:middle;margin-right:4px;color:#646970;"></span>
					<?php esc_html_e( 'Logo Customization', 'wsg' ); ?>
				</h4>

				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => '_wsg_logo_enabled',
						'label'       => __( 'Enable Logo Option', 'wsg' ),
						'value'       => $logo_enabled,
						'description' => __( 'Allow customers to upload a logo on this product.', 'wsg' ),
					)
				);
				?>

				<div id="wsg_logo_options" style="display:none;">

					<p class="form-field">
						<label style="float:left;width:150px;padding:4px 0 0;">
							<?php esc_html_e( 'Available Positions', 'wsg' ); ?>
						</label>
						<span class="wsg-position-chips">
							<?php
							$all_positions = wsg_get_logo_position_labels();
							foreach ( $all_positions as $slug => $label ) :
								?>
								<label class="wsg-position-chip">
									<input
										type="checkbox"
										name="_wsg_logo_positions[]"
										value="<?php echo esc_attr( $slug ); ?>"
										<?php checked( in_array( $slug, $logo_positions, true ) ); ?>
									/>
									<span class="wsg-position-chip__label"><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</span>
					</p>

					<p class="form-field" style="margin-bottom:4px;">
						<label style="float:left;width:150px;padding:4px 0 0;">
							<?php esc_html_e( 'Surcharge Pricing', 'wsg' ); ?>
						</label>
					</p>

					<?php
					woocommerce_wp_text_input(
						array(
							'id'          => '_wsg_logo_print_price',
							'label'       => __( 'Print Surcharge (&pound;)', 'wsg' ),
							'type'        => 'text',
							'value'       => $logo_print_price ? $logo_print_price : '',
							'description' => __( 'Extra cost per item for printed logos.', 'wsg' ),
							'desc_tip'    => true,
						)
					);

					woocommerce_wp_text_input(
						array(
							'id'          => '_wsg_logo_embroidery_price',
							'label'       => __( 'Embroidery Surcharge (&pound;)', 'wsg' ),
							'type'        => 'text',
							'value'       => $logo_embroidery_price ? $logo_embroidery_price : '',
							'description' => __( 'Extra cost per item for embroidered logos.', 'wsg' ),
							'desc_tip'    => true,
						)
					);
					?>

				</div><!-- #wsg_logo_options -->

			</div>

		</div><!-- #wsg_logo_fields -->

	</div><!-- #wsg_size_grid_panel -->
	<?php
}

/* ───────────────────────────────────────────
 * 4. Save Settings
 * ─────────────────────────────────────────── */

add_action( 'woocommerce_process_product_meta', 'wsg_save_product_settings' );

/**
 * Save Size Grid settings when a product is saved.
 *
 * @param int $post_id Product (post) ID.
 * @return void
 */
function wsg_save_product_settings( $post_id ) {

	/* --- Nonce verification --- */
	if (
		! isset( $_POST['wsg_settings_nonce'] )
		|| ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['wsg_settings_nonce'] ) ),
			'wsg_save_settings'
		)
	) {
		return;
	}

	/* --- Enabled --- */
	$enabled = isset( $_POST['_wsg_enabled'] ) ? 'yes' : '';
	update_post_meta( $post_id, '_wsg_enabled', $enabled );

	/* --- Mode --- */
	$mode = isset( $_POST['_wsg_mode'] )
		? sanitize_text_field( wp_unslash( $_POST['_wsg_mode'] ) )
		: 'product';

	if ( ! in_array( $mode, array( 'product', 'bundle' ), true ) ) {
		$mode = 'product';
	}

	update_post_meta( $post_id, '_wsg_mode', $mode );

	/* --- Discount Tiers --- */
	$tier_mins      = isset( $_POST['_wsg_tier_min'] ) ? array_map( 'absint', wp_unslash( $_POST['_wsg_tier_min'] ) ) : array();
	$tier_maxes     = isset( $_POST['_wsg_tier_max'] ) ? array_map( 'absint', wp_unslash( $_POST['_wsg_tier_max'] ) ) : array();
	$tier_discounts = isset( $_POST['_wsg_tier_discount'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['_wsg_tier_discount'] ) ) : array();

	$tiers = array();

	$count = count( $tier_mins );
	for ( $i = 0; $i < $count; $i++ ) {

		$min      = isset( $tier_mins[ $i ] ) ? absint( $tier_mins[ $i ] ) : 0;
		$max      = isset( $tier_maxes[ $i ] ) ? absint( $tier_maxes[ $i ] ) : 0;
		$discount = isset( $tier_discounts[ $i ] ) ? floatval( $tier_discounts[ $i ] ) : 0;

		/* Skip rows where min is 0 (empty / invalid). */
		if ( 0 === $min ) {
			continue;
		}

		$tiers[] = array(
			'min'      => $min,
			'max'      => $max,
			'discount' => $discount,
		);
	}

	/* Sort tiers by min ascending. */
	usort(
		$tiers,
		function ( $a, $b ) {
			return $a['min'] - $b['min'];
		}
	);

	update_post_meta( $post_id, '_wsg_discount_tiers', $tiers );

	/* --- Bundle Qty --- */
	$bundle_qty = isset( $_POST['_wsg_bundle_qty'] )
		? absint( wp_unslash( $_POST['_wsg_bundle_qty'] ) )
		: 0;
	update_post_meta( $post_id, '_wsg_bundle_qty', $bundle_qty );

	/* --- Bundle Price --- */
	$bundle_price = isset( $_POST['_wsg_bundle_price'] )
		? floatval( wp_unslash( $_POST['_wsg_bundle_price'] ) )
		: 0;
	update_post_meta( $post_id, '_wsg_bundle_price', $bundle_price );

	/* --- Bundle Display Name --- */
	$display_name = isset( $_POST['_wsg_bundle_display_name'] )
		? sanitize_text_field( wp_unslash( $_POST['_wsg_bundle_display_name'] ) )
		: '';
	update_post_meta( $post_id, '_wsg_bundle_display_name', $display_name );

	/* --- Logo Customization --- */
	$logo_enabled = isset( $_POST['_wsg_logo_enabled'] ) ? 'yes' : '';
	update_post_meta( $post_id, '_wsg_logo_enabled', $logo_enabled );

	$valid_positions = array_keys( wsg_get_logo_position_labels() );
	$logo_positions  = isset( $_POST['_wsg_logo_positions'] )
		? array_intersect(
			array_map( 'sanitize_text_field', wp_unslash( $_POST['_wsg_logo_positions'] ) ),
			$valid_positions
		)
		: array();
	update_post_meta( $post_id, '_wsg_logo_positions', array_values( $logo_positions ) );

	$logo_print_price = isset( $_POST['_wsg_logo_print_price'] )
		? floatval( wp_unslash( $_POST['_wsg_logo_print_price'] ) )
		: 0;
	update_post_meta( $post_id, '_wsg_logo_print_price', $logo_print_price );

	$logo_embroidery_price = isset( $_POST['_wsg_logo_embroidery_price'] )
		? floatval( wp_unslash( $_POST['_wsg_logo_embroidery_price'] ) )
		: 0;
	update_post_meta( $post_id, '_wsg_logo_embroidery_price', $logo_embroidery_price );
}

/* ───────────────────────────────────────────
 * 5. Admin JS (Inline)
 * ─────────────────────────────────────────── */

add_action( 'admin_footer', 'wsg_admin_inline_js' );

/**
 * Output inline JavaScript for the Size Grid admin panel.
 *
 * Handles mode field toggling, adding/removing discount tier rows.
 * Only loads on the product edit screen.
 *
 * @return void
 */
function wsg_admin_inline_js() {

	$screen = get_current_screen();

	if ( ! $screen || 'product' !== $screen->id ) {
		return;
	}

	?>
	<script>
	jQuery( function( $ ) {

		/* --- Toggle mode-specific field groups --- */
		function wsgToggleMode() {
			var mode = $( '#_wsg_mode' ).val();
			$( '#wsg_product_fields' ).toggle( mode === 'product' );
			$( '#wsg_bundle_fields' ).toggle( mode === 'bundle' );
		}
		$( '#_wsg_mode' ).on( 'change', wsgToggleMode );
		wsgToggleMode();

		/* --- Add tier row --- */
		$( '#wsg_add_tier' ).on( 'click', function() {
			var $row = $( '<tr>' );
			$row.append( $( '<td>' ).append( $( '<input>' ).attr({ type: 'number', name: '_wsg_tier_min[]', min: 1 }) ) );
			$row.append( $( '<td>' ).append( $( '<input>' ).attr({ type: 'number', name: '_wsg_tier_max[]', min: 0 }) ) );
			$row.append( $( '<td>' ).append( $( '<input>' ).attr({ type: 'text', name: '_wsg_tier_discount[]' }) ) );
			$row.append( $( '<td>' ).append( $( '<button type="button">' ).addClass( 'button wsg-remove-tier' ).html( '&times;' ) ) );
			$( '#wsg_tiers_table tbody' ).append( $row );
		} );

		/* --- Remove tier row --- */
		$( document ).on( 'click', '.wsg-remove-tier', function() {
			$( this ).closest( 'tr' ).remove();
		} );

		/* --- Toggle logo options --- */
		function wsgToggleLogo() {
			$( '#wsg_logo_options' ).toggle( $( '#_wsg_logo_enabled' ).is( ':checked' ) );
		}
		$( '#_wsg_logo_enabled' ).on( 'change', wsgToggleLogo );
		wsgToggleLogo();

	} );
	</script>
	<?php
}
