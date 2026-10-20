/* Store Messages — admin behaviour (no dependencies). */
(function () {
	'use strict';

	const cfg = window.stmsAdmin || {};
	const t = (key) => (cfg.i18n && cfg.i18n[key]) || key;
	const fmt = (s, ...args) => args.reduce((out, a, i) => out.replace('%' + (i + 1) + '$s', a).replace('%s', a), s);
	const $ = (sel, root = document) => root.querySelector(sel);
	const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

	function post(action, data) {
		const body = data instanceof FormData ? data : new FormData();
		if (!(data instanceof FormData)) {
			Object.entries(data || {}).forEach(([k, v]) => body.append(k, v));
		}
		body.set('action', action);
		body.set('nonce', cfg.nonce);
		return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body })
			.then((r) => r.json())
			.then((json) => {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || t('error'));
				}
				return json.data;
			});
	}

	function debounce(fn, ms) {
		let timer;
		return (...args) => {
			clearTimeout(timer);
			timer = setTimeout(() => fn(...args), ms);
		};
	}

	function status(el, text, kind) {
		if (!el) return;
		el.textContent = text || '';
		el.classList.toggle('is-ok', kind === 'ok');
		el.classList.toggle('is-error', kind === 'error');
	}

	/* ------------------------------------------------ code editors + tokens -- */

	const editors = [];
	let lastField = null;

	function initCodeEditors(onChange) {
		$$('textarea[data-stms-code]').forEach((ta) => {
			if (window.wp && wp.codeEditor && window.stmsCodeEditor) {
				const inst = wp.codeEditor.initialize(ta, window.stmsCodeEditor);
				const cm = inst.codemirror;
				cm.on('change', () => {
					cm.save();
					onChange();
				});
				cm.on('focus', () => (lastField = cm));
				editors.push(cm);
				if (!lastField) lastField = cm;
			} else {
				ta.addEventListener('focus', () => (lastField = ta));
				if (!lastField) lastField = ta;
			}
		});
		$$('.stms-editor input[type=text], .stms-editor textarea:not([data-stms-code])').forEach((el) => el.addEventListener('focus', () => (lastField = el)));
	}

	function insertToken(token) {
		const target = lastField;
		if (!target) return;
		if (target.replaceSelection) {
			target.replaceSelection(token);
			target.focus();
			return;
		}
		const start = target.selectionStart ?? target.value.length;
		const end = target.selectionEnd ?? start;
		target.value = target.value.slice(0, start) + token + target.value.slice(end);
		target.selectionStart = target.selectionEnd = start + token.length;
		target.focus();
		target.dispatchEvent(new Event('input', { bubbles: true }));
	}

	document.addEventListener('click', (e) => {
		const tok = e.target.closest('[data-stms-insert]');
		if (tok) {
			e.preventDefault();
			insertToken(tok.dataset.stmsInsert);
		}
	});

	/* --------------------------------------------------- preview (iframe) -- */

	function showHtml(frame, html) {
		frame.srcdoc = html;
	}

	function initEditor(form) {
		const panel = $('[data-stms-preview]', form);
		if (!panel) return;
		const frame = $('[data-stms-preview-frame]', panel);
		const subject = $('[data-stms-preview-subject]', panel);
		const orderSel = $('[data-stms-preview-order]', panel);
		const tplSel = $('[data-stms-preview-template]', form);
		const kind = form.dataset.stmsEditor;
		const previewAction = form.dataset.stmsPreviewAction || 'stms_preview';
		const testAction = form.dataset.stmsTestAction || 'stms_test';

		const collect = () => {
			editors.forEach((cm) => cm.save());
			const data = new FormData(form);
			data.delete('stms_action');
			data.delete('stms_nonce');
			data.set('order', orderSel ? orderSel.value : '0');
			if (kind === 'layout' && tplSel) data.set('template', tplSel.value);
			return data;
		};

		const refresh = () => {
			panel.classList.add('is-loading');
			post(previewAction, collect())
				.then((res) => {
					subject.textContent = res.subject;
					showHtml(frame, res.html);
				})
				.catch((err) => {
					subject.textContent = err.message;
				})
				.finally(() => panel.classList.remove('is-loading'));
		};
		const later = debounce(refresh, 450);

		initCodeEditors(later);
		form.addEventListener('input', (e) => {
			if (e.target.closest('[data-stms-live]')) later();
			if (e.target.type === 'color') {
				const code = e.target.parentNode.querySelector('code');
				if (code) code.textContent = e.target.value;
			}
		});
		[orderSel, tplSel].forEach((el) => el && el.addEventListener('change', refresh));

		// Campaigns: how many subscribers the chosen audience reaches.
		const countEl = $('[data-stms-audience-count]', form);
		$$('[data-stms-audience]', form).forEach((sel) =>
			sel.addEventListener('change', () => {
				post('stms_audience_count', collect())
					.then((res) => (countEl.textContent = res.message))
					.catch((err) => (countEl.textContent = err.message));
			})
		);
		$$('[data-stms-device]', panel).forEach((r) => r.addEventListener('change', () => panel.classList.toggle('is-mobile', r.value === 'mobile' && r.checked)));

		const testBtn = $('[data-stms-test]', panel);
		if (testBtn) {
			testBtn.addEventListener('click', () => {
				const st = $('[data-stms-test-status]', panel);
				const data = collect();
				data.set('to', $('[data-stms-test-to]', panel).value);
				testBtn.disabled = true;
				status(st, t('sending'));
				post(testAction, data)
					.then((res) => status(st, res.message, 'ok'))
					.catch((err) => status(st, err.message, 'error'))
					.finally(() => (testBtn.disabled = false));
			});
		}
		refresh();
	}

	$$('[data-stms-editor]').forEach(initEditor);

	/* --------------------------------------------------------- small bits -- */

	document.addEventListener('click', (e) => {
		const confirmBtn = e.target.closest('[data-stms-confirm]');
		if (confirmBtn) {
			const kind = confirmBtn.dataset.stmsConfirm;
			const count = $('[data-stms-audience-count]');
			const texts = {
				delete: t('confirmDelete'),
				reset: t('confirmReset'),
				schedule: t('confirmSchedule'),
				cancel: t('confirmCancel'),
				resubscribe: t('confirmResubscribe'),
				campaign: fmt(t('confirmCampaign'), count ? count.textContent.replace(/\.$/, '').toLowerCase() : ''),
			};
			if (!window.confirm(texts[kind] || t('confirmReset'))) e.preventDefault();
		}

		const media = e.target.closest('[data-stms-media]');
		if (media && window.wp && wp.media) {
			e.preventDefault();
			const input = $(media.dataset.stmsMedia);
			const frame = wp.media({ library: { type: 'image' }, multiple: false });
			frame.on('select', () => {
				const att = frame.state().get('selection').first().toJSON();
				input.value = att.url;
				input.dispatchEvent(new Event('input', { bubbles: true }));
			});
			frame.open();
		}

		if (e.target.closest('[data-stms-add-row]')) {
			const wrap = $('[data-stms-rows]');
			const tpl = $('template[data-stms-row-template]');
			const i = Date.now();
			wrap.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, i));
			const rows = $$('[data-stms-row]', wrap);
			$('input', rows[rows.length - 1]).focus();
		}
		const remove = e.target.closest('[data-stms-remove-row]');
		if (remove) {
			remove.closest('[data-stms-row]').remove();
		}

		const check = e.target.closest('[data-stms-check-updates]');
		if (check) {
			const out = $('[data-stms-update-status]');
			check.disabled = true;
			out.textContent = t('checking');
			post('stms_check_updates', {})
				.then((res) => {
					out.textContent = res.message + ' ';
					if (res.update) {
						const a = document.createElement('a');
						a.href = res.update;
						a.className = 'button button-primary';
						a.textContent = 'Update now';
						out.appendChild(a);
					}
				})
				.catch((err) => (out.textContent = err.message))
				.finally(() => (check.disabled = false));
		}
	});

	/* ------------------------------------------------------ order screen -- */

	function modal(subject, html) {
		const wrap = document.createElement('div');
		wrap.className = 'stms-modal';
		wrap.innerHTML = '<div class="stms-modal__dialog" role="dialog" aria-modal="true"><div class="stms-modal__head"><p><span></span><strong></strong></p><button type="button" class="button">' + t('close') + '</button></div><iframe title="' + t('preview') + '" sandbox="allow-same-origin"></iframe></div>';
		$('span', wrap).textContent = t('subject');
		$('strong', wrap).textContent = subject;
		document.body.appendChild(wrap);
		showHtml($('iframe', wrap), html);
		const close = () => {
			wrap.remove();
			document.removeEventListener('keydown', onKey);
		};
		const onKey = (e) => e.key === 'Escape' && close();
		document.addEventListener('keydown', onKey);
		wrap.addEventListener('click', (e) => (e.target === wrap || e.target.closest('.button')) && close());
		$('.button', wrap).focus();
	}

	function initBox(box) {
		const tplSel = $('[data-stms-box-template]', box);
		const trackWrap = $('[data-stms-box-tracking]', box);
		const trackInput = $('[data-stms-box-tracking-input]', box);
		const carrierSel = $('[data-stms-box-carrier]', box);
		const carrierRow = $('[data-stms-box-carrier-row]', box);
		const msgRow = $('[data-stms-box-message-row]', box);
		const complete = $('[data-stms-box-complete]', box);
		const st = $('[data-stms-box-status]', box);

		const hostOf = (u) => {
			try {
				return new URL(u.replace('{number}', 'X')).host.replace(/^www\./, '');
			} catch (e) {
				return '';
			}
		};

		// A pasted link picks its carrier; the carrier list is only needed for bare numbers.
		const syncTracking = () => {
			const v = trackInput.value.trim();
			const isUrl = /^https?:\/\//i.test(v);
			carrierRow.hidden = isUrl;
			if (isUrl) {
				const host = hostOf(v);
				const match = Array.from(carrierSel.options).find((o) => hostOf(o.dataset.url) === host);
				if (match) carrierSel.value = match.value;
			}
		};

		const syncTemplate = () => {
			const opt = tplSel.selectedOptions[0];
			trackWrap.hidden = opt.dataset.tracking !== '1';
			msgRow.hidden = opt.dataset.message !== '1';
			if (complete) complete.checked = opt.dataset.complete === '1';
			status(st, '');
		};

		const data = () => ({
			order: box.dataset.order,
			template: tplSel.value,
			tracking: trackInput.value,
			carrier: carrierSel.value,
			message: $('[data-stms-box-message]', box).value,
			to: $('[data-stms-box-to]', box).value,
			complete: complete && complete.checked ? 1 : '',
		});

		tplSel.addEventListener('change', syncTemplate);
		trackInput.addEventListener('input', syncTracking);
		syncTemplate();
		syncTracking();

		// Enter inside the box must not submit (and save) the whole order form.
		box.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') e.preventDefault();
		});

		$('[data-stms-box-preview]', box).addEventListener('click', (e) => {
			const btn = e.currentTarget;
			btn.disabled = true;
			const d = data();
			if (trackWrap.hidden) delete d.tracking;
			post('stms_preview', d)
				.then((res) => modal(res.subject, res.html))
				.catch((err) => status(st, err.message, 'error'))
				.finally(() => (btn.disabled = false));
		});

		$('[data-stms-box-send]', box).addEventListener('click', (e) => {
			const btn = e.currentTarget;
			const d = data();
			if (!window.confirm(fmt(t('confirmSend'), tplSel.selectedOptions[0].textContent.trim(), d.to))) return;
			btn.disabled = true;
			status(st, t('sending'));
			post('stms_send', d)
				.then((res) => {
					status(st, res.reload ? t('reloading') : res.message, 'ok');
					$('[data-stms-box-history]', box).outerHTML = res.history;
					$('[data-stms-box-message]', box).value = '';
					if (res.reload) setTimeout(() => window.location.reload(), 900);
				})
				.catch((err) => status(st, err.message, 'error'))
				.finally(() => (btn.disabled = false));
		});
	}

	$$('[data-stms-box]').forEach(initBox);
})();
