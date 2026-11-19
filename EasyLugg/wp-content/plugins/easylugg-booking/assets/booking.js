(function () {
	'use strict';

	const config = window.EasyLuggBooking;
	if (!config) {
		return;
	}

	const money = new Intl.NumberFormat('en-US');

	// Text lookup: reads the same strings as PHP (texts.php via wp_localize_script).
	// t('section.key', arg1, arg2) replaces %s / %1$s / %2$s / %d placeholders in order.
	// Falls back to the dotted key itself when the string is missing, so a typo
	// shows a visible key instead of silently rendering nothing.
	function t(path) {
		const args = Array.prototype.slice.call(arguments, 1);
		let node = config.texts || {};
		path.split('.').forEach(function (part) {
			node = node && node[part];
		});
		let str = (typeof node === 'string') ? node : path;
		let i = 0;
		return str.replace(/%(\d+\$)?[sd]/g, function (match, num) {
			if (num) {
				const v = args[parseInt(num, 10) - 1];
				return (v === undefined || v === null) ? match : String(v);
			}
			const v = args[i++];
			return (v === undefined || v === null) ? match : String(v);
		});
	}

	// Bag display name from texts.php (step1.bag_<size>_name).
	function bagLabel(size) {
		return t('step1.bag_' + size + '_name');
	}

	// Route display name from texts.php (summary.route_ah / route_ha / route_hh).
	function routeLabel(route) {
		return t('summary.route_' + String(route || '').toLowerCase());
	}

	function newRequestKey() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}

		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (char) {
			const random = Math.random() * 16 | 0;
			return (char === 'x' ? random : (random & 0x3 | 0x8)).toString(16);
		});
	}

	function initBooking(root) {
		const form = root.querySelector('.el-booking-form');
		if (!form) {
			return;
		}

		const steps = Array.from(root.querySelectorAll('.el-booking-step'));
		const progress = Array.from(root.querySelectorAll('[data-progress-step]'));
		const result = root.querySelector('[data-result]');
		const requestKeyInput = form.querySelector('[name="request_key"]');
		const requestKeyStorage = 'easylugg-booking-request-key';
		try {
			requestKeyInput.value = sessionStorage.getItem(requestKeyStorage) || newRequestKey();
			sessionStorage.setItem(requestKeyStorage, requestKeyInput.value);
		} catch (error) {
			requestKeyInput.value = newRequestKey();
		}

		function currentRoute() {
			const selected = form.querySelector('[name="corridor"]:checked');
			return selected ? selected.value : 'AH';
		}

		function activeRoutePanel() {
			return form.querySelector('[data-route-fields="' + currentRoute() + '"]');
		}

		function isOnRequest() {
			const panel = activeRoutePanel();
			return panel && Array.from(panel.querySelectorAll('select[name$="_reception"]'))
				.some(function (select) { return select.value === 'no'; });
		}

		function updateRoute() {
			const route = currentRoute();
			root.querySelectorAll('[data-route-fields]').forEach(function (panel) {
				const active = panel.dataset.routeFields === route;
				panel.hidden = !active;
				panel.querySelectorAll('input, select, textarea').forEach(function (field) {
					field.disabled = !active;
				});
			});

			const photo = form.querySelector('[name="luggage_photo"]');
			// The luggage photo is optional on every route.
			photo.required = false;
			root.querySelector('[data-photo-required-label]').textContent = t('step1.photo_optional');
			syncDeliveryDate();
			root.querySelector('[data-photo-help]').textContent = (route === 'HA' || route === 'HH')
				? t('step1.photo_help_hotel')
				: t('step1.photo_help_airport');

			updateOnRequest();
			updatePriceHint();
			updateSummary();
		}

		// Top-level service tabs: Delivery (the existing flow) or Storage
		// (assets/booking-storage.js). The tabs live outside the form
		// (section level, under the heading); the chosen service is
		// carried into the form by a hidden input, so the server and
		// the submit still see it. Switching disables the hidden
		// service's fields, so only the visible service is submitted.
		function currentService() {
			const field = form.querySelector('[name="service"]');
			return field && field.value === 'storage' ? 'storage' : 'delivery';
		}

		function isStorage() {
			return currentService() === 'storage';
		}

		function updateService() {
			const service = currentService();
			root.querySelectorAll('[data-service-panel]').forEach(function (panel) {
				const active = panel.dataset.servicePanel === service;
				panel.hidden = !active;
				panel.querySelectorAll('input, select, textarea').forEach(function (field) {
					field.disabled = !active;
				});
			});
			// The delivery panel holds all three route panels, so the
			// loop above re-enables the hidden routes' required fields
			// too. On a fresh load (AH is the default route, no change
			// event fires) that left HA/HH fields enabled: Step 1
			// validation then failed on HA's empty hotel name and
			// reportValidity() on the hidden field showed nothing, so
			// AH Continue did nothing silently. Re-apply the route
			// disabling here; updateRoute() is also what setService()
			// needs after a Delivery -> Storage -> Delivery switch.
			if (service === 'delivery') {
				updateRoute();
			}
			if (service === 'storage' && window.EasyLuggStorage) {
				window.EasyLuggStorage.syncPickupDate();
				window.EasyLuggStorage.syncTimeOptions();
			}
			updateOnRequest();
			updatePriceHint();
			updateSummary();
		}

		// Called by the tab buttons outside the form. Moves the sliding
		// indicator (via the .is-storage class on the tab bar), marks
		// the active tab, stores the choice in the hidden input, then
		// switches the visible service panel.
		function setService(service) {
			const next = service === 'storage' ? 'storage' : 'delivery';
			const field = form.querySelector('[name="service"]');
			if (field) {
				field.value = next;
			}
			const tabs = root.querySelector('.el-service-tabs');
			if (tabs) {
				tabs.classList.toggle('is-storage', next === 'storage');
				tabs.querySelectorAll('[data-service-tab]').forEach(function (tab) {
					const active = tab.dataset.serviceTab === next;
					tab.classList.toggle('is-active', active);
					tab.setAttribute('aria-selected', active ? 'true' : 'false');
				});
			}
			updateService();
		}

		function updateOnRequest() {
			const request = isOnRequest();
			const note = root.querySelector('[data-request-note]');
			const paymentNote = root.querySelector('[data-request-payment-note]');
			const paymentOptions = root.querySelector('[data-payment-options]');
			const submit = root.querySelector('[data-submit]');
			note.hidden = !request;
			paymentNote.hidden = !request;
			paymentOptions.hidden = request;
			submit.textContent = request ? t('step3.submit_request') : t('step3.submit');
		}

		function bagCounts() {
			return ['cabin', 'medium', 'oversize'].reduce(function (counts, size) {
				const field = form.querySelector('[name="bags[' + size + ']"]');
				counts[size] = field ? Math.max(0, parseInt(field.value, 10) || 0) : 0;
				return counts;
			}, {});
		}

		function estimate() {
			if (isStorage() && window.EasyLuggStorage) {
				return window.EasyLuggStorage.estimate();
			}
			const bags = bagCounts();
			const base = Object.keys(bags).reduce(function (total, size) {
				return total + bags[size] * (parseInt(config.prices[size], 10) || 0);
			}, 0);
			return base + storageCharge();
		}

		function parseDate(value) {
			const parts = String(value || '').split('-');
			if (parts.length !== 3) { return null; }
			const date = new Date(Date.UTC(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10)));
			return isNaN(date.getTime()) ? null : date;
		}

		function dateDiffDays(from, to) {
			const start = parseDate(from);
			const end = parseDate(to);
			if (!start || !end) { return 0; }
			return Math.round((end.getTime() - start.getTime()) / 86400000);
		}

		// Charged overnight-storage nights: the first night is free, so a
		// delivery 1 day after the service date costs nothing extra.
		function storageNights() {
			return Math.max(0, dateDiffDays(value('service_date'), value('delivery_date')) - 1);
		}

		function totalBags() {
			const bags = bagCounts();
			return bags.cabin + bags.medium + bags.oversize;
		}

		function storageCharge() {
			const nights = storageNights();
			if (nights < 1) { return 0; }
			return nights * (parseInt(config.prices.storage, 10) || 0) * totalBags();
		}

		function storageLabel() {
			const nights = storageNights();
			return nights === 1 ? t('summary.storage_row_one') : t('summary.storage_row_many', nights);
		}

		// Delivery date follows the service date until the guest picks
		// another date by hand (tracked per field); it can never be
		// earlier than the service date.
		function syncDeliveryDate() {
			const panel = activeRoutePanel();
			if (!panel) { return; }
			const service = panel.querySelector('[name="service_date"]');
			const delivery = panel.querySelector('[name="delivery_date"]');
			if (!service || !delivery) { return; }
			if (service.value) {
				delivery.min = service.value;
				if (!delivery.dataset.touched || !delivery.value || delivery.value < service.value) {
					delivery.value = service.value;
				}
			}
		}

		function updatePriceHint() {
			if (isStorage() && window.EasyLuggStorage) {
				window.EasyLuggStorage.updatePriceHint();
				return;
			}
			const bags = bagCounts();
			const parts = Object.keys(bags)
				.filter(function (size) { return bags[size] > 0; })
				.map(function (size) {
					return bags[size] + ' ' + bagLabel(size).toLowerCase();
				});
			const hint = root.querySelector('[data-price-hint]');
			hint.textContent = parts.length
				? t('summary.price_hint', money.format(estimate()), parts.join(', '))
				: t('summary.price_hint_empty');
		}

		function value(name) {
			const field = form.querySelector('[name="' + name + '"]:not(:disabled)');
			return field ? field.value.trim() : '';
		}

		// Contact channels (Step 2): each block has a channel picker and only
		// the input that channel needs is shown + enabled. The first block is
		// always visible and required; the second appears via "+ Add another
		// contact" and may be left blank.
		function channelBlocks() {
			return Array.from(root.querySelectorAll('[data-channel-block]'));
		}

		function syncChannelBlock(block) {
			const select = block.querySelector('[data-channel-select]');
			const type = select ? select.value : 'whatsapp';
			// Blocks are 0-based (render_channel_block(0, true) is primary).
			const isPrimary = block.dataset.channelBlock === '0';
			block.querySelectorAll('[data-channel-pane]').forEach(function (pane) {
				const active = pane.dataset.channelPane === type;
				pane.hidden = !active;
				pane.querySelectorAll('input, select').forEach(function (field) {
					field.disabled = !active;
					field.required = active && isPrimary;
				});
			});
		}

		function channelEntries() {
			const entries = [];
			channelBlocks().forEach(function (block) {
				if (block.hidden) {
					return;
				}
				const select = block.querySelector('[data-channel-select]');
				const type = select ? select.value : 'whatsapp';
				const pane = block.querySelector('[data-channel-pane="' + type + '"]');
				if (!pane) {
					return;
				}
				let text = '';
				if (type === 'whatsapp' || type === 'zalo') {
					const code = pane.querySelector('[data-country-code]');
					const phone = pane.querySelector('input[type="tel"]');
					const digits = phone ? phone.value.replace(/[^0-9]/g, '') : '';
					if (digits && code) {
						text = '+' + code.value + digits;
					}
				} else {
					const id = pane.querySelector('input[type="text"]');
					text = id ? id.value.trim() : '';
				}
				if (text) {
					entries.push({ label: t('step2.channel_' + type), value: text });
				}
			});
			return entries;
		}

		function validateChannels() {
			let valid = true;
			channelBlocks().forEach(function (block) {
				if (!valid || block.hidden) {
					return;
				}
				const isPrimary = block.dataset.channelBlock === '0';
				const select = block.querySelector('[data-channel-select]');
				const type = select ? select.value : 'whatsapp';
				const pane = block.querySelector('[data-channel-pane="' + type + '"]');
				if (!pane) {
					return;
				}
				if (type === 'whatsapp' || type === 'zalo') {
					const code = pane.querySelector('[data-country-code]');
					const phone = pane.querySelector('input[type="tel"]');
					const digits = phone ? phone.value.replace(/[^0-9]/g, '') : '';
					const combined = digits && code ? '+' + code.value + digits : '';
					if (!combined) {
						if (isPrimary) {
							phone.setCustomValidity(t('validation.channel_required'));
							phone.reportValidity();
							valid = false;
						} else {
							phone.setCustomValidity('');
						}
					} else if (!/^\+?[0-9][0-9\s().-]{6,28}$/.test(combined)) {
						phone.setCustomValidity(t('validation.phone_client'));
						phone.reportValidity();
						valid = false;
					} else {
						phone.setCustomValidity('');
					}
				} else {
					const id = pane.querySelector('input[type="text"]');
					if (!id.value.trim()) {
						if (isPrimary) {
							id.setCustomValidity(t('validation.channel_required'));
							id.reportValidity();
							valid = false;
						} else {
							id.setCustomValidity('');
						}
					} else {
						id.setCustomValidity('');
					}
				}
			});
			return valid;
		}

		function visibleChannelBlocks() {
			return channelBlocks().filter(function (block) {
				return !block.hidden;
			});
		}

		function updateChannelButtons() {
			const visible = visibleChannelBlocks().length;
			const canAdd = visible < 2;
			const canRemove = visible > 1;
			channelBlocks().forEach(function (block) {
				block.querySelectorAll('[data-add-channel]').forEach(function (button) {
					button.disabled = !canAdd;
				});
				block.querySelectorAll('[data-remove-channel]').forEach(function (button) {
					button.disabled = !canRemove;
				});
			});
		}

		function showChannelBlock(block) {
			block.hidden = false;
			block.querySelectorAll('[data-channel-select]').forEach(function (field) {
				field.disabled = false;
			});
			syncChannelBlock(block);
		}

		function hideChannelBlock(block) {
			block.querySelectorAll('input, select').forEach(function (field) {
				field.disabled = true;
				field.required = false;
				field.setCustomValidity('');
				if (field.tagName === 'INPUT') {
					field.value = '';
				}
			});
			block.hidden = true;
		}

		function copyChannelValues(from, to) {
			const fromSelect = from.querySelector('[data-channel-select]');
			const toSelect = to.querySelector('[data-channel-select]');
			if (!fromSelect || !toSelect) {
				return;
			}
			toSelect.value = fromSelect.value;
			syncChannelBlock(to);
			const type = fromSelect.value;
			const fromPane = from.querySelector('[data-channel-pane="' + type + '"]');
			const toPane = to.querySelector('[data-channel-pane="' + type + '"]');
			if (!fromPane || !toPane) {
				return;
			}
			const fromCode = fromPane.querySelector('[data-country-code]');
			const toCode = toPane.querySelector('[data-country-code]');
			if (fromCode && toCode) {
				toCode.value = fromCode.value;
			}
			const fromPhone = fromPane.querySelector('input[type="tel"]');
			const toPhone = toPane.querySelector('input[type="tel"]');
			if (fromPhone && toPhone) {
				toPhone.value = fromPhone.value;
			}
			const fromId = fromPane.querySelector('input[type="text"]');
			const toId = toPane.querySelector('input[type="text"]');
			if (fromId && toId) {
				toId.value = fromId.value;
			}
		}

		function addChannelBlock() {
			const second = root.querySelector('[data-channel-block="1"]');
			if (!second || !second.hidden) {
				return;
			}
			showChannelBlock(second);
			updateChannelButtons();
			updateSummary();
		}

		function removeChannelBlock(block) {
			if (visibleChannelBlocks().length <= 1) {
				return;
			}
			if (block.dataset.channelBlock === '0') {
				// Removing the primary shifts the second block's values
				// into it, so channels[0] stays the filled one.
				const second = root.querySelector('[data-channel-block="1"]');
				if (second) {
					copyChannelValues(second, block);
					hideChannelBlock(second);
				}
			} else {
				hideChannelBlock(block);
			}
			updateChannelButtons();
			updateSummary();
		}

		function initChannels() {
			channelBlocks().forEach(function (block) {
				const select = block.querySelector('[data-channel-select]');
				if (select) {
					select.addEventListener('change', function () {
						syncChannelBlock(block);
						updateSummary();
					});
					// Only sync visible blocks: a hidden block's inputs must
					// stay disabled until the block is shown, otherwise its
					// required empty input silently blocks Step 2 validation.
					if (!block.hidden) {
						syncChannelBlock(block);
					}
				}
			});
			channelBlocks().forEach(function (block) {
				block.querySelectorAll('[data-add-channel]').forEach(function (button) {
					button.addEventListener('click', addChannelBlock);
				});
				block.querySelectorAll('[data-remove-channel]').forEach(function (button) {
					button.addEventListener('click', function () {
						removeChannelBlock(block);
					});
				});
			});
			updateChannelButtons();
		}

		function initHotelSuggestions() {
			const hotels = Array.isArray(config.hotels) ? config.hotels : [];
			if (!hotels.length) {
				return;
			}

			function normalize(text) {
				// NFD strips accents (a -> a) but NOT d-stroke: map d explicitly.
				return (text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/đ/g, 'd');
			}

			function matchesFor(query) {
				// Token match: every word in the query must appear somewhere
				// in the hotel name or address, in any order. This makes
				// address search work ("Bach Dang Da Nang", "Cua Dai Hoi An")
				// even when the words are not next to each other.
				const words = normalize(query).split(/\s+/).filter(Boolean);
				if (!words.length) {
					return [];
				}
				return hotels.filter(function (hotel) {
					const haystack = normalize(hotel.displayName.text + ' ' + hotel.formattedAddress);
					return words.every(function (word) { return haystack.includes(word); });
				}).slice(0, 8);
			}

			form.querySelectorAll('[name$="_hotel_name"]').forEach(function (input) {
				const wrapper = input.closest('.el-hotel-suggest');
				const list = wrapper ? wrapper.querySelector('.el-hotel-suggestions') : null;
				if (!wrapper || !list) {
					return;
				}
				let currentMatches = [];
				let activeIndex = -1;

				function close() {
					list.hidden = true;
					list.replaceChildren();
					currentMatches = [];
					activeIndex = -1;
				}

				function fill(hotel) {
					const fieldset = input.closest('.el-hotel-fields');
					const name = hotel.displayName && hotel.displayName.text ? hotel.displayName.text : '';
					const address = hotel.formattedAddress || '';
					input.value = name;
					const addressInput = fieldset && fieldset.querySelector('[name$="_hotel_address"]');
					const lat = fieldset && fieldset.querySelector('[name$="_hotel_lat"]');
					const lng = fieldset && fieldset.querySelector('[name$="_hotel_lng"]');
					if (addressInput) {
						addressInput.value = address;
					}
					// Always set both coordinates (clearing when missing):
					// setting only one leaves a stale half-pair, which
					// blocks Step 1 with no visible message.
					if (lat) {
						lat.value = hotel.location && hotel.location.latitude ? hotel.location.latitude : '';
					}
					if (lng) {
						lng.value = hotel.location && hotel.location.longitude ? hotel.location.longitude : '';
					}
					const selected = fieldset && fieldset.querySelector('.el-hotel-selected');
					const selectedName = selected && selected.querySelector('[data-hotel-selected-name]');
					const selectedAddress = selected && selected.querySelector('[data-hotel-selected-address]');
					const hint = fieldset && fieldset.querySelector('[data-hotel-hint]');
					if (selectedName) {
						selectedName.textContent = name;
					}
					if (selectedAddress) {
						selectedAddress.textContent = address;
					}
					if (selected) {
						selected.hidden = false;
					}
					input.hidden = true;
					if (hint) {
						hint.hidden = true;
					}
					close();
					updateSummary();
				}

				function clearSelection() {
					const fieldset = input.closest('.el-hotel-fields');
					input.value = '';
					const addressInput = fieldset && fieldset.querySelector('[name$="_hotel_address"]');
					const lat = fieldset && fieldset.querySelector('[name$="_hotel_lat"]');
					const lng = fieldset && fieldset.querySelector('[name$="_hotel_lng"]');
					if (addressInput) {
						addressInput.value = '';
					}
					if (lat) {
						lat.value = '';
					}
					if (lng) {
						lng.value = '';
					}
					const selected = fieldset && fieldset.querySelector('.el-hotel-selected');
					const hint = fieldset && fieldset.querySelector('[data-hotel-hint]');
					if (selected) {
						selected.hidden = true;
					}
					input.hidden = false;
					if (hint) {
						hint.hidden = false;
					}
					close();
					input.focus();
					updateSummary();
				}

				function render(matches) {
					currentMatches = matches;
					activeIndex = -1;
					list.replaceChildren();
					if (!matches.length) {
						list.hidden = true;
						return;
					}
					matches.forEach(function (hotel) {
						const item = document.createElement('li');
						item.className = 'el-hotel-suggestion';
						item.setAttribute('role', 'option');
						const name = document.createElement('strong');
						name.textContent = hotel.displayName.text;
						const detail = document.createElement('small');
						detail.textContent = hotel.formattedAddress;
						item.append(name, detail);
						item.addEventListener('mousedown', function (event) {
							event.preventDefault();
							fill(hotel);
						});
						list.appendChild(item);
					});
					list.hidden = false;
				}

				input.addEventListener('input', function () {
					if (input.disabled) {
						close();
						return;
					}
					render(matchesFor(input.value.trim()));
				});
				input.addEventListener('focus', function () {
					if (!input.disabled && input.value.trim()) {
						render(matchesFor(input.value.trim()));
					}
				});
				input.addEventListener('keydown', function (event) {
					if (list.hidden) {
						return;
					}
					const items = Array.from(list.children);
					if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
						event.preventDefault();
						activeIndex = event.key === 'ArrowDown'
							? Math.min(activeIndex + 1, items.length - 1)
							: Math.max(activeIndex - 1, 0);
						items.forEach(function (item, index) {
							item.classList.toggle('is-active', index === activeIndex);
						});
					} else if (event.key === 'Enter' && activeIndex >= 0 && currentMatches[activeIndex]) {
						event.preventDefault();
						fill(currentMatches[activeIndex]);
					} else if (event.key === 'Escape') {
						close();
					}
				});
				input.addEventListener('blur', function () {
					window.setTimeout(close, 150);
				});
				const hotelFieldset = input.closest('.el-hotel-fields');
				const selectedName = hotelFieldset && hotelFieldset.querySelector('[data-hotel-selected-name]');
				if (selectedName) {
					selectedName.addEventListener('click', clearSelection);
				}
			});

			document.addEventListener('click', function (event) {
				if (!event.target.closest('.el-hotel-suggest')) {
					form.querySelectorAll('.el-hotel-suggestions').forEach(function (list) {
						list.hidden = true;
					});
				}
			});
		}

		function setError(stepNumber, message) {
			const target = root.querySelector('[data-step-error="' + stepNumber + '"]');
			target.textContent = message || '';
			target.hidden = !message;
		}

		function validateHotelCoordinates(panel) {
			const hotels = panel.querySelectorAll('.el-hotel-fields');
			for (const hotel of hotels) {
				const latitude = hotel.querySelector('[name$="_hotel_lat"]');
				const longitude = hotel.querySelector('[name$="_hotel_lng"]');
				if (latitude.disabled) {
					continue;
				}
				if ((latitude.value && !longitude.value) || (!latitude.value && longitude.value)) {
					return false;
				}
			}
			return true;
		}

		function validateStep(stepNumber) {
			setError(stepNumber, '');
			const step = root.querySelector('[data-step="' + stepNumber + '"]');
			const fields = Array.from(step.querySelectorAll('input, select, textarea'))
				.filter(function (field) { return !field.disabled && field.type !== 'radio' && field.type !== 'file'; });

			if (stepNumber === 1 && !isStorage() && (currentRoute() === 'AH' || currentRoute() === 'HA')) {
				const flight = activeRoutePanel().querySelector('[name="flight_number"]');
				flight.setCustomValidity(/^[A-Za-z0-9-]{1,7}$/.test(flight.value.trim()) ? '' : t('validation.flight_client'));
			}
			if (stepNumber === 2 && !validateChannels()) {
				return false;
			}

			for (const field of fields) {
				if (!field.checkValidity()) {
					field.reportValidity();
					return false;
				}
			}

			if (stepNumber === 1) {
				if (isStorage() && window.EasyLuggStorage) {
					return window.EasyLuggStorage.validateStep1({ setError: setError });
				}
				const bags = bagCounts();
				const total = bags.cabin + bags.medium + bags.oversize;
				if (total < 1) {
					setError(1, t('validation.bag_min_client'));
					return false;
				}
				const deliveryField = activeRoutePanel().querySelector('[name="delivery_date"]');
				if (deliveryField) {
					const serviceDate = value('service_date');
					const deliveryDate = value('delivery_date');
					if (!deliveryDate || (serviceDate && deliveryDate < serviceDate)) {
						setError(1, t('validation.delivery_date_invalid'));
						deliveryField.focus();
						return false;
					}
				}
				const photo = form.querySelector('[name="luggage_photo"]');
				if (photo.files.length && photo.files[0].size > 3 * 1024 * 1024) {
					setError(1, t('validation.photo_too_large'));
					photo.focus();
					return false;
				}
				if (!validateHotelCoordinates(activeRoutePanel())) {
					setError(1, t('validation.hotel_coords_both'));
					return false;
				}
			}

			if (stepNumber === 3 && !isOnRequest() && !form.querySelector('[name="payment_method"]:checked')) {
				setError(3, t('validation.payment_client'));
				return false;
			}

			return true;
		}

		function showStep(stepNumber, focusHeading) {
			steps.forEach(function (step) {
				step.hidden = parseInt(step.dataset.step, 10) !== stepNumber;
			});
			progress.forEach(function (item) {
				const number = parseInt(item.dataset.progressStep, 10);
				const isComplete = number < stepNumber;
				const isCurrent = number === stepNumber;
				item.classList.toggle('is-current', isCurrent);
				item.classList.toggle('is-complete', isComplete);
				const state = item.querySelector('.el-progress-state');
				if (state) {
					state.textContent = isComplete
						? t('progress.completed')
						: (isCurrent ? t('progress.in_progress') : t('progress.upcoming'));
				}
				const num = item.querySelector('.el-progress-num');
				const check = item.querySelector('.el-progress-check');
				if (num) { num.hidden = isComplete; }
				if (check) { check.hidden = !isComplete; }
			});
			const stepBadge = root.querySelector('[data-summary-step]');
			if (stepBadge) {
				stepBadge.textContent = t('progress.step_of', stepNumber);
			}
			const heading = root.querySelector('[data-step="' + stepNumber + '"] h3')
				|| root.querySelector('#easylugg-booking-title');
			if (heading && focusHeading !== false) {
				heading.setAttribute('tabindex', '-1');
				heading.focus({ preventScroll: true });
			}
			root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}

		function addReviewRow(container, label, content) {
			const row = document.createElement('div');
			row.className = 'el-review-row';
			const labelElement = document.createElement('span');
			labelElement.className = 'el-review-label';
			labelElement.textContent = label;
			const valueElement = document.createElement('span');
			valueElement.className = 'el-review-value';
			valueElement.textContent = content;
			row.append(labelElement, valueElement);
			container.appendChild(row);
		}

		function renderReview() {
			const review = root.querySelector('[data-review]');
			review.replaceChildren();
			const head = document.createElement('div');
			head.className = 'el-review-head';
			const headIcon = document.createElement('span');
			if (config.icons && config.icons['assignment']) {
				headIcon.innerHTML = config.icons['assignment'];
			}
			const headTitle = document.createElement('h3');
			headTitle.textContent = t('review.title');
			const edit = document.createElement('button');
			edit.type = 'button';
			edit.className = 'el-review-edit';
			if (config.icons && config.icons.edit) {
				const editIcon = document.createElement('span');
				editIcon.innerHTML = config.icons.edit;
				edit.appendChild(editIcon);
			}
			edit.appendChild(document.createTextNode(t('review.edit')));
			edit.addEventListener('click', function () { showStep(1); });
			head.append(headIcon, headTitle, edit);
			review.appendChild(head);
			if (isStorage() && window.EasyLuggStorage) {
				window.EasyLuggStorage.renderReviewRows({ review: review, addReviewRow: addReviewRow });
			} else {
				const route = currentRoute();
				const bags = bagCounts();
				const bagSummary = Object.keys(bags)
					.filter(function (size) { return bags[size] > 0; })
					.map(function (size) { return bagLabel(size) + ': ' + bags[size]; })
					.join(', ');
				const routePanel = activeRoutePanel();
				const fromHotel = routePanel.querySelector('[name="from_hotel_name"]:not(:disabled)');
				const toHotel = routePanel.querySelector('[name="to_hotel_name"]:not(:disabled)');
				addReviewRow(review, t('review.route'), route + ' — ' + routeLabel(route));
				addReviewRow(review, t('step1.service_date'), value('service_date'));
				if (value('delivery_date')) {
					addReviewRow(review, route === 'HA' ? t('step1.departure_date') : t('step1.delivery_date'), value('delivery_date'));
				}
				if (fromHotel) {
					addReviewRow(review, t('review.delivery_from'), fromHotel.value.trim() + ' — ' + value('from_hotel_address'));
				}
				if (toHotel) {
					addReviewRow(review, t('review.delivery_to'), toHotel.value.trim() + ' — ' + value('to_hotel_address'));
				}
				if (route === 'AH') {
					addReviewRow(review, t('step1.pickup_time'), value('pickup_time') + t('review.airport_suffix'));
					addReviewRow(review, t('step1.flight_number'), value('flight_number'));
				} else if (route === 'HA') {
					addReviewRow(review, t('step1.flight_number'), value('flight_number'));
					addReviewRow(review, t('step1.delivery_time'), value('airport_delivery_time'));
				}
				addReviewRow(review, t('review.luggage'), bagSummary);
				if (storageCharge() > 0) {
					addReviewRow(review, storageLabel(), t('summary.price_vnd', money.format(storageCharge())));
				}
				if (value('promotion_code')) {
					addReviewRow(review, t('summary.promo_row'), value('promotion_code'));
				}
			}
			const contactParts = [value('full_name'), value('email')]
				.concat(channelEntries().map(function (entry) { return entry.label + ' ' + entry.value; }))
				.filter(function (part) { return part; });
			addReviewRow(review, t('summary.contact_section'), contactParts.join(' — '));
			addReviewRow(review, t('review.estimated_price'), t('summary.price_vnd', money.format(estimate())));
		}

		function addSummaryRow(container, label, content) {
			const row = document.createElement('div');
			row.className = 'el-summary-row';
			const labelElement = document.createElement('span');
			labelElement.className = 'el-summary-label';
			labelElement.textContent = label;
			const valueElement = document.createElement('span');
			valueElement.className = 'el-summary-value';
			valueElement.textContent = content ? content : '—';
			row.append(labelElement, valueElement);
			container.appendChild(row);
		}

		function updateSummary() {
			const summary = root.querySelector('[data-summary]');
			if (!summary) {
				return;
			}
			summary.replaceChildren();
			const route = currentRoute();
			const bags = bagCounts();
			const airport = t('step1.airport_value');
			const icon = function (name) {
				return (config.icons && config.icons[name]) || '';
			};
			const usd = function (vnd) {
				// Approx. USD at the project's sheet rate (25,000 VND/USD).
				return t('summary.usd_line', (Math.round(vnd / 25000 * 100) / 100).toFixed(2));
			};
			const addSection = function (label) {
				const section = document.createElement('div');
				section.className = 'el-summary-section';
				const heading = document.createElement('div');
				heading.className = 'el-summary-group';
				heading.textContent = label;
				section.appendChild(heading);
				summary.appendChild(section);
				return section;
			};
			const addPlace = function (section, iconName, name, subs) {
				const place = document.createElement('div');
				place.className = 'el-summary-place';
				if (icon(iconName)) {
					const iconWrap = document.createElement('span');
					iconWrap.innerHTML = icon(iconName);
					place.appendChild(iconWrap);
				}
				const text = document.createElement('div');
				const nameEl = document.createElement('div');
				nameEl.className = 'el-summary-place-name';
				nameEl.textContent = name || '—';
				text.appendChild(nameEl);
				(subs || []).forEach(function (sub) {
					if (!sub) { return; }
					const subEl = document.createElement('div');
					subEl.className = 'el-summary-sub';
					subEl.textContent = sub;
					text.appendChild(subEl);
				});
				place.appendChild(text);
				section.appendChild(place);
			};
			if (isStorage() && window.EasyLuggStorage) {
				window.EasyLuggStorage.renderSummarySections({
					summary: summary,
					addSection: addSection,
					addPlace: addPlace,
					addSummaryRow: addSummaryRow,
					usd: usd,
					icon: icon
				});
			} else {
				// Service route.
				const routeSection = addSection(t('summary.route_section'));
				const routeRow = document.createElement('div');
				routeRow.className = 'el-summary-row';
				const routeName = document.createElement('span');
				routeName.className = 'el-summary-value';
				routeName.textContent = routeLabel(route);
				const routeTag = document.createElement('span');
				routeTag.className = 'el-summary-label';
				routeTag.textContent = t('summary.route_tag');
				routeTag.style.color = 'var(--el-booking-primary)';
				routeTag.style.fontWeight = '600';
				routeRow.append(routeName, routeTag);
				routeSection.appendChild(routeRow);
				// Pick-up From.
				const pickupSection = addSection(t('summary.pickup_section'));
				if (route === 'AH') {
					addPlace(pickupSection, 'flight-land', airport, [
						value('service_date'),
						value('pickup_time') ? t('summary.pickup_line', value('pickup_time')) : '',
						value('flight_number') ? t('summary.flight_line', value('flight_number')) : ''
					]);
				} else {
					addPlace(pickupSection, 'location-on', value('from_hotel_name'), [
						value('from_hotel_address'),
						value('service_date'),
						value('from_booking_code') ? t('summary.ref_line', value('from_booking_code')) : ''
					]);
				}
				// Delivery To.
				const deliverySection = addSection(t('summary.delivery_section'));
				if (route === 'AH') {
					addPlace(deliverySection, 'location-on', value('to_hotel_name'), [
						value('to_hotel_address'),
						value('delivery_date'),
						value('to_booking_code') ? t('summary.ref_line', value('to_booking_code')) : ''
					]);
				} else if (route === 'HA') {
					addPlace(deliverySection, 'flight-land', airport, [
						value('flight_number') ? t('summary.flight_line', value('flight_number')) : '',
						value('delivery_date'),
						value('airport_delivery_time') ? t('summary.delivery_line', value('airport_delivery_time')) : ''
					]);
				} else {
					addPlace(deliverySection, 'location-on', value('to_hotel_name'), [
						value('to_hotel_address'),
						value('delivery_date'),
						value('to_booking_code') ? t('summary.ref_line', value('to_booking_code')) : ''
					]);
				}
				// Luggage Manifest.
				const luggageSection = addSection(t('summary.luggage_section'));
				let hasBags = false;
				Object.keys(bags).forEach(function (size) {
					if (bags[size] > 0) {
						hasBags = true;
						addSummaryRow(luggageSection, t('summary.bag_line', bags[size], bagLabel(size)), t('summary.price_vnd', money.format(bags[size] * (parseInt(config.prices[size], 10) || 0))));
					}
				});
				if (!hasBags) {
					addSummaryRow(luggageSection, t('review.luggage'), t('summary.no_luggage'));
				}
				if (storageCharge() > 0) {
					addSummaryRow(luggageSection, storageLabel(), t('summary.price_vnd', money.format(storageCharge())));
				}
				// Summary line, NOT the hidden trust badge.
				addSummaryRow(luggageSection, t('summary.seals'), t('summary.seals_value'));
				// No amount: Stitch's $1,000/bag is invented, not approved business data.
				addSummaryRow(luggageSection, t('summary.insurance'), t('summary.insurance_value'));
				if (value('promotion_code')) {
					addSummaryRow(luggageSection, t('summary.promo_row'), value('promotion_code'));
				}
				if (value('note')) {
					addSummaryRow(luggageSection, t('summary.note_row'), value('note'));
				}
			}
			// Contact.
			const contactName = value('full_name');
			const contactEmail = value('email');
			const contactChannels = channelEntries();
			if (contactName || contactEmail || contactChannels.length) {
				const contactSection = addSection(t('summary.contact_section'));
				if (contactName) { addSummaryRow(contactSection, t('summary.contact_name'), contactName); }
				if (contactEmail) { addSummaryRow(contactSection, t('summary.contact_email'), contactEmail); }
				contactChannels.forEach(function (entry) {
					addSummaryRow(contactSection, entry.label, entry.value);
				});
			}
			// Total.
			const total = estimate();
			const totalRow = document.createElement('div');
			totalRow.className = 'el-summary-total';
			const totalLabel = document.createElement('span');
			totalLabel.textContent = t('summary.total_label');
			const totalValue = document.createElement('strong');
			totalValue.textContent = total > 0 ? t('summary.price_vnd', money.format(total)) : '—';
			totalRow.append(totalLabel, totalValue);
			summary.appendChild(totalRow);
			if (total > 0) {
				const usdEl = document.createElement('div');
				usdEl.className = 'el-summary-usd';
				usdEl.textContent = usd(total);
				summary.appendChild(usdEl);
			}
			const taxNote = document.createElement('p');
			taxNote.className = 'el-summary-note';
			taxNote.textContent = t('summary.tax_note');
			summary.appendChild(taxNote);
		}

		function showResult(response) {
			form.hidden = true;
			const summaryPanel = root.querySelector('[data-summary-panel]');
			if (summaryPanel) {
				summaryPanel.hidden = true;
			}
			result.hidden = false;
			result.replaceChildren();
			const isRequested = response.status === 'requested';
			const icon = function (name) {
				return (config.icons && config.icons[name]) || '';
			};
			const card = document.createElement('div');
			card.className = 'el-confirm-card';
			// Success icon.
			const successIcon = document.createElement('div');
			successIcon.className = 'el-confirm-icon';
			if (icon('check-circle')) { successIcon.innerHTML = icon('check-circle'); }
			card.appendChild(successIcon);
			// Heading + subtext.
			const heading = document.createElement('h2');
			heading.textContent = isRequested ? t('confirmation.heading_requested') : t('confirmation.heading_received');
			card.appendChild(heading);
			const message = document.createElement('p');
			message.className = 'el-confirm-sub';
			message.textContent = isRequested
				? t('confirmation.sub_requested')
				: t('confirmation.sub_received');
			card.appendChild(message);
			// Demo notice.
			if (!isRequested) {
				const demo = document.createElement('div');
				demo.className = 'el-confirm-demo';
				if (icon('info')) {
					const infoIcon = document.createElement('span');
					infoIcon.innerHTML = icon('info');
					demo.appendChild(infoIcon);
				}
				const demoText = document.createElement('span');
				demoText.textContent = t('confirmation.demo_notice');
				demo.appendChild(demoText);
				card.appendChild(demo);
			}
			// Details card.
			const details = document.createElement('div');
			details.className = 'el-confirm-details';
			const addDetailRow = function (iconName, label, valueText, right) {
				const row = document.createElement('div');
				row.className = 'el-confirm-row';
				const left = document.createElement('div');
				left.className = 'el-confirm-left';
				if (icon(iconName)) {
					const iconWrap = document.createElement('span');
					iconWrap.innerHTML = icon(iconName);
					left.appendChild(iconWrap);
				}
				const text = document.createElement('div');
				const labelEl = document.createElement('div');
				labelEl.className = 'el-confirm-label';
				labelEl.textContent = label;
				const valueEl = document.createElement('div');
				valueEl.className = 'el-confirm-value';
				valueEl.textContent = valueText;
				text.append(labelEl, valueEl);
				left.appendChild(text);
				row.appendChild(left);
				if (right) { row.appendChild(right); }
				details.appendChild(row);
				return row;
			};
			// Booking reference + copy.
			const copyBtn = document.createElement('button');
			copyBtn.type = 'button';
			copyBtn.className = 'el-confirm-copy';
			if (icon('content-copy')) {
				const copyIcon = document.createElement('span');
				copyIcon.innerHTML = icon('content-copy');
				copyBtn.appendChild(copyIcon);
			}
			copyBtn.appendChild(document.createTextNode(t('confirmation.copy')));
			copyBtn.addEventListener('click', function () {
				const code = response.booking_code || '';
				const done = function () {
					copyBtn.lastChild.textContent = t('confirmation.copied');
					setTimeout(function () { copyBtn.lastChild.textContent = t('confirmation.copy'); }, 2000);
				};
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(code).then(done, done);
				} else {
					const tmp = document.createElement('textarea');
					tmp.value = code;
					document.body.appendChild(tmp);
					tmp.select();
					try { document.execCommand('copy'); } catch (e) { /* clipboard unavailable */ }
					document.body.removeChild(tmp);
					done();
				}
			});
			addDetailRow('qr-code-2', t('confirmation.booking_ref'), response.booking_code || '', copyBtn);
			// Estimated price + USD.
			const priceNum = parseInt(String(response.price || '').replace(/[^\d]/g, ''), 10) || 0;
			const usdBadge = document.createElement('span');
			usdBadge.className = 'el-confirm-badge';
			usdBadge.textContent = priceNum > 0 ? t('confirmation.usd_badge', (Math.round(priceNum / 25000 * 100) / 100).toFixed(2)) : '';
			addDetailRow('payments', t('review.estimated_price'), response.price || '', usdBadge);
			// Service + date.
			const storageDetails = (isStorage() && window.EasyLuggStorage)
				? window.EasyLuggStorage.confirmationDetails()
				: null;
			const route = currentRoute();
			const serviceBadge = document.createElement('span');
			serviceBadge.className = 'el-confirm-badge el-confirm-badge-primary';
			serviceBadge.textContent = storageDetails ? storageDetails.badge : t('summary.route_tag');
			addDetailRow('flight-takeoff', t('confirmation.service_label'), storageDetails ? storageDetails.label : (routeLabel(route) || route), serviceBadge);
			const routeText = storageDetails
				? storageDetails.route
				: (route === 'AH' ? t('confirmation.route_ah') : (route === 'HA' ? t('confirmation.route_ha') : t('confirmation.route_hh')));
			const routeEl = document.createElement('span');
			routeEl.className = 'el-confirm-route';
			if (icon('near-me')) {
				const nearIcon = document.createElement('span');
				nearIcon.innerHTML = icon('near-me');
				routeEl.appendChild(nearIcon);
			}
			routeEl.appendChild(document.createTextNode(routeText));
			addDetailRow('calendar-month', t('confirmation.service_date_label'), storageDetails ? storageDetails.date : (value('service_date') || ''), routeEl);
			card.appendChild(details);
			// Actions.
			const actions = document.createElement('div');
			actions.className = 'el-confirm-actions';
			const track = document.createElement('a');
			track.className = 'el-button el-button-primary';
			track.href = response.tracking_url;
			track.appendChild(document.createTextNode(t('confirmation.open_tracking')));
			if (icon('arrow-forward')) {
				const arrowIcon = document.createElement('span');
				arrowIcon.innerHTML = icon('arrow-forward');
				track.appendChild(arrowIcon);
			}
			const again = document.createElement('button');
			again.type = 'button';
			again.className = 'el-button el-button-secondary';
			again.textContent = t('confirmation.book_another');
			again.addEventListener('click', function () { window.location.reload(); });
			actions.append(track, again);
			card.appendChild(actions);
			// Footnote.
			const footnote = document.createElement('p');
			footnote.className = 'el-confirm-note';
			footnote.textContent = t('confirmation.footnote');
			card.appendChild(footnote);
			result.appendChild(card);

			if (response.notice) {
				const warning = document.createElement('p');
				warning.className = 'el-request-note';
				warning.textContent = response.notice;
				result.appendChild(warning);
			} else if (response.email_sent) {
				const email = document.createElement('p');
				email.className = 'el-confirm-note';
				email.textContent = t('confirmation.email_sent');
				result.appendChild(email);
			}
		}

		form.addEventListener('change', function (event) {
			if (event.target.name === 'corridor') {
				updateRoute();
			} else if (event.target.name.endsWith('_reception')) {
				updateOnRequest();
			} else if (event.target.name === 'service_date') {
				syncDeliveryDate();
			} else if (event.target.name === 'delivery_date') {
				event.target.dataset.touched = '1';
				const service = activeRoutePanel().querySelector('[name="service_date"]');
				if (service && service.value && event.target.value && event.target.value < service.value) {
					event.target.value = service.value;
				}
			}
			updatePriceHint();
			updateSummary();
		});
		form.addEventListener('input', function () {
			updatePriceHint();
			updateSummary();
		});

		function showPhotoReminder(onConfirm) {
			const modal = root.querySelector('[data-photo-modal]');
			if (!modal) {
				onConfirm();
				return;
			}
			const ok = modal.querySelector('[data-photo-modal-ok]');
			modal.hidden = false;
			if (ok) {
				ok.focus();
				ok.addEventListener('click', function onOk() {
					ok.removeEventListener('click', onOk);
					modal.hidden = true;
					onConfirm();
				});
			}
		}

		root.querySelectorAll('[data-next]').forEach(function (button) {
			button.addEventListener('click', function () {
				const activeStep = parseInt(button.closest('[data-step]').dataset.step, 10);
				if (!validateStep(activeStep)) {
					return;
				}
				if (activeStep === 1) {
					// Hotel collections without a photo: remind the guest
					// to upload one on the handover day, then continue.
					const photo = form.querySelector('[name="luggage_photo"]');
					if (!isStorage() && (currentRoute() === 'HA' || currentRoute() === 'HH') && photo && !photo.files.length) {
						showPhotoReminder(function () {
							showStep(2);
						});
					} else {
						showStep(2);
					}
				} else {
					renderReview();
					updateOnRequest();
					showStep(3);
				}
			});
		});

		root.querySelectorAll('[data-back]').forEach(function (button) {
			button.addEventListener('click', function () {
				showStep(parseInt(button.closest('[data-step]').dataset.step, 10) - 1);
			});
		});

		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			if (!validateStep(3)) {
				return;
			}

			const submit = form.querySelector('[data-submit]');
			const submitOriginal = submit.innerHTML;
			const error = root.querySelector('[data-step-error="3"]');
			submit.disabled = true;
			submit.textContent = t('step3.submitting');
			error.hidden = true;

			try {
				const response = await fetch(config.ajaxUrl, {
					method: 'POST',
					body: new FormData(form),
					credentials: 'same-origin'
				});
				const payload = await response.json();
				if (!response.ok || !payload.success) {
					throw new Error(payload.data && payload.data.message ? payload.data.message : t('validation.submit_error'));
				}
				try {
					sessionStorage.removeItem(requestKeyStorage);
				} catch (storageError) {
					// The booking has already been saved; storage is optional.
				}
				showResult(payload.data);
			} catch (submissionError) {
				error.textContent = submissionError.message || t('validation.submit_offline');
				error.hidden = false;
			} finally {
				submit.disabled = false;
				submit.innerHTML = submitOriginal;
				updateOnRequest();
			}
		});

		// Bag steppers (+/- buttons around the number input).
		root.querySelectorAll('[data-bag-step]').forEach(function (button) {
			button.addEventListener('click', function () {
				const parts = button.dataset.bagStep.split('|');
				const size = parts[0];
				const delta = parseInt(parts[1], 10) || 0;
				const input = form.querySelector('[name="bags[' + size + ']"]');
				if (!input) { return; }
				const min = parseInt(input.min, 10) || 0;
				const max = parseInt(input.max, 10) || 20;
				const next = Math.min(max, Math.max(min, (parseInt(input.value, 10) || 0) + delta));
				input.value = String(next);
				input.dispatchEvent(new Event('input', { bubbles: true }));
			});
		});
		// Payment card selected state (CSS cannot select a parent from :checked).
		const syncPaymentSelected = function () {
			root.querySelectorAll('.el-payment-choice').forEach(function (choice) {
				const radio = choice.querySelector('input[type="radio"]');
				choice.classList.toggle('is-selected', !!(radio && radio.checked));
			});
		};
		form.addEventListener('change', function (event) {
			if (event.target.name === 'payment_method') {
				syncPaymentSelected();
			}
		});
		syncPaymentSelected();
		// Photo dropzone: show the chosen file name.
		const photoInput = form.querySelector('[name="luggage_photo"]');
		const photoSub = root.querySelector('.el-photo-sub');
		const photoSubDefault = photoSub ? photoSub.textContent : '';
		if (photoInput) {
			photoInput.addEventListener('change', function () {
				if (photoSub) {
					photoSub.textContent = (photoInput.files && photoInput.files[0])
						? photoInput.files[0].name
						: photoSubDefault;
				}
			});
		}

		initHotelSuggestions();
		initChannels();
		root.querySelectorAll('[data-service-tab]').forEach(function (tab) {
			tab.addEventListener('click', function () {
				setService(tab.dataset.serviceTab);
			});
		});
		updateRoute();
		// Shared helpers for the Storage module (assets/booking-storage.js).
		window.EasyLuggBookingApp = {
			t: t,
			value: value,
			parseDate: parseDate,
			dateDiffDays: dateDiffDays,
			money: money
		};
		updateService();
		showStep(1, false);
	}

	document.querySelectorAll('[data-easylugg-booking-flow]').forEach(function (root) {
		const main = document.querySelector('#content.site-main');
		if (main && !main.contains(root)) {
			main.appendChild(root);
		}
		initBooking(root);
	});
})();
