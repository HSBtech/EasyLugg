/**
 * EasyLugg Dashboard front-end behaviour (SRS NFR-01, NFR-02, CRT-07/09).
 * Plain JS, no build step. Every write goes to admin-ajax.php as a wp_ajax_
 * action; the server re-checks everything, this file only helps the user.
 */
(function () {
	'use strict';

	var cfg = window.EasyLuggDashboard || {};
	var root = document.getElementById('eld');
	if (!root) {
		return;
	}
	var msg = root.querySelector('.eld-msg');

	function show(text, isError) {
		if (!msg) {
			return;
		}
		msg.textContent = text;
		msg.hidden = false;
		msg.className = 'eld-msg ' + (isError ? 'is-error' : 'is-ok');
		msg.scrollIntoView({ block: 'nearest' });
	}

	// Shrink phone photos to ~2 MB before upload (MM §7). Falls back to the
	// original file when the browser cannot decode or draw it.
	function shrink(file) {
		return new Promise(function (resolve) {
			if (!file.type || file.type.indexOf('image/') !== 0 || file.size < 1500000) {
				resolve(file);
				return;
			}
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				var max = 1920;
				var scale = Math.min(1, max / Math.max(img.width, img.height));
				var canvas = document.createElement('canvas');
				canvas.width = Math.round(img.width * scale);
				canvas.height = Math.round(img.height * scale);
				canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
				URL.revokeObjectURL(url);
				canvas.toBlob(function (blob) {
					if (!blob) {
						resolve(file);
						return;
					}
					resolve(new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
				}, 'image/jpeg', 0.82);
			};
			img.onerror = function () {
				URL.revokeObjectURL(url);
				resolve(file);
			};
			img.src = url;
		});
	}

	function buildData(form) {
		var data = new FormData(form);
		var jobs = [];
		var inputs = form.querySelectorAll('input[data-eld-photos]');
		Array.prototype.forEach.call(inputs, function (input) {
			var name = input.name;
			data.delete(name);
			Array.prototype.forEach.call(input.files, function (file) {
				jobs.push(shrink(file).then(function (small) {
					data.append(name, small, small.name);
				}));
			});
		});
		return Promise.all(jobs).then(function () {
			return data;
		});
	}

	function submit(form) {
		var confirmText = form.getAttribute('data-confirm');
		if (confirmText && !window.confirm(confirmText)) {
			return;
		}
		var button = form.querySelector('button[type="submit"]');
		if (button) {
			button.disabled = true; // CRT-07: no double submit.
		}
		show(cfg.working || '…', false);
		buildData(form)
			.then(function (data) {
				return fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' });
			})
			.then(function (res) {
				return res.json();
			})
			.then(function (json) {
				if (json && json.success) {
					var d = json.data || {};
					show(d.message || '', false);
					var redirect = d.redirect || form.getAttribute('data-redirect');
					if (redirect) {
						window.location.href = redirect;
					} else if (d.reload !== false) {
						setTimeout(function () {
							window.location.reload();
						}, 500);
					}
					return;
				}
				show((json && json.data && json.data.message) || cfg.error, true);
				if (button) {
					button.disabled = false;
				}
			})
			.catch(function () {
				show(cfg.error, true);
				if (button) {
					button.disabled = false;
				}
			});
	}

	root.addEventListener('submit', function (event) {
		var form = event.target;
		if (form && form.classList && form.classList.contains('eld-form')) {
			event.preventDefault();
			if (form.checkValidity && !form.checkValidity()) {
				form.reportValidity();
				return;
			}
			submit(form);
		}
	});

	// Create form: show only the fields of the chosen route; hidden fields are
	// disabled so same-named fields on other routes are never submitted.
	function applyRoute(select) {
		var route = select.value;
		var form = select.closest('form');
		Array.prototype.forEach.call(form.querySelectorAll('[data-routes]'), function (panel) {
			var on = panel.getAttribute('data-routes').split(' ').indexOf(route) !== -1;
			panel.hidden = !on;
			Array.prototype.forEach.call(panel.querySelectorAll('input,select,textarea'), function (field) {
				field.disabled = !on;
			});
		});
	}
	var routeSelect = root.querySelector('[data-eld-route]');
	if (routeSelect) {
		applyRoute(routeSelect);
		routeSelect.addEventListener('change', function () {
			applyRoute(routeSelect);
		});
	}

	// Source <-> payment: OTA orders are already paid on the OTA, others are not.
	var source = root.querySelector('[data-eld-source]');
	var payment = root.querySelector('[data-eld-payment]');
	var ota = root.querySelector('[data-eld-ota]');
	function applySource() {
		if (!source || !payment) {
			return;
		}
		var isOta = source.value === 'ota';
		Array.prototype.forEach.call(payment.options, function (option) {
			option.disabled = (option.value === 'ota_paid') !== isOta;
		});
		if (payment.options[payment.selectedIndex].disabled) {
			for (var i = 0; i < payment.options.length; i++) {
				if (!payment.options[i].disabled) {
					payment.selectedIndex = i;
					break;
				}
			}
		}
		if (ota) {
			ota.closest('label').hidden = !isOta;
			ota.disabled = !isOta;
			ota.required = isOta;
		}
	}
	if (source) {
		applySource();
		source.addEventListener('change', applySource);
	}

	// Keep the delivery date from sitting before the service date.
	var serviceDate = root.querySelector('input[name="service_date"]');
	var deliveryDate = root.querySelector('input[name="delivery_date"]');
	if (serviceDate && deliveryDate) {
		serviceDate.addEventListener('change', function () {
			deliveryDate.min = serviceDate.value;
			if (!deliveryDate.value || deliveryDate.value < serviceDate.value) {
				deliveryDate.value = serviceDate.value;
			}
		});
	}
})();
