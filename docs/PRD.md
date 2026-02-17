# Workwear Size Grid — Product Requirements Document

**Version:** 2.1
**Status:** Final — ready for development
**Reference site:** workwearexpress.com
**Changelog:** 2.1 — WC standards audit: WC AJAX endpoints, proper price formatting, HPOS/Blocks declarations, idempotency guards, recursion-safe cascade removal, targeted form hiding, CSS specificity strategy, curated extensibility hooks, `get_price()` for sale/dynamic pricing compat, context-guarded price HTML filter, safe attribute detection

---

## 1. Problem

WooCommerce's default variable product UI (dropdown menus for colour and size) is designed for single-item purchases. In the workwear industry, customers buy the same garment in one colour across many sizes (e.g. 3×S, 5×M, 4×L for a team). They also buy bundles (e.g. "16 polo shirts for £99.99" split across multiple colours and sizes).

The default WooCommerce UI does not support either workflow.

---

## 2. Solution

A plugin that replaces the default variable product form with:

1. **Colour swatches** — visual circles instead of dropdowns
2. **Size/quantity grid** — table where each row is a size with a qty input

Two modes share this UI but differ in cart behavior:

| | Product Mode | Bundle Mode |
|---|---|---|
| Colour selection | Single | Multiple |
| Quantity rule | Open (any amount) | Must total exactly N |
| Pricing | Per-unit with optional bulk discount tiers | Fixed total price |
| Cart items | One WC cart item per colour+size | Multiple WC cart items (one per colour+size) grouped visually as one entry |
| Stock deduction | Standard WC per variation | Standard WC per variation (each is a real cart item) |

---

## 3. Architecture Principles

### 3.1 Lean on WooCommerce

The plugin builds only what WooCommerce does not provide. Specifically:

- **Product type:** Standard WC Variable product. No custom product types.
- **Stock:** Each colour+size is a real WC variation. Stock is managed entirely by WooCommerce — the plugin never touches `wc_update_product_stock()` or similar.
- **Tax:** All prices flow through WC's tax system. The plugin uses `$product->set_price()` and lets WC handle the rest.
- **Cart storage:** Uses WC's `$cart_item_data` parameter (4th arg to `add_to_cart()`) for all custom metadata. No custom DB tables.
- **Sessions:** Uses WC's session system (via cart). No custom sessions.
- **Currency/formatting:** Uses `wc_price()` for all server-side price display. JS receives the full WC price format settings (symbol, position, separators, decimals) via `wp_localize_script` and formats prices to match `wc_price()` output exactly.
- **AJAX:** Uses WooCommerce's `wc_ajax_` endpoints (not `admin-ajax.php`) for frontend AJAX operations. This avoids loading the full WP admin bootstrap and is the standard WC pattern for frontend requests.
- **Fragments:** Triggers `wc_fragment_refresh` after AJAX add-to-cart so WC's mini-cart updates natively.
- **Feature compatibility:** Declares HPOS compatibility (`custom_order_tables`) and Cart/Checkout Blocks incompatibility via `FeaturesUtil::declare_compatibility` on `before_woocommerce_init`.

### 3.2 Plugin Structure

```
workwear-size-grid/
├── workwear-size-grid.php          # Bootstrap: constants, version check, require files
├── CLAUDE.md                       # Claude Code project context
├── PRD.md                          # This document
│
├── includes/
│   ├── admin-product-settings.php  # Product Data tab: enable, mode, tiers, bundle config
│   ├── frontend-display.php        # Enqueue CSS/JS, build variation map, render #wsg-root
│   ├── cart-product-mode.php       # Product mode: AJAX handler, bulk discount pricing
│   ├── cart-bundle-mode.php        # Bundle mode: AJAX handler, grouped pricing, visibility, cascade removal
│   ├── cart-shared.php             # Shared cart hooks: order meta, email display
│   └── helpers.php                 # Colour hex resolution, attribute detection, tier lookup
│
└── assets/
    ├── frontend.css                # All frontend styles (no admin CSS needed beyond inline)
    └── frontend.js                 # Swatch picker, size grid, totals, validation, AJAX
```

**Naming conventions:**

- Plugin slug: `workwear-size-grid` (folder name — rename freely, does not affect functionality)
- Function prefix: `wsg_`
- Meta key prefix: `_wsg_`
- Text domain: `wsg`
- CSS class prefix: `wsg-`
- JS global: `wsgData` (via `wp_localize_script`)

