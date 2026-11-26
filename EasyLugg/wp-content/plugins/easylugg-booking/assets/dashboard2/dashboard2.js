'use strict';

/* Dashboard 2 — read-only (Phase 1A). The server renders every block
   (escaped); this script only sends filters and swaps region HTML. */
(function () {
	var D2 = window.EasyLuggD2 || {};
	var root = document.getElementById('eld2');
	if (!root) { return; }

	var els = {
		kpi: document.getElementById('eld2-kpi'),
		list: document.getElementById('eld2-list'),
		detail: document.getElementById('eld2-detail'),
		tracking: document.getElementById('eld2-tracking'),
		proof: document.getElementById('eld2-proof'),
		search: document.getElementById('eld2-search')
	};
	var url = new URL(window.location.href);
	var state = { from: D2.today || '', to: D2.today || '', tab: 'all', corridor: '', q: '', page: 1, code: (url.searchParams.get('code') || '').toUpperCase() };
	var failures = 0;
	var timer = null;

	function post(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', D2.nonce);
		Object.keys(data).forEach(function (k) { body.set(k, data[k]); });
		return fetch(D2.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (!j || !j.success) { throw new Error('fail'); } return j.data; });
	}

	function setLive(ok) {
		var live = document.getElementById('eld2-live');
		if (!live) { return; }
		live.textContent = ok ? D2.texts.live : D2.texts.offline;
		live.classList.toggle('is-off', !ok);
	}

	function refresh() {
		return post('easylugg_d2_refresh', state).then(function (d) {
			els.kpi.innerHTML = d.kpi;
			els.list.innerHTML = d.list;
			state.page = d.page;
			failures = 0;
			setLive(true);
		}).catch(function () {
			failures += 1;
			if (failures >= 2) { setLive(false); }
			if (!els.list.firstChild) {
				els.list.textContent = '';
				var p = document.createElement('p');
				p.className = 'eld2-muted eld2-pad';
				p.textContent = D2.texts.error + ' ';
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'eld2-link';
				b.setAttribute('data-d2-retry', '1');
				b.textContent = D2.texts.retry;
				p.appendChild(b);
				els.list.appendChild(p);
			}
		});
	}

	function loadDetail(code) {
		state.code = code;
		var rows = els.list.querySelectorAll('.eld2-row');
		Array.prototype.forEach.call(rows, function (r) { r.classList.toggle('is-selected', r.getAttribute('data-d2-code') === code); });
		post('easylugg_d2_detail', { code: code }).then(function (d) {
			els.detail.innerHTML = d.detail;
			els.tracking.innerHTML = d.tracking;
			els.proof.innerHTML = d.proof;
			url.searchParams.set('code', code);
			window.history.replaceState(null, '', url.toString());
		}).catch(function () { setLive(false); });
	}

	function apply(change) {
		Object.keys(change).forEach(function (k) { state[k] = change[k]; });
		if (!('page' in change)) { state.page = 1; }
		refresh();
	}

	root.addEventListener('click', function (e) {
		var t = e.target.closest('[data-d2-tab],[data-d2-corridor],[data-d2-page],[data-d2-code],[data-d2-clear],[data-d2-retry]');
		if (!t || t.disabled) { return; }
		if (t.hasAttribute('data-d2-tab')) { apply({ tab: t.getAttribute('data-d2-tab') }); }
		else if (t.hasAttribute('data-d2-corridor')) { apply({ corridor: t.getAttribute('data-d2-corridor') }); }
		else if (t.hasAttribute('data-d2-page')) { apply({ page: parseInt(t.getAttribute('data-d2-page'), 10) || 1 }); }
		else if (t.hasAttribute('data-d2-clear')) { if (els.search) { els.search.value = ''; } apply({ q: '' }); }
		else if (t.hasAttribute('data-d2-retry')) { refresh(); }
		else if (t.hasAttribute('data-d2-code')) { loadDetail(t.getAttribute('data-d2-code')); }
	});
	root.addEventListener('keydown', function (e) {
		var row = e.target.closest ? e.target.closest('[data-d2-code]') : null;
		if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); loadDetail(row.getAttribute('data-d2-code')); }
	});
	root.addEventListener('change', function (e) {
		var re = /^\d{4}-\d{2}-\d{2}$/;
		if (!re.test(e.target.value)) { return; }
		if (e.target.hasAttribute('data-d2-from')) { apply({ from: e.target.value, to: state.to < e.target.value ? e.target.value : state.to }); }
		else if (e.target.hasAttribute('data-d2-to')) { apply({ to: e.target.value, from: state.from > e.target.value ? e.target.value : state.from }); }
	});
	if (els.search) {
		var debounce = null;
		els.search.addEventListener('input', function () {
			clearTimeout(debounce);
			debounce = setTimeout(function () { apply({ q: els.search.value.trim() }); }, 350);
		});
	}

	function schedule() {
		clearInterval(timer);
		timer = setInterval(function () { if (document.visibilityState === 'visible') { refresh(); } }, 60000);
	}

	refresh().then(function () { if (state.code) { loadDetail(state.code); } });
	schedule();
})();
