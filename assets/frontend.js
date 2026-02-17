/**
 * Workwear Size Grid — Frontend JS
 *
 * Renders colour swatches, size/qty grids, totals, and handles
 * AJAX add-to-cart for both Product and Bundle modes.
 *
 * @package WorkwearSizeGrid
 */

(function($) {
	'use strict';

	// State key separator — must not appear in WC taxonomy slugs.
	var SEP = '::';

	/* ───────────────────────────────────────────
	 * Price formatter
	 * ─────────────────────────────────────────── */

	function wsgFormatPrice(price) {
		price = parseFloat(price) || 0;
		var formatted = price.toFixed(parseInt(wsgData.decimals, 10) || 2);
		formatted = formatted.replace('.', wsgData.decimalSeparator);
		var parts = formatted.split(wsgData.decimalSeparator);
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, wsgData.thousandSeparator);
		formatted = parts.join(wsgData.decimalSeparator);
		return wsgData.priceFormat
			.replace('%1$s', wsgData.currencySymbol)
			.replace('%2$s', formatted);
	}

	/* ───────────────────────────────────────────
	 * State
	 * ─────────────────────────────────────────── */

	var state = {
		selectedColors: [],   // array of colour slugs
		quantities: {}        // { 'navy::s': 3, 'navy::m': 5, ... }
	};

	/* ───────────────────────────────────────────
	 * Tier lookup (product mode)
	 * ─────────────────────────────────────────── */

	function getMatchingTier(totalQty) {
		if (!wsgData.tiers || !wsgData.tiers.length) {
			return null;
		}

		for (var i = 0; i < wsgData.tiers.length; i++) {
			var tier = wsgData.tiers[i];
			var min  = parseInt(tier.min, 10) || 0;
			var max  = parseInt(tier.max, 10) || 0;

			if (totalQty >= min && (!max || totalQty <= max)) {
				return tier;
			}
		}

		return null;
	}

	/* ───────────────────────────────────────────
	 * Light colour detection
	 * ─────────────────────────────────────────── */

	function isLightColor(hex) {
		hex = hex.replace('#', '');
		if (hex.length === 3) {
			hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
		}
		var r = parseInt(hex.substring(0, 2), 16);
		var g = parseInt(hex.substring(2, 4), 16);
		var b = parseInt(hex.substring(4, 6), 16);
		return (r > 200 && g > 200 && b > 200);
	}

	/* ───────────────────────────────────────────
	 * Parse state key
	 * ─────────────────────────────────────────── */

	function parseStateKey(key) {
		var idx = key.indexOf(SEP);
		return {
			colorSlug: key.substring(0, idx),
			sizeSlug:  key.substring(idx + SEP.length)
		};
	}

	/* ───────────────────────────────────────────
	 * Render: Swatches
	 * ─────────────────────────────────────────── */

	function renderSwatches() {
		var $root      = $('#wsg-root');
		var colorKeys  = Object.keys(wsgData.colorSizeMap);
		var isDefault  = (colorKeys.length === 1 && colorKeys[0] === 'default');

		// Skip heading and swatches if only 'default' key exists.
		if (isDefault) {
			return;
		}

		var $heading = $('<h3 class="wsg-section-title">').text(wsgData.i18n.selectColour);
		$root.append($heading);

		var $swatches = $('<div class="wsg-swatches">');

		$.each(wsgData.colorSizeMap, function(slug, colorData) {
			var $btn = $('<button type="button">')
				.addClass('wsg-swatch')
				.toggleClass('wsg-swatch--light', isLightColor(colorData.hex))
				.attr('data-color', slug)
				.attr('title', colorData.label)
				.css('background-color', colorData.hex);

			$btn.append($('<span class="screen-reader-text">').text(colorData.label));
			$swatches.append($btn);
		});

		$root.append($swatches);

		// Bundle mode gets a selected-colours pill container.
		if (wsgData.mode === 'bundle') {
			$root.append('<div class="wsg-selected-colors"></div>');
		}
	}

	/* ───────────────────────────────────────────
	 * Render: Grid (one per colour)
	 * ─────────────────────────────────────────── */

	function renderGrid(colorSlug) {
		var colorData  = wsgData.colorSizeMap[colorSlug];
		var colorKeys  = Object.keys(wsgData.colorSizeMap);
		var isDefault  = (colorKeys.length === 1 && colorKeys[0] === 'default');

		var $grid = $('<div class="wsg-grid">').attr('data-color', colorSlug);

		// Grid title — skip for 'default' (no colour attribute).
		if (!isDefault) {
			var $title = $('<h4 class="wsg-grid-title">');
			$title.append(
				$('<span class="wsg-grid-dot">').css('background-color', colorData.hex)
			);
			$title.append(document.createTextNode(colorData.label));
			$grid.append($title);
		}

		var $table = $(
			'<table class="wsg-grid-table">' +
				'<thead><tr></tr></thead>' +
				'<tbody></tbody>' +
			'</table>'
		);

		// Build thead with safe text.
		var $headRow = $table.find('thead tr');
		$headRow.append($('<th>').text(wsgData.i18n.size));
		$headRow.append($('<th>').text(wsgData.i18n.price));
		$headRow.append($('<th>').text(wsgData.i18n.qty));

		var $tbody = $table.find('tbody');

		$.each(colorData.sizes, function(i, size) {
			var $row = $('<tr>');

			if (!size.in_stock) {
				$row.addClass('wsg-row--disabled');
				$row.append($('<td>').text(size.label));
				$row.append($('<td>').html(wsgFormatPrice(size.price)));
				$row.append(
					$('<td>').append(
						$('<span class="wsg-out-of-stock-text">').text(wsgData.i18n.outOfStock)
					)
				);
			} else {
				var stateKey   = colorSlug + SEP + size.slug;
				var currentQty = state.quantities[stateKey] || 0;

				var $input = $('<input type="number" class="wsg-qty-input">')
					.attr('data-color', colorSlug)
					.attr('data-size', size.slug)
					.attr('data-variation', size.variation_id)
					.attr('data-price', size.price)
					.attr('min', 0)
					.val(currentQty);

				if (size.max_qty !== '' && size.max_qty !== null && size.max_qty !== undefined) {
					$input.attr('max', parseInt(size.max_qty, 10));
				}

				$row.append($('<td>').text(size.label));
				$row.append($('<td>').html(wsgFormatPrice(size.price)));
				$row.append($('<td>').append($input));
			}

			$tbody.append($row);
		});

		$grid.append($table);
		return $grid;
	}

	/* ───────────────────────────────────────────
	 * Render: Totals
	 * ─────────────────────────────────────────── */

	function renderTotals() {
		var $totals = $('<div class="wsg-totals">');

		if (wsgData.mode === 'product') {
			$totals.append(
				'<div class="wsg-totals-row wsg-totals-count"></div>' +
				'<div class="wsg-totals-row wsg-totals-discount" style="display:none"></div>' +
				'<div class="wsg-totals-row wsg-totals-price"><strong></strong></div>'
			);
		} else {
			// Bundle mode.
			$totals.append(
				'<div class="wsg-progress"><div class="wsg-progress-bar" style="width:0%"></div></div>' +
				'<div class="wsg-totals-row wsg-totals-count"></div>' +
				'<div class="wsg-totals-row wsg-totals-remaining"></div>' +
				'<div class="wsg-totals-row wsg-totals-price"><strong></strong></div>'
			);
		}

		return $totals;
	}

	/* ───────────────────────────────────────────
	 * Render: Add-to-cart button
	 * ─────────────────────────────────────────── */

	function renderButton() {
		var btnLabel = (wsgData.mode === 'bundle') ? wsgData.i18n.addBundle : wsgData.i18n.addToCart;
		var $wrap    = $('<div class="wsg-actions">');

		$wrap.append(
			$('<button type="button" class="wsg-add-to-cart button alt" disabled>').text(btnLabel)
		);
		$wrap.append('<div class="wsg-message" style="display:none"></div>');

		return $wrap;
	}

	/* ───────────────────────────────────────────
	 * Update totals + button state
	 * ─────────────────────────────────────────── */

	function updateTotals() {
		var totalQty = 0;

		$.each(state.quantities, function(key, qty) {
			totalQty += parseInt(qty, 10) || 0;
		});

		if (wsgData.mode === 'product') {
			// --- Product mode ---
			var tier      = getMatchingTier(totalQty);
			var discount  = tier ? parseFloat(tier.discount) : 0;
			var totalPrice = 0;

			$.each(state.quantities, function(key, qty) {
				qty = parseInt(qty, 10) || 0;
				if (qty <= 0) {
					return;
				}

				var parsed    = parseStateKey(key);
				var colorData = wsgData.colorSizeMap[parsed.colorSlug];

				if (!colorData) {
					return;
				}

				var sizePrice = 0;
				$.each(colorData.sizes, function(i, s) {
					if (s.slug === parsed.sizeSlug) {
						sizePrice = parseFloat(s.price);
						return false;
					}
				});

				totalPrice += (sizePrice - discount) * qty;
			});

			// Prevent negative totals.
			if (totalPrice < 0) {
				totalPrice = 0;
			}

			// Update count.
			$('.wsg-totals-count').text(
				wsgData.i18n.totalItems.replace('%d', totalQty)
			);

			// Update discount row.
			if (discount > 0 && totalQty > 0) {
				$('.wsg-totals-discount')
					.text(wsgData.i18n.youSave.replace('%s', wsgFormatPrice(discount)))
					.show();
			} else {
				$('.wsg-totals-discount').hide();
			}

			// Update price.
			$('.wsg-totals-price strong').text(
				wsgData.i18n.total.replace('%s', wsgFormatPrice(totalPrice))
			);

			// Enable button if at least 1 item.
			$('.wsg-add-to-cart').prop('disabled', totalQty <= 0);

		} else {
			// --- Bundle mode ---
			var bundleQty   = parseInt(wsgData.bundleQty, 10) || 0;
			var percent     = bundleQty > 0 ? Math.min((totalQty / bundleQty) * 100, 100) : 0;
			var remaining   = bundleQty - totalQty;

			// Progress bar.
			$('.wsg-progress-bar').css('width', percent + '%');

			if (totalQty > bundleQty) {
				$('.wsg-progress').addClass('wsg-progress--over');
			} else {
				$('.wsg-progress').removeClass('wsg-progress--over');
			}

			// Count.
			$('.wsg-totals-count').text(
				wsgData.i18n.itemsOf
					.replace('%1$d', totalQty)
					.replace('%2$d', bundleQty)
			);

			// Remaining.
			$('.wsg-totals-remaining').text(
				wsgData.i18n.remaining.replace('%d', Math.max(remaining, 0))
			);

			// Price.
			$('.wsg-totals-price strong').text(
				wsgData.i18n.bundlePrice.replace('%s', wsgFormatPrice(wsgData.bundlePrice))
			);

			// Enable only when exact qty met.
			$('.wsg-add-to-cart').prop('disabled', totalQty !== bundleQty);
		}
	}

	/* ───────────────────────────────────────────
	 * Update bundle pills
	 * ─────────────────────────────────────────── */

	function updateBundlePills() {
		var $container = $('.wsg-selected-colors');
		$container.empty();

		$.each(state.selectedColors, function(i, slug) {
			var colorData = wsgData.colorSizeMap[slug];
			if (!colorData) {
				return;
			}

			var $pill = $('<span class="wsg-color-pill">').attr('data-color', slug);
			$pill.append(
				$('<span class="wsg-pill-dot">').css('background-color', colorData.hex)
			);
			$pill.append(document.createTextNode(colorData.label));

			var $remove = $('<button type="button" class="wsg-pill-remove">')
				.attr('data-color', slug)
				.html('&times;');
			$pill.append($remove);

			$container.append($pill);
		});
	}

	/* ───────────────────────────────────────────
	 * Remove quantities for a colour
	 * ─────────────────────────────────────────── */

	function removeColorQuantities(colorSlug) {
		var prefix = colorSlug + SEP;
		var toDelete = [];

		$.each(state.quantities, function(key) {
			if (key.indexOf(prefix) === 0) {
				toDelete.push(key);
			}
		});

		$.each(toDelete, function(i, key) {
			delete state.quantities[key];
		});
	}

	/* ───────────────────────────────────────────
	 * Event: Swatch click
	 * ─────────────────────────────────────────── */

	function handleSwatchClick(e) {
		e.preventDefault();

		var $btn      = $(this);
		var colorSlug = $btn.data('color');
		var $grids    = $('.wsg-grids-container');

		if (wsgData.mode === 'product') {
			// Single-select: do nothing if already active.
			if ($btn.hasClass('wsg-swatch--active')) {
				return;
			}

			$('.wsg-swatch').removeClass('wsg-swatch--active');
			$btn.addClass('wsg-swatch--active');

			state.selectedColors = [colorSlug];
			state.quantities     = {};

			$grids.find('.wsg-grid').remove();
			$grids.append(renderGrid(colorSlug));

		} else {
			// Bundle mode: multi-toggle.
			if ($btn.hasClass('wsg-swatch--active')) {
				// Deactivate.
				$btn.removeClass('wsg-swatch--active');

				var idx = state.selectedColors.indexOf(colorSlug);
				if (idx !== -1) {
					state.selectedColors.splice(idx, 1);
				}

				$grids.find('.wsg-grid[data-color="' + colorSlug + '"]').remove();
				removeColorQuantities(colorSlug);
			} else {
				// Activate.
				$btn.addClass('wsg-swatch--active');
				state.selectedColors.push(colorSlug);
				$grids.append(renderGrid(colorSlug));
			}

			updateBundlePills();
		}

		updateTotals();
	}

	/* ───────────────────────────────────────────
	 * Event: Pill remove click
	 * ─────────────────────────────────────────── */

	function handlePillRemove(e) {
		e.preventDefault();
		e.stopPropagation();

		var colorSlug = $(this).data('color');

		// Trigger same toggle logic via swatch button.
		$('.wsg-swatch[data-color="' + colorSlug + '"]').trigger('click');
	}

	/* ───────────────────────────────────────────
	 * Event: Qty input change
	 * ─────────────────────────────────────────── */

	function handleQtyChange() {
		var $input    = $(this);
		var colorSlug = $input.data('color');
		var sizeSlug  = $input.data('size');
		var val       = parseInt($input.val(), 10) || 0;

		// Enforce min.
		if (val < 0) {
			val = 0;
			$input.val(0);
		}

		// Enforce max if set.
		var max = $input.attr('max');
		if (max !== undefined && max !== '' && val > parseInt(max, 10)) {
			val = parseInt(max, 10);
			$input.val(val);
		}

		var stateKey = colorSlug + SEP + sizeSlug;

		if (val > 0) {
			state.quantities[stateKey] = val;
		} else {
			delete state.quantities[stateKey];
		}

		updateTotals();
	}

	/* ───────────────────────────────────────────
	 * Event: Add to Cart (AJAX)
	 * ─────────────────────────────────────────── */

	function handleAddToCart(e) {
		e.preventDefault();

		var $btn     = $(this);
		var btnLabel = (wsgData.mode === 'bundle') ? wsgData.i18n.addBundle : wsgData.i18n.addToCart;

		// Build items array.
		var items = [];

		$.each(state.quantities, function(key, qty) {
			qty = parseInt(qty, 10) || 0;
			if (qty <= 0) {
				return;
			}

			var parsed    = parseStateKey(key);
			var colorData = wsgData.colorSizeMap[parsed.colorSlug];

			if (!colorData) {
				return;
			}

			var sizeData = null;
			$.each(colorData.sizes, function(i, s) {
				if (s.slug === parsed.sizeSlug) {
					sizeData = s;
					return false;
				}
			});

			if (!sizeData) {
				return;
			}

			items.push({
				variation_id: sizeData.variation_id,
				color_slug:   parsed.colorSlug,
				color_label:  colorData.label,
				color_hex:    colorData.hex,
				size_slug:    parsed.sizeSlug,
				size_label:   sizeData.label,
				qty:          qty,
				price:        sizeData.price
			});
		});

		if (!items.length) {
			return;
		}

		// Disable button and show "Adding..." text.
		$btn.prop('disabled', true).text(wsgData.i18n.adding);

		var $message = $('.wsg-message');
		$message.hide().removeClass('wsg-message--success wsg-message--error').text('');

		$.ajax({
			url:    wsgData.ajaxUrl,
			method: 'POST',
			data: {
				security:   wsgData.nonce,
				product_id: wsgData.productId,
				mode:       wsgData.mode,
				items:      JSON.stringify(items)
			},
			success: function(response) {
				if (response.success) {
					// Show success message.
					$message
						.addClass('wsg-message--success')
						.html(wsgData.i18n.added + ' &#10003;')
						.fadeIn();

					// Auto-hide after 3 seconds.
					setTimeout(function() {
						$message.fadeOut();
					}, 3000);

					// Trigger WooCommerce cart fragment refresh.
					$(document.body).trigger('wc_fragment_refresh');

					// Redirect if needed.
					if (response.data && response.data.redirect) {
						window.location.href = response.data.cart_url;
						return;
					}

					// Reset quantities and grid inputs.
					state.quantities = {};
					$('.wsg-qty-input').val(0);
					updateTotals();
				} else {
					// Show error message.
					var errorMsg = (response.data && response.data.message)
						? response.data.message
						: wsgData.i18n.error;

					$message
						.addClass('wsg-message--error')
						.text(errorMsg)
						.fadeIn();
				}
			},
			error: function() {
				$message
					.addClass('wsg-message--error')
					.text(wsgData.i18n.error)
					.fadeIn();
			},
			complete: function() {
				$btn.prop('disabled', false).text(btnLabel);
				// Re-run totals to recalculate button state.
				updateTotals();
			}
		});
	}

	/* ───────────────────────────────────────────
	 * Initialise
	 * ─────────────────────────────────────────── */

	$(document).ready(function() {
		if (typeof wsgData === 'undefined' || !$('#wsg-root').length) {
			return;
		}

		var $root     = $('#wsg-root');
		var colorKeys = Object.keys(wsgData.colorSizeMap);

		// Render static UI.
		renderSwatches();
		$root.append('<div class="wsg-grids-container"></div>');
		$root.append(renderTotals());
		$root.append(renderButton());

		// Bind events via delegation on #wsg-root.
		$root.on('click', '.wsg-swatch', handleSwatchClick);
		$root.on('click', '.wsg-pill-remove', handlePillRemove);
		$root.on('input change', '.wsg-qty-input', handleQtyChange);
		$root.on('click', '.wsg-add-to-cart', handleAddToCart);

		// Auto-select first colour in product mode.
		if (wsgData.mode === 'product' && colorKeys.length > 0 && colorKeys[0] !== 'default') {
			$('.wsg-swatch[data-color="' + colorKeys[0] + '"]').trigger('click');
		}

		// If no colour attribute (only 'default'), show grid directly.
		if (colorKeys.length === 1 && colorKeys[0] === 'default') {
			$('.wsg-grids-container').append(renderGrid('default'));
		}

		updateTotals();
	});

})(jQuery);