### 3.3 WordPress & WooCommerce Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/)
- All output escaped: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`
- All input sanitized: `sanitize_text_field()`, `absint()`, `floatval()`
- Nonces on all AJAX endpoints and form saves
- All user-facing strings wrapped in `__()` or `esc_html__()` with text domain `wsg`
- Hooks use named functions (not closures) for unhook-ability
- Each file can be understood independently — no circular dependencies
- `woocommerce_before_calculate_totals` callbacks must guard against multiple fires (see 7.2, 8.2)
- Cascade removal hooks must guard against infinite recursion (see 8.7)
- Price modifications use `get_price()` (the active price), not `get_regular_price()`, to respect sale prices and dynamic pricing plugins

### 3.4 WooCommerce Feature Compatibility

The bootstrap file (`workwear-size-grid.php`) must declare feature compatibility on `before_woocommerce_init`:

```php
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        // HPOS compatible — we use WC CRUD API ($item->add_meta_data), not update_post_meta
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables', __FILE__, true
        );
        // NOT compatible with Cart/Checkout Blocks (v1 limitation)
        // Bundle visibility, qty override, and cascade removal hooks do not fire in Blocks
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks', __FILE__, false
        );
    }
});
```

**Why Blocks incompatibility?** The following hooks used by bundle mode do not fire in WooCommerce Cart/Checkout Blocks:
- `woocommerce_cart_item_visible` / `woocommerce_widget_cart_item_visible`
- `woocommerce_cart_item_quantity`
- `woocommerce_cart_item_name`
- `woocommerce_get_item_data` (partial support only)

Declaring `false` causes WooCommerce to show an admin notice prompting the store to use the classic shortcode cart/checkout.

### 3.5 Extensibility

The plugin provides curated filters and actions at key decision points so other plugins can modify behavior without patching:

**Data filters:**

| Filter | Args | Description |
|---|---|---|
| `wsg_color_map` | `$map` | Custom colour name → hex mappings |
| `wsg_variation_map` | `$map, $product` | Full variation map before passing to JS |
| `wsg_localized_data` | `$data, $product` | The `wsgData` object before localization |
| `wsg_discount_amount` | `$discount, $product_id, $total_qty` | Calculated discount per item |
| `wsg_cart_item_data` | `$cart_item_data, $item, $product_id` | Cart item meta before `add_to_cart()` |
| `wsg_bundle_display_name` | `$name, $product, $bundle_data` | Bundle name shown in cart |
| `wsg_is_enabled` | `$enabled, $product` | Whether size grid is active for a product |

**Action hooks:**

| Action | Args | Description |
|---|---|---|
| `wsg_before_add_to_cart` | `$product_id, $items, $mode` | Fires before processing AJAX add-to-cart |
| `wsg_after_add_to_cart` | `$product_id, $cart_item_keys, $mode` | Fires after all items added to cart |

This is intentionally a small surface area. Filters exist at data-flow boundaries; internals are not exposed.

---

## 4. Admin Settings

### 4.1 Product Data Tab

**Hook:** `woocommerce_product_data_tabs` + `woocommerce_product_data_panels`  
**Visibility:** Variable products only (`show_if_variable`)

**Fields:**

| Field | Type | Default | Notes |
|---|---|---|---|
| Enable Size Grid | Checkbox | Off | Meta: `_wsg_enabled` = `yes` or empty |
| Mode | Select | Product | Meta: `_wsg_mode` = `product` or `bundle` |

**Product mode fields** (visible when mode = product):

| Field | Type | Meta Key |
|---|---|---|
| Discount tiers | Repeater (min_qty, max_qty, discount) | `_wsg_discount_tiers` (serialized array) |

Each tier row: Min Qty (int, required >0), Max Qty (int, optional, empty = no upper limit), Discount per item (float). Sorted by min_qty on save. First matching tier wins at runtime.

**Bundle mode fields** (visible when mode = bundle):

| Field | Type | Meta Key |
|---|---|---|
| Required Total Qty | Number | `_wsg_bundle_qty` (int) |
| Bundle Price | Text | `_wsg_bundle_price` (float) |
| Bundle Display Name | Text (optional) | `_wsg_bundle_display_name` (string) |

Bundle Display Name: if set, overrides the cart item title (e.g. "16 × Best Workwear Polo Shirts + Free Logo"). If empty, defaults to `"{qty} × {product_title}"`.

**Save hook:** `woocommerce_process_product_meta`  
**Validation:** Bundle mode requires qty > 0 and price > 0.

### 4.2 Admin JS

Minimal inline JS in the panel (via `admin_footer`): toggles visibility of mode-specific field groups, handles add/remove tier rows. No separate admin JS file needed.

---

## 5. Frontend Display

### 5.1 Asset Loading

**Hook:** `wp_enqueue_scripts`  
**Conditions:** `is_product()` AND `_wsg_enabled === 'yes'` on the current product.

Enqueue:
- `assets/frontend.css` — all plugin styles
- `assets/frontend.js` — depends on `jquery`
- `wp_localize_script` → `wsgData` object containing:

```php
array(
    'ajaxUrl'            => WC_AJAX::get_endpoint( 'wsg_add_to_cart' ),
    'nonce'              => wp_create_nonce( 'wsg_nonce' ),
    'productId'          => $product_id,
    'mode'               => 'product' | 'bundle',
    'bundleQty'          => 16,           // bundle mode only
    'bundlePrice'        => 99.99,        // bundle mode only
    'tiers'              => [...],        // product mode only
    'currencySymbol'     => get_woocommerce_currency_symbol(),
    'priceFormat'        => get_woocommerce_price_format(),        // e.g. '%1$s%2$s' or '%2$s%1$s'
    'decimalSeparator'   => wc_get_price_decimal_separator(),      // e.g. '.' or ','
    'thousandSeparator'  => wc_get_price_thousand_separator(),     // e.g. ',' or '.'
    'decimals'           => wc_get_price_decimals(),               // e.g. 2
    'colorSizeMap'       => [...],        // see 5.3 (filtered via wsg_variation_map)
    'i18n'               => [...],        // all UI strings
)
```

**Note:** `ajaxUrl` uses `WC_AJAX::get_endpoint()` instead of `admin_url('admin-ajax.php')`. This avoids loading the full WP admin bootstrap on each AJAX request (~40% faster). The full price formatting settings allow JS to replicate `wc_price()` output exactly, respecting currency position, decimal/thousand separators, and decimal count (see 6.2).

The full `wsgData` object is filterable via `apply_filters( 'wsg_localized_data', $data, $product )` before localization.

### 5.2 Hide Default WC Form

**Method:** Inject `<script>document.documentElement.classList.add('wsg-active');</script>` via `wp_head` (not `wp_footer`).

Using `document.documentElement` (the `<html>` element) in `wp_head` ensures the class exists **before** the `<body>` renders, eliminating the flash of unstyled content (FOUC) that would occur if the class were added in `wp_footer`.

**CSS rules — targeted hiding:**
```css
/* Hide only the variation dropdowns and the default add-to-cart button */
.wsg-active .variations_form .variations { display: none !important; }
.wsg-active .variations_form .single_add_to_cart_button { display: none !important; }
.wsg-active .variations_form .woocommerce-variation-add-to-cart { display: none !important; }
```

**Important:** We hide specific elements inside `form.cart`, NOT `form.cart` itself. Other plugins (wishlists, compare lists, custom fields) add buttons and content inside `form.cart`. Hiding the entire form would break them. The `.variations` table (colour/size dropdowns) and the default add-to-cart button are the only elements that conflict with our grid UI.

**Graceful degradation:** If JS is disabled, `wsg-active` is never added, so the default WC form remains fully functional.

### 5.3 Variation Map

Built server-side in PHP and passed to JS. Structure:

```json
{
  "navy": {
    "label": "Navy",
    "hex": "#1e3a5f",
    "sizes": [
      {
        "slug": "s",
        "label": "S",
        "variation_id": 201,
        "price": 5.50,
        "in_stock": true,
        "max_qty": 50
      }
    ]
  }
}
```

**Attribute detection** (in `helpers.php`):
1. Scan product's variation attributes for name/slug containing `color` or `colour` → that's the colour attribute
2. Scan for name/slug containing `size` → that's the size attribute
3. If neither is found: do not render the grid; log a warning to `debug.log` explaining which attributes were found and what was expected. The grid requires at least one recognized attribute.
4. If only one recognized attribute exists (e.g. size but no colour): skip colour swatches, show size grid directly

**No blind fallback.** Previous versions assumed "first attribute = colour, second = size" when names didn't match. This is fragile — a product with `material` + `size` attributes would misidentify material as colour. Instead, the plugin requires explicit `color`/`colour`/`size` naming and logs a clear warning when detection fails. The filter `wsg_is_enabled` (see 3.5) can be used to override detection for non-standard attribute names.

**Colour hex resolution** (in `helpers.php`):
1. Term meta: `product_attribute_color`, `color`, `_swatches_color` (covers popular swatch plugins)
2. Built-in map of ~30 common colour names → hex
3. Filter: `apply_filters('wsg_color_map', $map)` for custom additions
4. Fallback: `#cccccc`

