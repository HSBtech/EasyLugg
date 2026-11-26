'use strict';

/* Dashboard 2 — presentation layer for the hosted Dashboard 1 screens
   (Create a booking, Settings). It only adds markup around the existing
   fields (section headings, segmented controls, steppers, section index);
   field names, validation and saving stay in booking-dashboard.js. */
(function () {
	var cfg = window.EasyLuggD2Embed || {};
	var L = cfg.labels || {};
	var wrap = document.querySelector('.eld2-embed');
	if (!wrap) { return; }
	var screen = wrap.getAttribute('data-screen');

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text) { n.textContent = text; }
		return n;
	}

	function fire(node) {
		node.dispatchEvent(new Event('input', { bubbles: true }));
		node.dispatchEvent(new Event('change', { bubbles: true }));
	}

	/* A <select> shown as a segmented control; the select stays in the DOM. */
	function segment(select) {
		if (!select || select.getAttribute('data-d2-seg')) { return; }
		select.setAttribute('data-d2-seg', '1');
		var seg = el('div', 'eld2-seg');
		seg.setAttribute('role', 'group');
		var buttons = [];
		Array.prototype.forEach.call(select.options, function (opt) {
			if (opt.value === '') { return; }
			var b = el('button', 'eld2-seg-btn', opt.textContent);
			b.type = 'button';
			b.setAttribute('data-value', opt.value);
			b.addEventListener('click', function () {
				if (select.value !== opt.value) { select.value = opt.value; fire(select); }
				sync();
			});
			buttons.push(b);
			seg.appendChild(b);
		});
		function sync() {
			buttons.forEach(function (b) {
				var opt = Array.prototype.filter.call(select.options, function (o) { return o.value === b.getAttribute('data-value'); })[0];
				b.classList.toggle('is-on', select.value === b.getAttribute('data-value'));
				b.disabled = !!(opt && opt.disabled);
			});
		}
		select.addEventListener('change', sync);
		select.classList.add('eld2-visually-hidden');
		select.parentNode.appendChild(seg);
		sync();
	}

	/* Number input with - / + buttons. */
	function stepper(input) {
		if (input.getAttribute('data-d2-step')) { return; }
		input.setAttribute('data-d2-step', '1');
		var box = el('span', 'eld2-step');
		var minus = el('button', 'eld2-step-btn', '−');
		var plus = el('button', 'eld2-step-btn', '+');
		minus.type = plus.type = 'button';
		minus.setAttribute('aria-label', L.minus || '-');
		plus.setAttribute('aria-label', L.plus || '+');
		function bump(d) {
			var min = input.min !== '' ? parseInt(input.min, 10) : 0;
			var max = input.max !== '' ? parseInt(input.max, 10) : 999;
			var v = (parseInt(input.value, 10) || 0) + d;
			input.value = Math.max(min, Math.min(max, v));
			fire(input);
		}
		minus.addEventListener('click', function () { bump(-1); });
		plus.addEventListener('click', function () { bump(1); });
		input.parentNode.insertBefore(box, input);
		box.appendChild(minus);
		box.appendChild(input);
		box.appendChild(plus);
	}

	function heading(text, before, routes) {
		if (!before) { return; }
		var h = el('h4', 'eld2-sec-title', text);
		if (routes) { h.setAttribute('data-routes', routes); }
		before.parentNode.insertBefore(h, before);
	}

	function initCreate() {
		var form = wrap.querySelector('form[data-action="easylugg_dashboard_create"]');
		if (!form) { return; }
		var title = form.parentNode.querySelector('h3');
		if (title) { title.classList.add('eld2-hide'); }

		segment(form.querySelector('[data-eld-route]'));
		segment(form.querySelector('[data-eld-source]'));
		Array.prototype.forEach.call(form.querySelectorAll('input[name^="bags["]'), stepper);
		var storageBags = form.querySelector('input[name="storage_bags"]');
		if (storageBags) { stepper(storageBags); }

		function grid(selector) {
			var f = form.querySelector(selector);
			return f ? f.closest('.eld-grid') : null;
		}
		heading(L.schedule, grid('[name="service_date"]'), 'AH HA HH ST');
		heading(L.locations, grid('[name="from_hotel_name"]'), 'AH HA HH');
		heading(L.luggage, grid('[name="bags[cabin]"]'), 'AH HA HH ST');
		heading(L.customer, grid('[name="full_name"]'));
		heading(L.details, grid('[name="source"]'));

		var submit = form.querySelector('button[type="submit"]');
		if (submit) {
			var bar = el('div', 'eld2-bar-foot');
			var note = form.querySelector(':scope > p');
			if (note) { bar.appendChild(note); }
			var cancel = el('a', 'eld-btn eld2-cancel', L.cancel);
			cancel.href = cfg.home || '#';
			bar.appendChild(cancel);
			bar.appendChild(submit);
			form.appendChild(bar);
		}
	}

	function initConfig() {
		var cards = wrap.querySelectorAll('section.eld-card');
		if (!cards.length) { return; }
		var nav = el('nav', 'eld2-index');
		nav.setAttribute('aria-label', L.sections || '');
		Array.prototype.forEach.call(cards, function (card, i) {
			var h = card.querySelector('h4, h3');
			if (!h) { return; }
			card.id = card.id || 'eld2-sec-' + i;
			var a = el('a', '', h.textContent);
			a.href = '#' + card.id;
			a.addEventListener('click', function (e) {
				e.preventDefault();
				card.scrollIntoView({ behavior: 'smooth', block: 'start' });
			});
			nav.appendChild(a);
		});
		var host = wrap.querySelector('#eld');
		if (host) { host.insertBefore(nav, host.firstChild); host.classList.add('eld2-config'); }
	}

	if (screen === 'create') { initCreate(); }
	if (screen === 'config') { initConfig(); }
})();
