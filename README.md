# Workwear Size Grid

WooCommerce plugin that replaces the default variable product dropdowns with colour swatches and a size/quantity grid. Designed for workwear stores where customers order multiple sizes at once.

## Features

- **Colour swatches** — visual colour buttons replace the standard dropdown
- **Size/qty grid** — table layout with size, price, and quantity input per row
- **Two modes per product:**
  - **Product mode** — each size added as a separate cart item, with tiered bulk discounts
  - **Bundle mode** — customer picks exactly N items across any colour/size combination for a fixed price, displayed as a single cart entry
- **Live totals** — real-time price and item count as the customer fills in quantities
- **Progress bar** (bundle mode) — visual indicator of items selected vs. required
- **Bulk discount tiers** (product mode) — configurable min/max qty ranges with per-item discounts
- **AJAX add-to-cart** — no page reload
- **HPOS compatible** — works with WooCommerce High-Performance Order Storage
- **Stock-aware** — out-of-stock sizes shown as disabled, stock managed by WooCommerce

## Requirements

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.1+
- Variable products with `pa_color` (or `pa_colour`) and `pa_size` attributes

## Installation

1. Download or clone this repository into `wp-content/plugins/wc-size-grid/`
2. Activate the plugin in **Plugins > Installed Plugins**
3. Go to any Variable product and open the **Size Grid** tab in the Product Data panel
4. Check **Enable Size Grid**, choose a mode, and configure settings

## Setup

### Product Mode

1. Enable the size grid and set mode to **Product**
2. Add discount tiers (optional):
   - **Min Qty** — minimum items to qualify
   - **Max Qty** — maximum items (0 = unlimited)
   - **Discount** — amount subtracted per item (e.g. £1.00 off each)
3. Save the product

### Bundle Mode

1. Enable the size grid and set mode to **Bundle**
2. Set the **Bundle Quantity** — exact number of items the customer must select
3. Set the **Bundle Price** — fixed price for the entire bundle
4. Set a **Display Name** (optional) — shown in cart instead of the product title
5. Save the product

## How It Works

### Product Mode

The customer selects a colour, then enters quantities for each size. Items are added to the cart individually. If discount tiers are configured and the total quantity qualifies, a per-item discount is applied automatically.

### Bundle Mode

The customer selects one or more colours and fills in quantities across all size grids until the total matches the required bundle quantity. All items are added to the cart as real WooCommerce variations (for stock management) but displayed as a single line item at the fixed bundle price.

In the cart:
- Only one line item is visible (the bundle parent)
- Sub-items are hidden but tracked for stock
- A "Sizes ordered" breakdown shows all colour/size selections
- Removing the bundle removes all associated items
- Quantity displays as non-editable "1"

## Important Notes

### Cart/Checkout Blocks

This plugin requires the **classic** WooCommerce cart and checkout shortcodes (`[woocommerce_cart]` and `[woocommerce_checkout]`). The WooCommerce Cart and Checkout **Blocks** do not support the PHP filters needed for bundle display (visibility, name override, quantity override).

If your site uses WC Blocks, you'll see an admin notice guiding you to switch to the classic shortcodes.

### Theme Compatibility

The plugin's product page UI works with both classic and block themes — WooCommerce renders the add-to-cart form via classic PHP templates regardless of theme type.

## File Structure

```
wc-size-grid/
├── workwear-size-grid.php          # Bootstrap, constants, WC compat declarations
├── includes/
│   ├── helpers.php                 # Colour hex lookup, attribute detection, tier lookup
│   ├── admin-product-settings.php  # Product Data tab UI + save
│   ├── frontend-display.php        # Asset loading, variation map, render container
│   ├── cart-product-mode.php       # AJAX handler, bulk discount pricing
│   ├── cart-bundle-mode.php        # Bundle AJAX, pricing, visibility, cascade removal
│   └── cart-shared.php             # Order meta save for both modes
├── assets/
│   ├── frontend.js                 # Swatches, grid, totals, AJAX add-to-cart
│   └── frontend.css                # All frontend styles
├── docs/
│   └── PRD.md                      # Product requirements document
└── tests/
    └── setup-test-data.php         # Creates test products for development
```

## Development

### Local Setup

1. Use [LocalWP](https://localwp.com/) or any WordPress local environment
2. Enable debug mode in `wp-config.php`:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'SCRIPT_DEBUG', true );
   ```
3. Create variable products with `pa_color` and `pa_size` attributes
4. Or run `tests/setup-test-data.php` via WP-CLI to generate test products

### Coding Standards

- WordPress Coding Standards (WPCS)
- Function prefix: `wsg_`
- Meta key prefix: `_wsg_`
- Text domain: `wsg`
- CSS class prefix: `wsg-`
- All output escaped, all input sanitized, nonces on every endpoint

## License

GPL-2.0-or-later