**Size ordering:** Matches the product's attribute term order (as defined in WooCommerce → Attributes).

### 5.4 Render Container

**Hook:** `woocommerce_before_add_to_cart_form` (priority 15)

Output: `<div id="wsg-root" data-mode="product|bundle"></div>`

All UI is built client-side by `frontend.js` using the `wsgData` object. No server-side HTML for swatches or grid rows.

### 5.5 Bundle Price Display

**Hook:** `woocommerce_get_price_html` (priority 10)

**Context guard:** Only apply on single product pages (`is_product()`) and only for the main queried product (`get_queried_object_id() === $product->get_id()`). This prevents the bundle price from replacing the variation price range on shop/archive pages, related products, widgets, upsells, REST API responses, and admin screens.

When `_wsg_mode === 'bundle'` and `_wsg_bundle_price` is set and context checks pass: return `wc_price($bundle_price)` instead of the default variation price range.

```php
function wsg_bundle_price_html( $price_html, $product ) {
    if ( ! is_product() ) {
        return $price_html;
    }
    if ( get_queried_object_id() !== $product->get_id() ) {
        return $price_html;
    }
    // ... bundle price override ...
}
```

---

## 6. Frontend JS Behavior

### 6.1 Colour Swatches

**Product mode:** Single-select. Clicking a different colour clears all qty inputs and switches the grid. Clicking the same colour does nothing.

