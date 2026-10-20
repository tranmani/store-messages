/* Store Messages — sign-up popup and forms (no dependencies, works on cached pages). */
(function () {
	'use strict';

	var cfg = window.stmsSignup || {};
	var KEY_DONE = 'stmsSignupDone';
	var KEY_LATER = 'stmsSignupLater';

	function store(key, value) {
		try {
			if (value === undefined) return window.localStorage.getItem(key);
			window.localStorage.setItem(key, value);
		} catch (e) {
			return null;
		}
	}

	function lang() {
		return (document.documentElement.lang || '').slice(0, 2).toLowerCase();
	}

	/* ------------------------------------------------------------ forms -- */

	function initForm(form) {
		var msg = form.querySelector('.stms-signup__msg');
		var button = form.querySelector('button[type=submit]');
		var email = form.querySelector('input[name=email]');

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			msg.textContent = '';
			msg.classList.remove('is-error');
			if (!email.value || !email.checkValidity()) {
				msg.textContent = cfg.email || '';
				msg.classList.add('is-error');
				email.setAttribute('aria-invalid', 'true');
				email.focus();
				return;
			}
			email.removeAttribute('aria-invalid');
			var data = new FormData(form);
			data.append('source', form.dataset.source || 'form');
			data.append('lang', lang());
			button.disabled = true;
			fetch(cfg.rest, { method: 'POST', body: data, credentials: 'omit' })
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					if (!res || !res.ok) throw new Error((res && res.message) || cfg.error);
					store(KEY_DONE, '1');
					var done = document.createElement('p');
					done.className = 'stms-signup__done';
					done.setAttribute('role', 'status');
					done.textContent = res.message;
					form.replaceWith(done);
					done.setAttribute('tabindex', '-1');
					done.focus();
				})
				.catch(function (err) {
					msg.textContent = err.message || cfg.error;
					msg.classList.add('is-error');
					button.disabled = false;
				});
		});
	}

	document.querySelectorAll('[data-stms-signup]').forEach(initForm);

	/* ------------------------------------------------------------ popup -- */

	var pop = document.querySelector('[data-stms-popup]:not(.is-preview)');
	if (!pop) return;
	if (store(KEY_DONE) || Number(store(KEY_LATER) || 0) > Date.now()) return;

	var dialog = pop.querySelector('.stms-pop__dialog');
	var opener = null;
	var shown = false;

	function focusables() {
		return Array.prototype.filter.call(dialog.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]):not([tabindex="-1"]), [tabindex="0"]'), function (el) {
			return el.offsetParent !== null;
		});
	}

	function onKey(e) {
		if (e.key === 'Escape') {
			close();
			return;
		}
		if (e.key !== 'Tab') return;
		var items = focusables();
		if (!items.length) return;
		var first = items[0];
		var last = items[items.length - 1];
		if (e.shiftKey && document.activeElement === first) {
			e.preventDefault();
			last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault();
			first.focus();
		}
	}

	// A cookie banner comes first: never cover it (on phones both sit at the bottom).
	var waitFor = pop.dataset.waitFor || '';
	function bannerUp() {
		if (!waitFor) return false;
		var points = [[0.5, 0.97], [0.5, 0.85], [0.15, 0.95], [0.85, 0.95]];
		for (var i = 0; i < points.length; i++) {
			var el = document.elementFromPoint(window.innerWidth * points[i][0], window.innerHeight * points[i][1]);
			if (el && el.closest(waitFor)) return true;
		}
		return false;
	}

	function open() {
		if (shown) return;
		detach();
		if (bannerUp()) {
			timer = setTimeout(open, 1500);
			return;
		}
		shown = true;
		opener = document.activeElement;
		pop.hidden = false;
		requestAnimationFrame(function () {
			pop.classList.add('is-open');
		});
		document.addEventListener('keydown', onKey);
		var first = dialog.querySelector('input:not([tabindex="-1"])');
		setTimeout(function () {
			(first || dialog).focus({ preventScroll: true });
		}, 60);
	}

	function close() {
		var days = Number(pop.dataset.days || 0);
		store(KEY_LATER, String(Date.now() + Math.max(days, 0) * 86400000 + (days ? 0 : 3600000)));
		pop.classList.remove('is-open');
		document.removeEventListener('keydown', onKey);
		setTimeout(function () {
			pop.hidden = true;
		}, 200);
		if (opener && opener.focus) opener.focus({ preventScroll: true });
	}

	pop.addEventListener('click', function (e) {
		if (e.target.closest('[data-stms-close]')) close();
	});

	var delay = Number(pop.dataset.delay || 0);
	var scroll = Number(pop.dataset.scroll || 0);
	var timer = null;

	function onScroll() {
		var max = document.documentElement.scrollHeight - window.innerHeight;
		if (max > 0 && (window.scrollY / max) * 100 >= scroll) open();
	}

	function detach() {
		window.removeEventListener('scroll', onScroll);
		if (timer) clearTimeout(timer);
	}

	if (scroll > 0) window.addEventListener('scroll', onScroll, { passive: true });
	if (delay > 0 || scroll <= 0) timer = setTimeout(open, Math.max(delay, 0) * 1000);
})();
