# Workwear Size Grid — Claude Code Project Context

## What This Is

WooCommerce plugin that replaces the default variable product dropdowns with colour swatches + size/qty grid. Two modes: Product (separate cart items, bulk discounts) and Bundle (grouped as one cart entry, fixed price).

Full requirements in `PRD.md` in this directory.

## Architecture — Lean on WooCommerce

This plugin builds only what WC doesn't provide. We do NOT:
- Create custom product types (use standard Variable products)
- Manage stock (WC handles it — each colour+size is a real variation)
- Calculate tax (use `$product->set_price()` and let WC do the rest)
- Store custom data in DB tables (use WC's `$cart_item_data`)
- Format prices (use `wc_price()` server-side; JS uses WC's full format settings via `wsgData`)
- Handle sessions (use WC's cart)
- Use `admin-ajax.php` (use WC's `wc_ajax_` endpoints for frontend AJAX)

We DO:
- Replace the frontend UI (swatches + grid)
- Add admin settings per product (mode, tiers, bundle config)
- Handle multi-item AJAX add-to-cart
- Adjust prices in cart (discounts and bundle pricing)
- Group bundle items visually (hide sub-items, cascade removal)

## File Structure

```
workwear-size-grid/
├── workwear-size-grid.php          # Bootstrap: constants, require files, WC feature compat declarations
├── includes/
│   ├── admin-product-settings.php  # Product Data tab UI + save
│   ├── frontend-display.php        # Enqueue, variation map, render container, price HTML override
│   ├── cart-product-mode.php       # Product: AJAX handler, bulk discount pricing, discount display
│   ├── cart-bundle-mode.php        # Bundle: AJAX handler, grouped pricing, visibility, name override, cascade removal
│   ├── cart-shared.php             # Order meta save, shared between both modes
│   └── helpers.php                 # Colour hex lookup, attribute detection, tier lookup
└── assets/
    ├── frontend.css                # All frontend styles
    └── frontend.js                 # Swatches, grid, totals, validation, AJAX add-to-cart
```

Each file has ONE responsibility. No circular dependencies between files.

## Coding Standards

- WordPress Coding Standards (WPCS)
- Function prefix: `wsg_`
- Meta key prefix: `_wsg_`
- Text domain: `wsg`
- CSS class prefix: `wsg-`
- JS data object: `wsgData` (via `wp_localize_script`)
- All output escaped (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`)
- All input sanitized (`sanitize_text_field()`, `absint()`, `floatval()`)
- Nonces on every AJAX endpoint and admin save
- Named functions for all hooks (no closures) — allows unhooking
- CSS uses `#wsg-root` ID specificity (not blanket `!important`); `!important` only on form-hiding rules
- `woocommerce_before_calculate_totals` callbacks guarded with `did_action() >= 2` check
- Cascade removal hooks guarded with `remove_action`/`add_action` to prevent recursion
- Price modifications use `get_price()` (respects sale/dynamic pricing), not `get_regular_price()`
- `woocommerce_get_price_html` filter guarded with `is_product()` context check
- Declare HPOS compat + Blocks incompatibility via `FeaturesUtil` on `before_woocommerce_init`

## How Bundle Mode Works (the tricky part)

Bundles add REAL variations to cart (for stock deduction) but GROUP them visually:

1. Customer picks colours and sizes totaling exactly N items
2. Server adds each colour+size as a separate `WC()->cart->add_to_cart()` call
3. All share a `_wsg_bundle_id` and carry a `_wsg_bundle_index` (0, 1, 2…)
4. Price hook: index 0 gets the full bundle price, all others get £0
5. Visibility hook: only index 0 is visible in cart/mini-cart
6. Display hook: index 0 shows "Sizes ordered" breakdown collected from all items in the group
7. Removal hook: removing any item cascades to remove all items with the same bundle_id (with unhook/rehook recursion guard)
8. Qty hook: shows "1" as plain text (not editable)

## Key WooCommerce Hooks

### Admin
- `woocommerce_product_data_tabs` → add tab
- `woocommerce_product_data_panels` → tab content
- `woocommerce_process_product_meta` → save

### Bootstrap
- `before_woocommerce_init` → declare HPOS + Blocks compatibility

### Frontend
- `wp_enqueue_scripts` → load assets
- `woocommerce_before_add_to_cart_form` → render #wsg-root
- `woocommerce_get_price_html` → bundle price display (guarded: single product only)
- `wp_head` → inject wsg-active class on `<html>` + CSS to hide default form elements

### Cart (Product Mode)
- `wc_ajax[_nopriv]_wsg_add_to_cart` → WC AJAX handler
- `woocommerce_before_calculate_totals` → apply bulk discount (idempotency guard)
- `woocommerce_get_item_data` → show discount note

### Cart (Bundle Mode)
- `wc_ajax[_nopriv]_wsg_add_to_cart` → WC AJAX handler (routes by mode)
- `woocommerce_before_calculate_totals` → bundle pricing, first=price rest=0 (idempotency guard)
- `woocommerce_cart_item_visible` → hide sub-items
- `woocommerce_widget_cart_item_visible` → hide from mini-cart
- `woocommerce_cart_item_name` → override to bundle display name
- `woocommerce_cart_item_quantity` → show "1" non-editable
- `woocommerce_get_item_data` → show sizes breakdown
- `woocommerce_remove_cart_item` → cascade removal (unhook/rehook guard)
- `woocommerce_add_to_cart_validation` → allow parent product add

### Order
- `woocommerce_checkout_create_order_line_item` → save meta

## Post Meta (per product)

| Key | Values | Mode |
|---|---|---|
| `_wsg_enabled` | `'yes'` / `''` | Both |
| `_wsg_mode` | `'product'` / `'bundle'` | Both |
| `_wsg_discount_tiers` | `[{min, max, discount}]` | Product |
| `_wsg_bundle_qty` | int | Bundle |
| `_wsg_bundle_price` | float | Bundle |
| `_wsg_bundle_display_name` | string (optional) | Bundle |

## Cart Item Meta

| Key | Mode | Description |
|---|---|---|
| `_wsg_group_id` | Product | Unique per add-to-cart action |
| `_wsg_discount` | Product | Per-item discount from tier |
| `_wsg_is_bundle` | Bundle | Boolean flag |
| `_wsg_bundle_id` | Bundle | Groups items in one bundle |
| `_wsg_bundle_price` | Bundle | Total bundle price |
| `_wsg_bundle_qty` | Bundle | Required total items |
| `_wsg_bundle_index` | Bundle | 0 = visible parent, 1+ = hidden |
| `_wsg_color_label` | Bundle | e.g. "Hot Pink" |
| `_wsg_size_label` | Bundle | e.g. "XS" |
| `_wsg_color_hex` | Bundle | e.g. "#ff1493" |

## Local Development

- Site: LocalWP
- Debug log: `../../debug.log` (relative to plugin, or `wp-content/debug.log`)
- wp-config.php should have: `WP_DEBUG`, `WP_DEBUG_LOG`, `SCRIPT_DEBUG` all true
- Test with Astra theme for production parity
- Need variable products with `pa_color`/`pa_colour` and `pa_size` attributes