**Bundle mode:** Multi-select toggle. Click to add (grid appears for that colour). Click again to remove (grid and its quantities removed). Active colours shown as removable pill tags below the swatches.

### 6.2 Size/Quantity Grid

One table per selected colour.

| Column | Content |
|---|---|
| Size | Label from variation attribute (e.g. "S", "M", "XL") |
| Price | Formatted via `wsgFormatPrice(price)` using WC settings from `wsgData` (see below) |
| Qty | `<input type="number" min="0" value="0">` |

Out-of-stock rows: input disabled, "Out of stock" text shown.

**JS price formatting:** All prices in the grid, totals, and discount notes must use `wsgFormatPrice()` — a helper in `frontend.js` that replicates WC's `wc_price()` output using the settings localized in `wsgData`:

```javascript
function wsgFormatPrice( price ) {
    var formatted = price.toFixed( wsgData.decimals );
    // Apply decimal separator (e.g. ',' for European stores)
    formatted = formatted.replace( '.', wsgData.decimalSeparator );
    // Apply thousand separator
    var parts = formatted.split( wsgData.decimalSeparator );
    parts[0] = parts[0].replace( /\B(?=(\d{3})+(?!\d))/g, wsgData.thousandSeparator );
    formatted = parts.join( wsgData.decimalSeparator );
    // Apply currency position (e.g. '%1$s%2$s' = symbol+price, '%2$s %1$s' = price+symbol)
    return wsgData.priceFormat
        .replace( '%1$s', wsgData.currencySymbol )
        .replace( '%2$s', formatted );
}
```

This ensures correct output for all WC configurations: right-positioned symbols (`99,99 EUR`), comma decimals (`1.234,56`), zero-decimal currencies (JPY `1,234`), and 3-decimal currencies (KWD `1.234`).

### 6.3 Live Totals

Updated on every `input`/`change` event on qty fields. Does NOT re-render the full UI — only updates the footer numbers and progress bar for smooth typing.

**Product mode:**
- Total items count
- Active discount tier: "You save £X.XX per item"
- Total price: `Σ (price - discount) × qty`

**Bundle mode:**
- Progress bar: `(totalQty / requiredQty) × 100%`, green when exact, red when over
- Text: "12 of 16 items — 4 remaining"
- Total price: always the fixed bundle price (static)
- Add to Cart button: disabled unless `totalQty === requiredQty`

### 6.4 Add to Cart (AJAX)

**Endpoint:** `wc_ajax_wsg_add_to_cart` / `wc_ajax_nopriv_wsg_add_to_cart`

Uses WooCommerce's AJAX endpoint system (`/?wc-ajax=wsg_add_to_cart`) instead of `admin-ajax.php`. The URL is pre-built in `wsgData.ajaxUrl` via `WC_AJAX::get_endpoint()` (see 5.1).

**Payload:**
```json
{
  "security": "<nonce>",
  "product_id": 184,
  "mode": "product|bundle",
  "items": "[{\"variation_id\":201, \"color_slug\":\"navy\", \"color_label\":\"Navy\", \"color_hex\":\"#1e3a5f\", \"size_slug\":\"s\", \"size_label\":\"S\", \"qty\":3, \"price\":5.50}, ...]"
}
```

**Button states:**
- Default: "Add to Cart" / "Add Bundle to Cart"
- Loading: "Adding…" (disabled)
- Success: button resets, green pill "Added to cart ✓" appears, auto-fades after 3s
- Error: button resets, red box with server error message

**After success:** Trigger `$(document.body).trigger('wc_fragment_refresh')` for mini-cart update. If WC setting `woocommerce_cart_redirect_after_add` is `yes`, redirect to cart.

