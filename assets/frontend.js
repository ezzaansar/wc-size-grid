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
		quantities: {},       // { 'navy::s': 3, 'navy::m': 5, ... }
		logo: {
			attachmentId: null,
			url:          null,
			positions:    [],     // array of position slugs (multi-select)
			method:       'print',
			notes:        '',
			noLogo:       false
		}
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
	 * Render: Logo Customization Section (3-step wizard)
	 * ─────────────────────────────────────────── */

	function renderLogoSection() {
		if (!wsgData.logoEnabled) {
			return null;
		}

		var $section = $('<div class="wsg-logo-section wsg-logo-section--collapsed">');

		// Clickable accordion header.
		var $header = $('<div class="wsg-logo-header">');
		var $title  = $('<h3 class="wsg-section-title">');
		$title.append(document.createTextNode(wsgData.i18n.logoTitle + ' '));
		$title.append($('<span class="wsg-logo-optional">').text(wsgData.i18n.logoOptional));
		$header.append($title);
		$header.append($('<span class="wsg-logo-chevron">'));
		$section.append($header);

		// Collapsible body.
		var $body = $('<div class="wsg-logo-body">');

		// Step indicator.
		var $steps = $('<div class="wsg-wizard-steps">');
		var stepLabels = [
			wsgData.i18n.stepPosition,
			wsgData.i18n.stepMethod,
			wsgData.i18n.stepLogo
		];
		for (var si = 0; si < 3; si++) {
			if (si > 0) {
				$steps.append($('<div class="wsg-wizard-step-line">'));
			}
			var $step = $('<div class="wsg-wizard-step">').attr('data-step', si + 1);
			$step.append($('<span class="wsg-wizard-step-num">').text(si + 1));
			$step.append($('<span class="wsg-wizard-step-label">').text(stepLabels[si]));
			if (si === 0) {
				$step.addClass('wsg-wizard-step--active');
			}
			$steps.append($step);
		}
		$body.append($steps);

		// ── Step 1: Position selection ──────────────────────
		var $pane1 = $('<div class="wsg-wizard-pane" data-pane="1">');
		$pane1.append($('<p class="wsg-wizard-subtitle">').text(wsgData.i18n.selectPositions));

		var printPrice = parseFloat(wsgData.logoPrintPrice) || 0;
		var embPrice   = parseFloat(wsgData.logoEmbroideryPrice) || 0;

		var $posGrid = $('<div class="wsg-logo-position-grid">');
		$.each(wsgData.logoPositions, function(i, pos) {
			var $card = $('<button type="button" class="wsg-logo-pos-card">').attr('data-position', pos.slug);

			var $tshirt = $('<div class="wsg-pos-tshirt">');
			var dotClass = 'wsg-pos-dot--' + pos.slug.replace(/_/g, '-');
			$tshirt.append($('<span>').addClass('wsg-pos-dot ' + dotClass));
			$card.append($tshirt);

			$card.append($('<span class="wsg-pos-name">').text(pos.label));

			var $badges = $('<div class="wsg-pos-badges">');
			$badges.append($('<span class="wsg-pos-badge wsg-pos-badge--print">').text(wsgData.i18n.printAvailable));
			$badges.append($('<span class="wsg-pos-badge wsg-pos-badge--emb">').text(wsgData.i18n.embAvailable));
			$card.append($badges);

			$card.append($('<span class="wsg-pos-check" aria-hidden="true">').html('&#10003;'));

			$posGrid.append($card);
		});
		$pane1.append($posGrid);

		var $nav1 = $('<div class="wsg-wizard-nav">');
		$nav1.append(
			$('<button type="button" class="wsg-wizard-continue">').text(wsgData.i18n.continue).prop('disabled', true)
		);
		$pane1.append($nav1);
		$body.append($pane1);

		// ── Step 2: Method selection ────────────────────────
		var $pane2 = $('<div class="wsg-wizard-pane" data-pane="2">').css('display', 'none');

		var $methods = $('<div class="wsg-logo-method-cards">');

		var $printCard = $('<button type="button" class="wsg-logo-method-card wsg-logo-method-card--active">')
			.attr('data-method', 'print');
		$printCard.append($('<span class="wsg-method-icon wsg-method-icon--print">'));
		$printCard.append($('<span class="wsg-method-name">').text(wsgData.i18n.print));
		$printCard.append($('<span class="wsg-method-desc">').text(wsgData.i18n.printDesc));
		if (printPrice > 0) {
			$printCard.append(
				$('<span class="wsg-method-price">').text('+' + wsgFormatPrice(printPrice) + ' ' + wsgData.i18n.perItem)
			);
		}
		$methods.append($printCard);

		var $embCard = $('<button type="button" class="wsg-logo-method-card">').attr('data-method', 'embroidery');
		$embCard.append($('<span class="wsg-method-icon wsg-method-icon--embroidery">'));
		$embCard.append($('<span class="wsg-method-name">').text(wsgData.i18n.embroidery));
		$embCard.append($('<span class="wsg-method-desc">').text(wsgData.i18n.embroideryDesc));
		if (embPrice > 0) {
			$embCard.append(
				$('<span class="wsg-method-price">').text('+' + wsgFormatPrice(embPrice) + ' ' + wsgData.i18n.perItem)
			);
		}
		$methods.append($embCard);
		$pane2.append($methods);

		var $nav2 = $('<div class="wsg-wizard-nav">');
		$nav2.append($('<button type="button" class="wsg-wizard-back">').text(wsgData.i18n.back));
		$nav2.append($('<button type="button" class="wsg-wizard-continue">').text(wsgData.i18n.continue));
		$pane2.append($nav2);
		$body.append($pane2);

		// ── Step 3: Logo upload ─────────────────────────────
		var $pane3 = $('<div class="wsg-wizard-pane" data-pane="3">').css('display', 'none');

		// Hidden file input.
		$pane3.append(
			$('<input type="file" class="wsg-logo-file-input" accept="image/*">').css('display', 'none')
		);

		// Drag-and-drop zone.
		var $dropzone = $('<div class="wsg-logo-dropzone">');
		$dropzone.append($('<div class="wsg-logo-drop-icon">'));
		$dropzone.append($('<div class="wsg-logo-drop-text">').text(wsgData.i18n.uploadLogo));
		$dropzone.append($('<div class="wsg-logo-drop-hint">').text(wsgData.i18n.dragDrop));
		var $spinner = $('<div class="wsg-logo-spinner">').css('display', 'none');
		$spinner.append($('<span class="wsg-logo-spinner-dot">'));
		$spinner.append(document.createTextNode(' ' + wsgData.i18n.uploading));
		$dropzone.append($spinner);
		$pane3.append($dropzone);

		// Preview card (hidden initially).
		var $preview = $('<div class="wsg-logo-preview">').css('display', 'none');
		$preview.append($('<img class="wsg-logo-preview-img">').attr('alt', 'Logo'));
		var $previewInfo = $('<div class="wsg-logo-preview-info">');
		$previewInfo.append(
			$('<span class="wsg-logo-preview-status">').text(wsgData.i18n.logoUploaded)
		);
		var $previewActions = $('<div class="wsg-logo-preview-actions">');
		$previewActions.append(
			$('<button type="button" class="wsg-logo-change">').text(wsgData.i18n.changeLogo)
		);
		$previewActions.append(
			$('<button type="button" class="wsg-logo-remove">').text(wsgData.i18n.removeLogo)
		);
		$previewInfo.append($previewActions);
		$preview.append($previewInfo);
		$pane3.append($preview);

		// "No logo" checkbox.
		var $noLogoLabel = $('<label class="wsg-logo-no-logo">');
		$noLogoLabel.append($('<input type="checkbox" class="wsg-logo-no-logo-input">'));
		$noLogoLabel.append(document.createTextNode(' ' + wsgData.i18n.noLogoOption));
		$pane3.append($noLogoLabel);

		// Notes textarea.
		var $notesWrap = $('<div class="wsg-logo-notes-wrap">');
		$notesWrap.append($('<label class="wsg-logo-field-label">').text(wsgData.i18n.addNotes));
		$notesWrap.append(
			$('<textarea class="wsg-logo-notes" rows="2">').attr('placeholder', wsgData.i18n.notesPlaceholder)
		);
		$pane3.append($notesWrap);

		// Surcharge note.
		$pane3.append($('<div class="wsg-logo-surcharge">'));

		var $nav3 = $('<div class="wsg-wizard-nav">');
		$nav3.append($('<button type="button" class="wsg-wizard-back">').text(wsgData.i18n.back));
		$nav3.append($('<button type="button" class="wsg-wizard-finish">').text(wsgData.i18n.finish));
		$pane3.append($nav3);
		$body.append($pane3);

		$section.append($body);
		return $section;
	}

	/* ───────────────────────────────────────────
	 * Logo: get current surcharge
	 * ─────────────────────────────────────────── */

	function getLogoSurcharge() {
		if (!wsgData.logoEnabled || state.logo.positions.length === 0) {
			return 0;
		}
		if (state.logo.method === 'embroidery') {
			return parseFloat(wsgData.logoEmbroideryPrice) || 0;
		}
		return parseFloat(wsgData.logoPrintPrice) || 0;
	}

	/* ───────────────────────────────────────────
	 * Logo: update surcharge note text
	 * ─────────────────────────────────────────── */

	function updateLogoSurchargeNote() {
		var surcharge = getLogoSurcharge();
		var $note     = $('.wsg-logo-surcharge');
		if (surcharge > 0) {
			$note.text(wsgData.i18n.logoSurcharge.replace('%s', wsgFormatPrice(surcharge)));
		} else {
			$note.text('');
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

		// Logo surcharge.
		var logoSurcharge    = getLogoSurcharge();
		// logoIncomplete: positions selected but neither a logo uploaded nor "no logo" ticked.
		var logoIncomplete   = state.logo.positions.length > 0 && !state.logo.attachmentId && !state.logo.noLogo;

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

				totalPrice += (sizePrice - discount + logoSurcharge) * qty;
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

			// Enable button if at least 1 item and logo is not incomplete.
			$('.wsg-add-to-cart').prop('disabled', totalQty <= 0 || logoIncomplete);

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

			// Price (bundle base + logo surcharge).
			var bundleTotal = parseFloat(wsgData.bundlePrice) + (logoSurcharge * bundleQty);
			$('.wsg-totals-price strong').text(
				wsgData.i18n.bundlePrice.replace('%s', wsgFormatPrice(bundleTotal))
			);

			// Enable only when exact qty met and logo not incomplete.
			$('.wsg-add-to-cart').prop('disabled', totalQty !== bundleQty || logoIncomplete);
		}

		// Update logo surcharge note.
		updateLogoSurchargeNote();
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
	 * Event: Logo accordion toggle
	 * ─────────────────────────────────────────── */

	function handleLogoToggle() {
		$(this).closest('.wsg-logo-section').toggleClass('wsg-logo-section--collapsed');
	}

	/* ───────────────────────────────────────────
	 * Event: Logo dropzone click
	 * ─────────────────────────────────────────── */

	function handleDropzoneClick(e) {
		if ($(e.target).closest('.wsg-logo-preview').length) {
			return;
		}
		$('.wsg-logo-file-input').trigger('click');
	}

	/* ───────────────────────────────────────────
	 * Event: Logo change button click
	 * ─────────────────────────────────────────── */

	function handleLogoChangeClick(e) {
		e.preventDefault();
		$('.wsg-logo-file-input').trigger('click');
	}

	/* ───────────────────────────────────────────
	 * Event: Logo drag events
	 * ─────────────────────────────────────────── */

	function handleDragOver(e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).addClass('wsg-logo-dropzone--dragover');
	}

	function handleDragLeave(e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).removeClass('wsg-logo-dropzone--dragover');
	}

	function handleDrop(e) {
		e.preventDefault();
		e.stopPropagation();
		$(this).removeClass('wsg-logo-dropzone--dragover');

		var files = e.originalEvent.dataTransfer && e.originalEvent.dataTransfer.files;
		if (files && files.length) {
			processLogoFile(files[0]);
		}
	}

	/* ───────────────────────────────────────────
	 * Event: Logo file selected (input change)
	 * ─────────────────────────────────────────── */

	function handleLogoFileChange() {
		var file = this.files && this.files[0];
		if (!file) {
			return;
		}
		processLogoFile(file);
	}

	/* ───────────────────────────────────────────
	 * Logo: process and upload a file
	 * ─────────────────────────────────────────── */

	function processLogoFile(file) {
		// Client-side type validation.
		var allowed = (wsgData.logoAllowedTypes || '').split(',');
		if (allowed.length && allowed.indexOf(file.type) === -1) {
			$('.wsg-message')
				.removeClass('wsg-message--success')
				.addClass('wsg-message--error')
				.text(wsgData.i18n.invalidFileType)
				.fadeIn();
			return;
		}

		// Client-side size validation.
		var maxSize = parseInt(wsgData.logoMaxSize, 10) || (5 * 1024 * 1024);
		if (file.size > maxSize) {
			$('.wsg-message')
				.removeClass('wsg-message--success')
				.addClass('wsg-message--error')
				.text(wsgData.i18n.fileTooLarge)
				.fadeIn();
			return;
		}

		// Show uploading state.
		var $dropzone = $('.wsg-logo-dropzone');
		$dropzone.addClass('wsg-logo-dropzone--uploading');
		$dropzone.find('.wsg-logo-drop-icon, .wsg-logo-drop-text, .wsg-logo-drop-hint').hide();
		$dropzone.find('.wsg-logo-spinner').show();

		var formData = new FormData();
		formData.append('logo_file', file);
		formData.append('security', wsgData.nonce);

		$.ajax({
			url:         wsgData.logoUploadUrl,
			method:      'POST',
			data:        formData,
			processData: false,
			contentType: false,
			success: function(response) {
				if (response.success) {
					state.logo.attachmentId = response.data.attachment_id;
					state.logo.url          = response.data.url;

					// Hide dropzone, show preview card.
					$dropzone.hide();
					$('.wsg-logo-preview-img').attr('src', response.data.thumbnail || response.data.url);
					$('.wsg-logo-preview').show();

					// Auto-expand if collapsed.
					$('.wsg-logo-section').removeClass('wsg-logo-section--collapsed');

					updateTotals();
				} else {
					var msg = (response.data && response.data.message) ? response.data.message : wsgData.i18n.error;
					$('.wsg-message')
						.removeClass('wsg-message--success')
						.addClass('wsg-message--error')
						.text(msg)
						.fadeIn();
				}
			},
			error: function() {
				$('.wsg-message')
					.removeClass('wsg-message--success')
					.addClass('wsg-message--error')
					.text(wsgData.i18n.error)
					.fadeIn();
			},
			complete: function() {
				// Restore dropzone state.
				$dropzone.removeClass('wsg-logo-dropzone--uploading');
				$dropzone.find('.wsg-logo-spinner').hide();
				$dropzone.find('.wsg-logo-drop-icon, .wsg-logo-drop-text, .wsg-logo-drop-hint').show();
				// Reset file input so same file can be re-selected.
				$('.wsg-logo-file-input').val('');
			}
		});
	}

	/* ───────────────────────────────────────────
	 * Event: Logo remove (step 3 — keep positions/method, reset upload)
	 * ─────────────────────────────────────────── */

	function handleLogoRemove(e) {
		e.preventDefault();

		state.logo.attachmentId = null;
		state.logo.url          = null;
		state.logo.noLogo       = false;

		// Show dropzone, hide preview.
		$('.wsg-logo-preview').hide();
		$('.wsg-logo-dropzone').show();
		$('.wsg-logo-no-logo-input').prop('checked', false);

		updateTotals();
	}

	/* ───────────────────────────────────────────
	 * Event: Logo position card click (multi-select)
	 * ─────────────────────────────────────────── */

	function handleLogoPositionCard(e) {
		e.preventDefault();
		var $card = $(this);
		$card.toggleClass('wsg-logo-pos-card--active');

		var pos = $card.data('position');
		var idx = state.logo.positions.indexOf(pos);
		if (idx === -1) {
			state.logo.positions.push(pos);
		} else {
			state.logo.positions.splice(idx, 1);
		}

		// Enable Continue on step 1 only when at least one position selected.
		$('.wsg-wizard-pane[data-pane="1"] .wsg-wizard-continue')
			.prop('disabled', state.logo.positions.length === 0);

		updateTotals();
	}

	/* ───────────────────────────────────────────
	 * Event: Logo method card click
	 * ─────────────────────────────────────────── */

	function handleLogoMethodCard(e) {
		e.preventDefault();
		var $card = $(this);

		$('.wsg-logo-method-card').removeClass('wsg-logo-method-card--active');
		$card.addClass('wsg-logo-method-card--active');

		state.logo.method = $card.data('method');
		updateTotals();
	}

	/* ───────────────────────────────────────────
	 * Event: Wizard Continue button
	 * ─────────────────────────────────────────── */

	function handleWizardContinue(e) {
		e.preventDefault();
		var $pane     = $(this).closest('.wsg-wizard-pane');
		var currStep  = parseInt($pane.data('pane'), 10);
		var nextStep  = currStep + 1;

		$pane.hide();
		$('.wsg-wizard-pane[data-pane="' + nextStep + '"]').show();

		$('.wsg-wizard-step').removeClass('wsg-wizard-step--active wsg-wizard-step--done');
		for (var sd = 1; sd < nextStep; sd++) {
			$('.wsg-wizard-step[data-step="' + sd + '"]').addClass('wsg-wizard-step--done');
		}
		$('.wsg-wizard-step[data-step="' + nextStep + '"]').addClass('wsg-wizard-step--active');
	}

	/* ───────────────────────────────────────────
	 * Event: Wizard Back button
	 * ─────────────────────────────────────────── */

	function handleWizardBack(e) {
		e.preventDefault();
		var $pane    = $(this).closest('.wsg-wizard-pane');
		var currStep = parseInt($pane.data('pane'), 10);
		var prevStep = currStep - 1;

		$pane.hide();
		$('.wsg-wizard-pane[data-pane="' + prevStep + '"]').show();

		$('.wsg-wizard-step').removeClass('wsg-wizard-step--active wsg-wizard-step--done');
		for (var sb = 1; sb < prevStep; sb++) {
			$('.wsg-wizard-step[data-step="' + sb + '"]').addClass('wsg-wizard-step--done');
		}
		$('.wsg-wizard-step[data-step="' + prevStep + '"]').addClass('wsg-wizard-step--active');
	}

	/* ───────────────────────────────────────────
	 * Event: Wizard Finish button
	 * ─────────────────────────────────────────── */

	function handleWizardFinish(e) {
		e.preventDefault();
		// Collapse the accordion to signal completion.
		$('.wsg-logo-section').addClass('wsg-logo-section--collapsed');
		updateTotals();
	}

	/* ───────────────────────────────────────────
	 * Event: No-logo checkbox toggle
	 * ─────────────────────────────────────────── */

	function handleNoLogoToggle() {
		state.logo.noLogo = $(this).is(':checked');
		if (state.logo.noLogo) {
			$('.wsg-logo-dropzone').hide();
			$('.wsg-logo-preview').hide();
		} else {
			if (!state.logo.attachmentId) {
				$('.wsg-logo-dropzone').show();
			} else {
				$('.wsg-logo-preview').show();
			}
		}
		updateTotals();
	}

	/* ───────────────────────────────────────────
	 * Event: Notes textarea input
	 * ─────────────────────────────────────────── */

	function handleNotesChange() {
		state.logo.notes = $(this).val();
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

		// Build AJAX data.
		var ajaxData = {
			security:   wsgData.nonce,
			product_id: wsgData.productId,
			mode:       wsgData.mode,
			items:      JSON.stringify(items)
		};

		// Append logo data if positions are selected.
		if (state.logo.positions.length > 0 && state.logo.method) {
			ajaxData.logo_positions = JSON.stringify(state.logo.positions);
			ajaxData.logo_method    = state.logo.method;
			if (state.logo.attachmentId) {
				ajaxData.logo_attachment_id = state.logo.attachmentId;
			}
			if (state.logo.notes) {
				ajaxData.logo_notes = state.logo.notes;
			}
		}

		$.ajax({
			url:    wsgData.ajaxUrl,
			method: 'POST',
			data:   ajaxData,
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

					// Reset logo state.
					if (wsgData.logoEnabled) {
						state.logo = {
							attachmentId: null,
							url:          null,
							positions:    [],
							method:       'print',
							notes:        '',
							noLogo:       false
						};
						// Reset wizard to step 1.
						$('.wsg-wizard-pane').hide();
						$('.wsg-wizard-pane[data-pane="1"]').show();
						$('.wsg-wizard-step').removeClass('wsg-wizard-step--active wsg-wizard-step--done');
						$('.wsg-wizard-step[data-step="1"]').addClass('wsg-wizard-step--active');
						$('.wsg-logo-pos-card').removeClass('wsg-logo-pos-card--active');
						$('.wsg-wizard-pane[data-pane="1"] .wsg-wizard-continue').prop('disabled', true);
						$('.wsg-logo-preview').hide();
						$('.wsg-logo-dropzone').show();
						$('.wsg-logo-no-logo-input').prop('checked', false);
						$('.wsg-logo-notes').val('');
						$('.wsg-logo-method-card').removeClass('wsg-logo-method-card--active');
						$('.wsg-logo-method-card[data-method="print"]').addClass('wsg-logo-method-card--active');
					}

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

		// Logo section (between swatches and grids).
		var $logoSection = renderLogoSection();
		if ($logoSection) {
			$root.append($logoSection);
		}

		$root.append('<div class="wsg-grids-container"></div>');
		$root.append(renderTotals());
		$root.append(renderButton());

		// Bind events via delegation on #wsg-root.
		$root.on('click', '.wsg-swatch', handleSwatchClick);
		$root.on('click', '.wsg-pill-remove', handlePillRemove);
		$root.on('input change', '.wsg-qty-input', handleQtyChange);
		$root.on('click', '.wsg-add-to-cart', handleAddToCart);

		// Logo events.
		$root.on('click', '.wsg-logo-header', handleLogoToggle);
		$root.on('click', '.wsg-logo-dropzone', handleDropzoneClick);
		$root.on('dragenter dragover', '.wsg-logo-dropzone', handleDragOver);
		$root.on('dragleave', '.wsg-logo-dropzone', handleDragLeave);
		$root.on('drop', '.wsg-logo-dropzone', handleDrop);
		$root.on('change', '.wsg-logo-file-input', handleLogoFileChange);
		$root.on('click', '.wsg-logo-change', handleLogoChangeClick);
		$root.on('click', '.wsg-logo-remove', handleLogoRemove);
		// Wizard controls.
		$root.on('click', '.wsg-logo-pos-card', handleLogoPositionCard);
		$root.on('click', '.wsg-logo-method-card', handleLogoMethodCard);
		$root.on('click', '.wsg-wizard-continue', handleWizardContinue);
		$root.on('click', '.wsg-wizard-back', handleWizardBack);
		$root.on('click', '.wsg-wizard-finish', handleWizardFinish);
		$root.on('change', '.wsg-logo-no-logo-input', handleNoLogoToggle);
		$root.on('input', '.wsg-logo-notes', handleNotesChange);

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
