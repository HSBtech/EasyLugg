'use strict';

/* Dashboard — read-only overview. The server renders every block
   (escaped); this script only sends filters and swaps region HTML. */
(function () {
	var APP = window.EasyLuggApp || {};
	var root = document.getElementById('elapp');
	if (!root) { return; }

	var els = {
		kpi: document.getElementById('elapp-kpi'),
		list: document.getElementById('elapp-list'),
		detail: document.getElementById('elapp-detail'),
		tracking: document.getElementById('elapp-tracking'),
		proof: document.getElementById('elapp-proof'),
		search: document.getElementById('elapp-search')
	};
	var url = new URL(window.location.href);
	var state = { from: APP.today || '', to: APP.today || '', tab: 'all', corridor: '', q: '', page: 1, code: (url.searchParams.get('code') || '').toUpperCase() };
	var failures = 0;
	var timer = null;

	function post(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', APP.nonce);
		Object.keys(data).forEach(function (k) { body.set(k, data[k]); });
		return fetch(APP.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (!j || !j.success) { throw new Error('fail'); } return j.data; });
	}

	function setLive(ok) {
		var live = document.getElementById('elapp-live');
		if (!live) { return; }
		live.textContent = ok ? APP.texts.live : APP.texts.offline;
		live.classList.toggle('is-off', !ok);
	}

	function refresh() {
		return post('easylugg_app_refresh', state).then(function (d) {
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
				p.className = 'elapp-muted elapp-pad';
				p.textContent = APP.texts.error + ' ';
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'elapp-link';
				b.setAttribute('data-app-retry', '1');
				b.textContent = APP.texts.retry;
				p.appendChild(b);
				els.list.appendChild(p);
			}
		});
	}

	function loadDetail(code) {
		state.code = code;
		var rows = els.list.querySelectorAll('.elapp-row');
		Array.prototype.forEach.call(rows, function (r) { r.classList.toggle('is-selected', r.getAttribute('data-app-code') === code); });
		post('easylugg_app_detail', { code: code }).then(function (d) {
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
		var t = e.target.closest('[data-app-tab],[data-app-corridor],[data-app-page],[data-app-code],[data-app-clear],[data-app-retry]');
		if (!t || t.disabled) { return; }
		if (t.hasAttribute('data-app-tab')) { apply({ tab: t.getAttribute('data-app-tab') }); }
		else if (t.hasAttribute('data-app-corridor')) { apply({ corridor: t.getAttribute('data-app-corridor') }); }
		else if (t.hasAttribute('data-app-page')) { apply({ page: parseInt(t.getAttribute('data-app-page'), 10) || 1 }); }
		else if (t.hasAttribute('data-app-clear')) { if (els.search) { els.search.value = ''; } apply({ q: '' }); }
		else if (t.hasAttribute('data-app-retry')) { refresh(); }
		else if (t.hasAttribute('data-app-code')) { loadDetail(t.getAttribute('data-app-code')); }
	});
	root.addEventListener('keydown', function (e) {
		var row = e.target.closest ? e.target.closest('[data-app-code]') : null;
		if (row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); loadDetail(row.getAttribute('data-app-code')); }
	});
	root.addEventListener('change', function (e) {
		var re = /^\d{4}-\d{2}-\d{2}$/;
		if (!re.test(e.target.value)) { return; }
		if (e.target.hasAttribute('data-app-from')) { apply({ from: e.target.value, to: state.to < e.target.value ? e.target.value : state.to }); }
		else if (e.target.hasAttribute('data-app-to')) { apply({ to: e.target.value, from: state.from > e.target.value ? e.target.value : state.from }); }
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