---

## 7. Cart — Product Mode

**File:** `cart-product-mode.php`

### 7.1 AJAX Handler

For each item with `qty > 0`:

```php
WC()->cart->add_to_cart(
    $product_id,
    $qty,
    $variation_id,
    $variation_attributes,   // e.g. ['attribute_pa_color' => 'navy', 'attribute_pa_size' => 's']
    $cart_item_data          // custom meta below
);
```

**Cart item data:**
```php
array(
    '_wsg_group_id'  => 'wsg_184_65a1b2c3',   // unique per add-to-cart action
    '_wsg_discount'  => 0.50,                   // tier discount at time of add
)
```

### 7.2 Bulk Discount Pricing

**Hook:** `woocommerce_before_calculate_totals` (priority 20)

**Idempotency guard:** This hook fires multiple times per request (mini-cart fragments, shipping calculations, coupon validation, other plugins). The callback must guard against re-application:

```php
function wsg_apply_bulk_discount( $cart ) {
    if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
        return;
    }
    if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
        return;
    }

    foreach ( $cart->get_cart() as $item ) {
        if ( empty( $item['_wsg_discount'] ) ) {
            continue;
        }
        $discount = floatval( $item['_wsg_discount'] );
        $original = floatval( $item['data']->get_price() );
        $item['data']->set_price( max( 0, $original - $discount ) );
    }
}
```

**Uses `get_price()`, not `get_regular_price()`.** This respects WC sale prices and third-party dynamic pricing plugins (membership pricing, role-based pricing, etc.). If a product is on sale for £8 and a £2 bulk discount applies, the customer pays £6 — not £8 (which would happen if we discounted from the £10 regular price).

WooCommerce handles tax on the adjusted price automatically.

### 7.3 Cart Display

**Hook:** `woocommerce_get_item_data`

Show below the item name:
```
Bulk discount: -£0.50 per item
```

### 7.4 Cart Behavior

- Each colour+size is a standard, independent WC cart item
- Customer CAN edit qty in cart (standard WC behavior)
- Discount is locked at time of add-to-cart (does not recalculate on qty change — documented as v1 limitation)
- Items from different add-to-cart actions never merge (unique `_wsg_group_id`)

---

## 8. Cart — Bundle Mode

**File:** `cart-bundle-mode.php`

### 8.1 AJAX Handler

1. Validate: `Σ item.qty === _wsg_bundle_qty`. Reject if not.
2. Generate `$bundle_id = 'wsg_bnd_' . $product_id . '_' . uniqid()`
3. For each item with `qty > 0` (indexed as 0, 1, 2…):

```php
WC()->cart->add_to_cart(
    $product_id,
    $qty,
    $variation_id,
    $variation_attributes,
    array(
        '_wsg_is_bundle'      => true,
        '_wsg_bundle_id'      => $bundle_id,
        '_wsg_bundle_price'   => 99.99,
        '_wsg_bundle_qty'     => 16,
        '_wsg_bundle_index'   => $index,       // 0, 1, 2…
        '_wsg_color_label'    => 'Hot Pink',
        '_wsg_size_label'     => 'XS',
        '_wsg_color_hex'      => '#ff1493',
    )
);
```

Each variation is a real WC cart item → stock deducted correctly by WooCommerce.

### 8.2 Bundle Pricing

**Hook:** `woocommerce_before_calculate_totals` (priority 20)

**Idempotency guard:** Same pattern as product mode (see 7.2) — skip if `is_admin()` without AJAX, or if `did_action() >= 2`.

Group cart items by `_wsg_bundle_id`. Within each group:
- Index 0 (first item): `$item['data']->set_price( $bundle_price )`
- Index > 0 (sub-items): `$item['data']->set_price( 0 )`

Result: cart total includes the bundle price exactly once.

### 8.3 Bundle Visibility in Cart

**Hook:** `woocommerce_cart_item_visible`
- Sub-items (index > 0): return `false` → hidden from cart table
- First item (index 0): return `true` → visible

**Hook:** `woocommerce_widget_cart_item_visible`
- Same logic → hides sub-items from mini-cart

### 8.4 Bundle Cart Item Name

**Hook:** `woocommerce_cart_item_name`

For the visible bundle item (index 0): override name with:
- `_wsg_bundle_display_name` if set by admin, OR
- `"{bundle_qty} × {product_title}"` as default

### 8.5 Bundle Quantity Display

**Hook:** `woocommerce_cart_item_quantity`

For the visible bundle item (index 0): return `<span>1</span>` (plain text, not editable). The customer bought 1 bundle.

### 8.6 Bundle Breakdown in Cart

**Hook:** `woocommerce_get_item_data`

