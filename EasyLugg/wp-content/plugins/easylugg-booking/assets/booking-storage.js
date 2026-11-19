(function () {
	'use strict';

	// Luggage Storage service: the storage-only half of the booking form.
	// Loads after assets/booking.js (declared as a dependency) and talks
	// to it through window.EasyLuggBookingApp (shared text/date helpers)
	// and the window.EasyLuggStorage API below, which booking.js calls at
	// its branch points (estimate, price hint, Step 1 validation, review,
	// summary, confirmation). Delivery never depends on this file.
	const config = window.EasyLuggBooking;
	const app = window.EasyLuggBookingApp;
	if (!config || !app) {
		return;
	}

	function t() {
		return app.t.apply(null, arguments);
	}

	function getRoot() {
		return document.querySelector('[data-easylugg-booking-flow]');
	}

	function getForm() {
		const root = getRoot();
		return root ? root.querySelector('.el-booking-form') : null;
	}

	function isStorage() {
		const form = getForm();
		if (!form) {
			return false;
		}
		// The service tabs live outside the form; the choice is
		// carried by a hidden input (see setService in booking.js).
		const field = form.querySelector('[name="service"]');
		return !!field && field.value === 'storage';
	}

	function storageBags() {
		const form = getForm();
		if (!form) {
			return 0;
		}
		const input = form.querySelector('[name="storage_bags"]');
		return input ? Math.max(0, parseInt(input.value, 10) || 0) : 0;
	}

	// Charged days: calendar-day difference, minimum 1 day. 0 when the
	// dates are not picked yet (the price is then unknown, not 1 day).
	function storageDays() {
		const dropoff = app.value('dropoff_date');
		const pickup = app.value('pickup_date');
		if (!dropoff || !pickup) {
			return 0;
		}
		return Math.max(1, app.dateDiffDays(dropoff, pickup));
	}

	// Bags x days x the configured per-bag daily fee. 0 until bags and
	// both dates are known, so the summary shows a dash, not a guess.
	function estimate() {
		const bags = storageBags();
		const days = storageDays();
		if (bags < 1 || days < 1) {
			return 0;
		}
		return bags * days * (parseInt(config.prices.storage, 10) || 0);
	}

	// Pick-up date follows the drop-off date until the guest picks
	// another date by hand (tracked per field); it can never be
	// earlier than the drop-off date.
	function syncPickupDate() {
		const form = getForm();
		if (!form) {
			return;
		}
		const dropoff = form.querySelector('[name="dropoff_date"]');
		const pickup = form.querySelector('[name="pickup_date"]');
		if (!dropoff || !pickup) {
			return;
		}
		if (dropoff.value) {
			pickup.min = dropoff.value;
			if (!pickup.dataset.touched || !pickup.value || pickup.value < dropoff.value) {
				pickup.value = dropoff.value;
			}
		}
	}

	function updatePriceHint() {
		const root = getRoot();
		if (!root) {
			return;
		}
		const hint = root.querySelector('[data-storage-price-hint]');
		if (!hint) {
			return;
		}
		const total = estimate();
		hint.textContent = total > 0
			? t('storage.price_hint', app.money.format(total))
			: t('storage.price_hint_empty');
	}

	// Same-day time rules, enforced inside the time lists (not only on
	// Continue): on the same day the pick-up list greys out every slot
	// at or before the drop-off time, and the drop-off list greys out
	// every slot at or after the pick-up time. On different days both
	// lists are fully open. A combination that became invalid because
	// the dates changed after the times were picked shows the red
	// warning under Pick-up time (the selected-but-disabled slot
	// stays until the guest picks another one).
	function syncTimeOptions() {
		const form = getForm();
		if (!form) {
			return;
		}
		// Scoped to the Storage panel: pickup_time shares its name with
		// the Delivery AH field, and the panel is disabled (not absent)
		// while Delivery is active.
		const panel = form.querySelector('[data-service-panel="storage"]');
		const dropoffDate = panel ? panel.querySelector('[name="dropoff_date"]') : null;
		const pickupDate = panel ? panel.querySelector('[name="pickup_date"]') : null;
		const dropoffTime = panel ? panel.querySelector('[name="dropoff_time"]') : null;
		const pickupTime = panel ? panel.querySelector('[name="pickup_time"]') : null;
		const root = getRoot();
		const warning = root ? root.querySelector('[data-storage-time-warning]') : null;
		if (!dropoffDate || !pickupDate || !dropoffTime || !pickupTime) {
			return;
		}
		const sameDay = dropoffDate.value && pickupDate.value && dropoffDate.value === pickupDate.value;
		Array.from(pickupTime.options).forEach(function (option) {
			option.disabled = !!sameDay && !!dropoffTime.value && option.value !== '' && option.value <= dropoffTime.value;
		});
		Array.from(dropoffTime.options).forEach(function (option) {
			option.disabled = !!sameDay && !!pickupTime.value && option.value !== '' && option.value >= pickupTime.value;
		});
		const invalid = !!sameDay && !!dropoffTime.value && !!pickupTime.value && pickupTime.value <= dropoffTime.value;
		if (warning) {
			warning.hidden = !invalid;
		}
	}

	// Step 1 checks for Storage. Native validity (required fields, date
	// minimums) already ran in booking.js; here: bags, date order and
	// same-day time order.
	function validateStep1(ctx) {
		const setError = ctx.setError;
		if (storageBags() < 1) {
			setError(1, t('validation.storage_bags_min'));
			return false;
		}
		const dropoffDate = app.value('dropoff_date');
		const pickupDate = app.value('pickup_date');
		if (!pickupDate || (dropoffDate && pickupDate < dropoffDate)) {
			setError(1, t('validation.pickup_date_invalid'));
			const pickupField = getForm().querySelector('[name="pickup_date"]');
			if (pickupField) {
				pickupField.focus();
			}
			return false;
		}
		if (pickupDate === dropoffDate) {
			const dropoffTime = app.value('dropoff_time');
			const pickupTime = app.value('pickup_time');
			if (pickupTime && dropoffTime && pickupTime <= dropoffTime) {
				setError(1, t('validation.pickup_time_before'));
				return false;
			}
		}
		return true;
	}

	// Review rows for Step 3. Contact + total rows are added by
	// booking.js after this, same as for Delivery.
	function renderReviewRows(ctx) {
		const review = ctx.review;
		const addReviewRow = ctx.addReviewRow;
		addReviewRow(review, t('review.route'), t('summary.route_st'));
		addReviewRow(review, t('storage.dropoff_date'), app.value('dropoff_date') + ', ' + app.value('dropoff_time'));
		addReviewRow(review, t('storage.pickup_date'), app.value('pickup_date') + ', ' + app.value('pickup_time'));
		addReviewRow(review, t('review.luggage'), t('storage.bags_days', storageBags(), storageDays()));
		if (app.value('promotion_code')) {
			addReviewRow(review, t('summary.promo_row'), app.value('promotion_code'));
		}
	}

	// Summary sections. Contact + total + tax note are added by
	// booking.js after this, same as for Delivery.
	function renderSummarySections(ctx) {
		const summary = ctx.summary;
		const addSection = ctx.addSection;
		const addPlace = ctx.addPlace;
		const addSummaryRow = ctx.addSummaryRow;
		// Service.
		const routeSection = addSection(t('summary.storage_section'));
		const routeRow = document.createElement('div');
		routeRow.className = 'el-summary-row';
		const routeName = document.createElement('span');
		routeName.className = 'el-summary-value';
		routeName.textContent = t('summary.route_st');
		const routeTag = document.createElement('span');
		routeTag.className = 'el-summary-label';
		routeTag.textContent = t('summary.route_tag_storage');
		routeTag.style.color = 'var(--el-booking-primary)';
		routeTag.style.fontWeight = '600';
		routeRow.append(routeName, routeTag);
		routeSection.appendChild(routeRow);
		// Storage location.
		const placeSection = addSection(t('storage.hub_label'));
		addPlace(placeSection, 'location-on', t('storage.hub_name'), [
			t('storage.hub_address'),
			t('storage.hub_hours')
		]);
		// Drop-off.
		const dropoffSection = addSection(t('summary.dropoff_row'));
		addPlace(dropoffSection, 'schedule', app.value('dropoff_date'), [
			app.value('dropoff_time')
		]);
		// Pick-up.
		const pickupSection = addSection(t('summary.pickup_row'));
		addPlace(pickupSection, 'schedule', app.value('pickup_date'), [
			app.value('pickup_time')
		]);
		// Luggage.
		const luggageSection = addSection(t('summary.luggage_section'));
		const bags = storageBags();
		const days = storageDays();
		const total = estimate();
		if (bags < 1) {
			addSummaryRow(luggageSection, t('review.luggage'), t('summary.no_luggage'));
		} else if (days > 0) {
			addSummaryRow(luggageSection, t('storage.bags_days', bags, days), t('summary.price_vnd', app.money.format(total)));
		} else {
			addSummaryRow(luggageSection, t('summary.storage_bags'), String(bags));
		}
		addSummaryRow(luggageSection, t('summary.seals'), t('summary.seals_value'));
		addSummaryRow(luggageSection, t('summary.insurance'), t('summary.insurance_value'));
		const note = app.value('note');
		if (note) {
			addSummaryRow(luggageSection, t('summary.note_row'), note);
		}
		const promo = app.value('promotion_code');
		if (promo) {
			addSummaryRow(luggageSection, t('summary.promo_row'), promo);
		}
	}

	// Confirmation card details (service label, date, route, badge).
	function confirmationDetails() {
		return {
			label: t('summary.route_st'),
			date: app.value('dropoff_date'),
			route: t('storage.hub_name'),
			badge: t('summary.route_tag_storage')
		};
	}

	function initStorage(root) {
		const form = root.querySelector('.el-booking-form');
		if (!form) {
			return;
		}
		// Bag stepper (+/- around the number input). No upper cap.
		root.querySelectorAll('[data-storage-bag-step]').forEach(function (button) {
			button.addEventListener('click', function () {
				const delta = parseInt(button.dataset.storageBagStep, 10) || 0;
				const input = form.querySelector('[name="storage_bags"]');
				if (!input) {
					return;
				}
				const min = parseInt(input.min, 10) || 0;
				input.value = String(Math.max(min, (parseInt(input.value, 10) || 0) + delta));
				input.dispatchEvent(new Event('input', { bubbles: true }));
			});
		});
		// Drop-off date drives the pick-up date.
		const dropoff = form.querySelector('[name="dropoff_date"]');
		if (dropoff) {
			dropoff.addEventListener('change', function () {
				syncPickupDate();
				syncTimeOptions();
			});
		}
		const pickup = form.querySelector('[name="pickup_date"]');
		if (pickup) {
			pickup.addEventListener('change', function () {
				pickup.dataset.touched = '1';
				const service = form.querySelector('[name="dropoff_date"]');
				if (service && service.value && pickup.value && pickup.value < service.value) {
					pickup.value = service.value;
				}
				syncTimeOptions();
			});
		}
		// Either time list re-checks the other one. Scoped to the
		// Storage panel: at init the panel is disabled (Delivery is
		// active), so an :not(:disabled) lookup would find nothing.
		const storagePanel = form.querySelector('[data-service-panel="storage"]');
		['dropoff_time', 'pickup_time'].forEach(function (name) {
			const select = storagePanel ? storagePanel.querySelector('[name="' + name + '"]') : null;
			if (select) {
				select.addEventListener('change', syncTimeOptions);
			}
		});
		// Storage location: hotel-style picker. One hub for now, so the
		// selected card (name + address, exactly like a picked hotel)
		// is the display; clicking it opens the suggestion list with
		// the single hub, clicking the hub closes it again.
		const hubSelected = form.querySelector('[data-storage-hub-selected]');
		const hubList = form.querySelector('[data-storage-hub-list]');
		if (hubSelected && hubList) {
			hubSelected.addEventListener('click', function () {
				hubList.hidden = !hubList.hidden;
			});
			hubSelected.addEventListener('keydown', function (event) {
				if (event.key === 'Escape') {
					hubList.hidden = true;
				}
			});
			hubList.querySelectorAll('[data-storage-hub-option]').forEach(function (option) {
				option.addEventListener('click', function () {
					hubList.hidden = true;
					hubSelected.focus();
				});
			});
			document.addEventListener('click', function (event) {
				if (!hubList.hidden && !event.target.closest('[data-storage-hub-wrap]')) {
					hubList.hidden = true;
				}
			});
		}
		syncPickupDate();
		syncTimeOptions();
		updatePriceHint();
	}

	document.querySelectorAll('[data-easylugg-booking-flow]').forEach(initStorage);

	window.EasyLuggStorage = {
		isStorage: isStorage,
		estimate: estimate,
		storageDays: storageDays,
		storageBags: storageBags,
		syncPickupDate: syncPickupDate,
		syncTimeOptions: syncTimeOptions,
		updatePriceHint: updatePriceHint,
		validateStep1: validateStep1,
		renderReviewRows: renderReviewRows,
		renderSummarySections: renderSummarySections,
		confirmationDetails: confirmationDetails
	};
})();
