(function () {
	'use strict';

	var config = window.EasyLuggBooking;
	if (!config) {
		return;
	}

	// Text lookup: reads the same strings as PHP (texts.php via wp_localize_script).
	function t(key) {
		var parts = String(key).split('.');
		var value = config.texts;
		for (var i = 0; i < parts.length; i++) {
			if (value && typeof value === 'object' && parts[i] in value) {
				value = value[parts[i]];
			} else {
				return key;
			}
		}
		return typeof value === 'string' ? value : key;
	}

	document.querySelectorAll('[data-tracking-form]').forEach(function (form) {
		var root = form.closest('[data-easylugg-tracking]');
		if (!root) {
			return;
		}
		var result = root.querySelector('[data-tracking-result]');
		var error = root.querySelector('[data-tracking-error]');
		var submit = form.querySelector('[data-tracking-submit]');
		var input = form.querySelector('[name="booking_code"]');
		var clear = form.querySelector('[data-tracking-clear]');
		var showing = root.querySelector('[data-tracking-showing]');
		var showingText = root.querySelector('[data-tracking-showing-text]');
		var emptyHtml = result.innerHTML;

		input.addEventListener('input', function () {
			clear.hidden = input.value.trim() === '';
		});
		clear.addEventListener('click', function () {
			input.value = '';
			clear.hidden = true;
			error.hidden = true;
			showing.hidden = true;
			result.innerHTML = emptyHtml;
			input.focus();
		});

		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			var code = (input.value || '').trim().toUpperCase();
			if (!code) {
				error.textContent = t('tracking.lookup_not_found');
				error.hidden = false;
				return;
			}
			// Normalise the code the guest typed (lowercase + spaces).
			input.value = code;
			submit.disabled = true;
			submit.textContent = t('tracking.lookup_searching');
			error.hidden = true;
			showing.hidden = true;
			result.innerHTML = '';

			// Server messages are guest-friendly texts; a network or
			// parse failure has no message of its own, so it falls
			// back to the not-found text instead of a technical error.
			var serverMessage = '';
			try {
				const response = await fetch(config.ajaxUrl, {
					method: 'POST',
					body: new FormData(form),
					credentials: 'same-origin'
				});
				const payload = await response.json();
				if (!response.ok || !payload.success) {
					serverMessage = payload.data && payload.data.message ? payload.data.message : '';
					throw new Error(serverMessage || t('tracking.lookup_not_found'));
				}
				result.innerHTML = payload.data.html;
				showingText.textContent = t('tracking.lookup_showing').replace('%s', code);
				showing.hidden = false;
			} catch (lookupError) {
				result.innerHTML = emptyHtml;
				error.textContent = serverMessage || t('tracking.lookup_not_found');
				error.hidden = false;
			} finally {
				submit.disabled = false;
				submit.textContent = t('tracking.lookup_button');
			}
		});
	});
})();