For the visible bundle item (index 0): collect all items with the same `_wsg_bundle_id` from the cart. Render as:

```
Sizes ordered
● Hot Pink — XS × 10
● Heather Grey — XS × 6
```

With colour dots (inline CSS on spans).

### 8.7 Bundle Cascade Removal

**Hook:** `woocommerce_remove_cart_item`

When any item with `_wsg_is_bundle` is removed: iterate through the cart and remove ALL items sharing the same `_wsg_bundle_id`.

**Recursion guard (critical):** Each call to `$cart->remove_cart_item()` fires `woocommerce_remove_cart_item` again, which would re-enter this handler and cause infinite recursion. The callback must unhook itself before removing sibling items, then re-hook after:

```php
function wsg_cascade_bundle_removal( $cart_item_key, $cart ) {
    $removed = $cart->removed_cart_contents[ $cart_item_key ];

    if ( empty( $removed['_wsg_is_bundle'] ) ) {
        return;
    }

    $bundle_id = $removed['_wsg_bundle_id'];

    // Unhook to prevent recursion
    remove_action( 'woocommerce_remove_cart_item', 'wsg_cascade_bundle_removal', 10 );

    foreach ( $cart->get_cart() as $key => $item ) {
        if ( isset( $item['_wsg_bundle_id'] ) && $item['_wsg_bundle_id'] === $bundle_id ) {
            $cart->remove_cart_item( $key );
        }
    }

    // Re-hook for future removals
    add_action( 'woocommerce_remove_cart_item', 'wsg_cascade_bundle_removal', 10, 2 );
}
```

This is the same pattern used by WooCommerce Product Bundles and other grouping plugins.

### 8.8 Prevent Individual Editing

**Hook:** `woocommerce_cart_item_quantity`

For sub-items: this is moot since they're hidden. For the visible parent item: already handled (shows "1" as plain text).

---

## 9. Order & Email Display

**File:** `cart-shared.php`

### 9.1 Save to Order

**Hook:** `woocommerce_checkout_create_order_line_item`

**For bundle items (index 0):**
- Save meta `_wsg_is_bundle = true`
- Save meta `_wsg_bundle_id`
- Build and save human-readable `Sizes ordered` string from all cart items in the group:
  `"Hot Pink XS ×10, Heather Grey XS ×6"`

**For bundle sub-items (index > 0):**
- Save meta `_wsg_is_bundle = true`
- Save meta `_wsg_bundle_id`
- Save meta `_wsg_color_label`, `_wsg_size_label`

**For product mode items:**
- Save meta `_wsg_discount` if > 0

### 9.2 Admin Order View

Bundle sub-items appear as separate order line items at £0.00. The parent shows the bundle price with the "Sizes ordered" meta. This provides full visibility for fulfillment without custom admin UI.

### 9.3 Customer Emails

WooCommerce's default order table will include all line items. The "Sizes ordered" meta on the parent item will render automatically via WC's `woocommerce_display_item_meta`. No custom email template needed.

---

## 10. Hooks Reference

Complete list of WordPress/WooCommerce hooks the plugin uses:

### Admin
| Hook | Type | File | Purpose |
|---|---|---|---|
| `woocommerce_product_data_tabs` | Filter | admin-product-settings.php | Add "Size Grid" tab |
| `woocommerce_product_data_panels` | Action | admin-product-settings.php | Render tab content |
| `woocommerce_process_product_meta` | Action | admin-product-settings.php | Save settings |
| `admin_head` | Action | admin-product-settings.php | Tab icon CSS |

### Bootstrap
| Hook | Type | File | Purpose |
|---|---|---|---|
| `before_woocommerce_init` | Action | workwear-size-grid.php | Declare HPOS + Blocks compatibility |

### Frontend
| Hook | Type | File | Purpose |
|---|---|---|---|
| `wp_enqueue_scripts` | Action | frontend-display.php | Load CSS/JS on product pages |
| `woocommerce_before_add_to_cart_form` | Action | frontend-display.php | Render `#wsg-root` container |
| `woocommerce_get_price_html` | Filter | frontend-display.php | Bundle price display (guarded: single product page only) |
| `wp_head` | Action | frontend-display.php | Inject `wsg-active` class on `<html>` + CSS to hide default form elements |

### Cart — Product Mode
| Hook | Type | File | Purpose |
|---|---|---|---|
| `wc_ajax[_nopriv]_wsg_add_to_cart` | Action | cart-product-mode.php | WC AJAX add-to-cart |
| `woocommerce_before_calculate_totals` | Action | cart-product-mode.php | Apply bulk discount (guarded: idempotent) |
| `woocommerce_get_item_data` | Filter | cart-product-mode.php | Show discount note |

