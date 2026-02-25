<?php
/**
 * Plugin Name: Workwear Size Grid
 * Description: Replaces WooCommerce variable product dropdowns with colour swatches and size/quantity grid. Supports product mode (bulk discounts) and bundle mode (fixed price bundles).
 * Version:     1.1.0
 * Author:      Workwear Express
 * Text Domain: wsg
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.5
 * License:     GPL-2.0-or-later
 *
 * @package WorkwearSizeGrid
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────────────────────
 * Constants
 * ─────────────────────────────────────────── */

define( 'WSG_VERSION', '1.1.0' );
define( 'WSG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WSG_PLUGIN_FILE', __FILE__ );

/* ───────────────────────────────────────────
 * WooCommerce feature compatibility
 *
 * Declared early (before_woocommerce_init) so
 * WC sees it before its own plugins_loaded.
 * Closure is acceptable here — matches WC's
 * own documentation pattern.
 * ─────────────────────────────────────────── */

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			__FILE__,
			false
		);
	}
} );

/* ───────────────────────────────────────────
 * Bootstrap: verify WooCommerce, then load
 * ─────────────────────────────────────────── */

add_action( 'plugins_loaded', 'wsg_bootstrap' );

/**
 * Check for WooCommerce and load plugin includes.
 *
 * Runs on `plugins_loaded` so WooCommerce has already
 * registered itself by this point.
 *
 * @return void
 */
function wsg_bootstrap() {

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wsg_missing_wc_notice' );
		return;
	}

	/* --- Include files (order matters) --- */
	require_once WSG_PLUGIN_DIR . 'includes/helpers.php';
	require_once WSG_PLUGIN_DIR . 'includes/admin-product-settings.php';
	require_once WSG_PLUGIN_DIR . 'includes/frontend-display.php';
	require_once WSG_PLUGIN_DIR . 'includes/cart-product-mode.php';
	require_once WSG_PLUGIN_DIR . 'includes/cart-bundle-mode.php';
	require_once WSG_PLUGIN_DIR . 'includes/cart-shared.php';
	require_once WSG_PLUGIN_DIR . 'includes/logo-upload.php';
}

/**
 * Admin notice shown when WooCommerce is not active.
 *
 * @return void
 */
function wsg_missing_wc_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'Workwear Size Grid requires WooCommerce to be installed and active.',
				'wsg'
			);
			?>
		</p>
	</div>
	<?php
}

/* ───────────────────────────────────────────
 * Admin notice: Cart/Checkout Blocks detected
 * ─────────────────────────────────────────── */

add_action( 'admin_notices', 'wsg_cart_blocks_notice' );

/**
 * Show an admin notice when the Cart or Checkout pages use WC Blocks
 * instead of classic shortcodes.
 *
 * The plugin's cart display hooks (visibility, name, qty overrides)
 * only work with the classic [woocommerce_cart] / [woocommerce_checkout]
 * shortcodes, not with the block-based editor versions.
 *
 * @return void
 */
function wsg_cart_blocks_notice() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	// Only show on WooCommerce admin pages.
	$screen = get_current_screen();
	if ( ! $screen || strpos( $screen->id, 'woocommerce' ) === false ) {
		return;
	}

	// Allow dismissal via user meta.
	if ( get_user_meta( get_current_user_id(), '_wsg_blocks_notice_dismissed', true ) ) {
		return;
	}

	$pages_with_blocks = array();

	$cart_page_id     = wc_get_page_id( 'cart' );
	$checkout_page_id = wc_get_page_id( 'checkout' );

	if ( $cart_page_id > 0 && has_block( 'woocommerce/cart', $cart_page_id ) ) {
		$pages_with_blocks[] = __( 'Cart', 'wsg' );
	}
	if ( $checkout_page_id > 0 && has_block( 'woocommerce/checkout', $checkout_page_id ) ) {
		$pages_with_blocks[] = __( 'Checkout', 'wsg' );
	}

	if ( empty( $pages_with_blocks ) ) {
		return;
	}

	$page_list = implode( ' &amp; ', $pages_with_blocks );
	?>
	<div class="notice notice-warning is-dismissible" data-wsg-dismiss="blocks">
		<p>
			<strong><?php esc_html_e( 'Workwear Size Grid', 'wsg' ); ?>:</strong>
			<?php
			printf(
				/* translators: %s: page names like "Cart & Checkout" */
				esc_html__( 'Your %s page(s) use WooCommerce Blocks, which are not compatible with this plugin\'s bundle display. Please switch them to the classic shortcodes [woocommerce_cart] and [woocommerce_checkout] for bundle mode to work correctly.', 'wsg' ),
				'<strong>' . wp_kses_post( $page_list ) . '</strong>'
			);
			?>
		</p>
	</div>
	<?php
}