### Cart — Bundle Mode
| Hook | Type | File | Purpose |
|---|---|---|---|
| `wc_ajax[_nopriv]_wsg_add_to_cart` | Action | cart-bundle-mode.php | WC AJAX add-to-cart |
| `woocommerce_before_calculate_totals` | Action | cart-bundle-mode.php | Bundle price, first=price rest=0 (guarded: idempotent) |
| `woocommerce_cart_item_visible` | Filter | cart-bundle-mode.php | Hide sub-items |
| `woocommerce_widget_cart_item_visible` | Filter | cart-bundle-mode.php | Hide sub-items from mini-cart |
| `woocommerce_cart_item_name` | Filter | cart-bundle-mode.php | Override bundle item name |
| `woocommerce_cart_item_quantity` | Filter | cart-bundle-mode.php | Show "1" (non-editable) |
| `woocommerce_get_item_data` | Filter | cart-bundle-mode.php | Show sizes breakdown |
| `woocommerce_remove_cart_item` | Action | cart-bundle-mode.php | Cascade remove all bundle items (guarded: unhook/rehook) |
| `woocommerce_add_to_cart_validation` | Filter | cart-bundle-mode.php | Allow parent product without variation |

### Order / Email
| Hook | Type | File | Purpose |
|---|---|---|---|
| `woocommerce_checkout_create_order_line_item` | Action | cart-shared.php | Save bundle/discount meta to order |

---

## 11. Post Meta Reference

**Per-product meta** (set by admin):

| Key | Type | Mode | Description |
|---|---|---|---|
| `_wsg_enabled` | `'yes'` or `''` | Both | Whether size grid is active |
| `_wsg_mode` | `'product'` or `'bundle'` | Both | Operating mode |
| `_wsg_discount_tiers` | Serialized array | Product | `[{min, max, discount}, ...]` |
| `_wsg_bundle_qty` | int | Bundle | Required total items |
| `_wsg_bundle_price` | float | Bundle | Fixed bundle price |
| `_wsg_bundle_display_name` | string | Bundle | Optional cart display name override |

**Cart item meta** (set at add-to-cart time):

| Key | Type | Mode | Description |
|---|---|---|---|
| `_wsg_group_id` | string | Product | Groups items from same add-to-cart action |
| `_wsg_discount` | float | Product | Discount per item from tier lookup |
| `_wsg_is_bundle` | bool | Bundle | Flags item as part of a bundle |
| `_wsg_bundle_id` | string | Bundle | Groups all items in one bundle |
| `_wsg_bundle_price` | float | Bundle | The total bundle price |
| `_wsg_bundle_qty` | int | Bundle | Required total items |
| `_wsg_bundle_index` | int | Bundle | 0 = visible parent, 1+ = hidden sub-item |
| `_wsg_color_label` | string | Bundle | Human-readable colour name |
| `_wsg_size_label` | string | Bundle | Human-readable size name |
| `_wsg_color_hex` | string | Bundle | Hex for colour dot display |

---

## 12. Edge Cases

| Scenario | Behavior |
|---|---|
| Product with only size attribute (no colour) | Skip colour swatches, show size grid directly with no colour header |
| Product with only colour attribute (no size) | Show colour swatches, single qty input per colour (one-row grid, no "Size" column) |
| Product with no recognized attributes (no color/colour/size) | Do not render grid; log warning to debug.log with attribute names found; default WC form remains |
| Variation out of stock | Qty input disabled, "Out of stock" shown, row visually greyed |
| Variation has stock limit (max_qty) | Input `max` attribute set; server-side WC validation enforces it |
| Bundle: total exceeds required | Progress bar turns red, button stays disabled |
| Bundle: remove from cart | All items with same `_wsg_bundle_id` removed (cascade) |
| Product mode: add same colour/sizes twice | New cart items created (different `_wsg_group_id`), no merge |
| Bundle mode: add same bundle product twice | Two independent bundle groups in cart |
| Cart qty changed (product mode) | Allowed; discount does NOT recalculate (v1 limitation) |
| JS disabled | `wsg-active` class never added → default WC variation form remains functional |
| Admin sets bundle qty to 0 | Qty validation skipped (no limit enforced) |
| White / very light colour | Border always visible via CSS (light grey border on light swatches) |
| WC cart redirect after add enabled | JS follows redirect URL from AJAX response |

---

## 13. CSS Strategy

All styles in `assets/frontend.css`. Key principles:

- All selectors scoped under `#wsg-root` — the ID selector provides specificity of 100, which reliably overrides theme class-based rules without needing `!important`
- **Specificity, not `!important`:** Use `#wsg-root .wsg-swatch` (specificity 110) instead of `.wsg-swatch !important`. This is composable — child themes and other plugins can still override with `#wsg-root .wsg-swatch.custom-class` or their own ID selectors
- **`!important` reserved for targeted overrides only:** The form-hiding rules in Section 5.2 (`.wsg-active .variations_form .variations { display: none !important; }`) are the exception — these must override any theme that sets `display` on those elements. All other declarations must NOT use `!important`
- Browser spinners hidden on number inputs
- Responsive breakpoint at 600px: grid stacks, swatches shrink, button goes full width
- Colour swatch selected state uses `box-shadow` (not border) to avoid layout shift
- No CSS framework dependencies
- Stylesheet loaded at default `wp_enqueue_scripts` priority (10), which places it after most theme stylesheets

---

## 14. Testing Checklist

### Product Mode
- [ ] Colour swatches render with correct hex values
- [ ] Clicking a colour shows the size grid for that colour
- [ ] Clicking a different colour resets qty inputs and swaps the grid
- [ ] Entering qty updates total items count and price in real time
- [ ] Bulk discount note appears when tier threshold is crossed
- [ ] "Add to Cart" adds separate cart items per size
- [ ] Cart shows correct per-item price with discount applied
- [ ] Cart item quantities are individually editable
- [ ] Mini-cart updates after adding

### Bundle Mode
- [ ] Multiple colours can be selected simultaneously
- [ ] Each colour gets its own size grid section
- [ ] Progress bar updates as quantities change
- [ ] Progress bar turns red when over the required total
- [ ] Button disabled until exact total reached
- [ ] "Add Bundle to Cart" creates a single visible cart entry at the bundle price
- [ ] Cart shows "Sizes ordered" breakdown with colour dots
- [ ] Cart quantity shows "1" (non-editable)
- [ ] Removing the bundle removes ALL associated cart items
- [ ] Sub-items hidden from cart and mini-cart
- [ ] Bundle display name shows correctly in cart

### General
- [ ] Out-of-stock sizes show disabled inputs
- [ ] Default WC variation dropdowns and add-to-cart button are hidden
- [ ] Other plugins' buttons inside `form.cart` (wishlists, compare) remain visible
- [ ] With JS disabled, default WC form still works
- [ ] No FOUC — default form does not flash before hiding
- [ ] WC tax settings (inc/exc) reflected correctly in prices
- [ ] Mobile layout at 375px width
- [ ] Order admin screen shows bundle breakdown
- [ ] Customer order confirmation email includes sizes ordered

### Price Formatting
- [ ] Prices display correctly with comma decimal separator (e.g. `1.234,56`)
- [ ] Prices display correctly with right-positioned currency symbol (e.g. `99,99 EUR`)
- [ ] Zero-decimal currencies display without decimals (e.g. JPY `1,234`)
- [ ] Thousand separators applied correctly

### Compatibility
- [ ] No HPOS warning in WooCommerce → Status → Compatibility
- [ ] Cart/Checkout Blocks incompatibility notice shown when Blocks are active
- [ ] Sale prices respected — bulk discount applies to sale price, not regular price
- [ ] Products with unrecognized attributes (e.g. `material` + `fit`) show default WC form, not broken grid
- [ ] Cascade bundle removal does not cause PHP errors or remove unrelated cart items
- [ ] AJAX add-to-cart works with full-page caching enabled

---

## 15. Out of Scope (v1)

- Logo / print / embroidery customisation
- Multiple different products in one bundle (v1 = single garment per bundle)
- Cart item grouping for product mode (items show as standard separate WC lines)
- Recalculating bulk discount when cart qty is manually changed
- Product image swap on colour selection (gallery stays static)
- Re-populating the grid when returning to product page from cart
- WooCommerce Blocks cart/checkout (classic shortcode cart/checkout only)
- REST API endpoints
- Gutenberg block for embedding the grid
- Coupon interaction with bundle pricing
- Multi-currency support beyond WC's default

---

## 16. Development Setup

### Environment
- LocalWP with WordPress 6.x, WooCommerce 9.x, PHP 8.1+
- Astra theme (production parity)
- `WP_DEBUG`, `WP_DEBUG_LOG`, `SCRIPT_DEBUG` enabled

### Claude Code
```bash
cd ~/Local\ Sites/{site}/app/public/wp-content/plugins/workwear-size-grid
claude
```

Claude Code reads `CLAUDE.md` in the plugin root automatically for project context.

### Test Data Required
- At least one Variable product with `pa_color` (or `pa_colour`) and `pa_size` attributes
- Multiple colour terms with enough variations to test multi-size selection
- At least one product configured as Bundle mode with required qty and price
- Some variations set to out-of-stock to test disabled states
