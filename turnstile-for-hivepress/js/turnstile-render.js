/**
 * Turnstile for HivePress — explicit render + submission lifecycle.
 *
 * The hard problem this solves: HivePress shows login/register/reset-password
 * forms inside FancyBox modals. FancyBox opens an inline modal with
 * $.fancybox.open({ src: '#user_login_modal' }), which MOVES the modal's DOM
 * node out of its original (hidden) position into the FancyBox overlay, and
 * moves it back when the modal closes.
 *
 * Rendering a Turnstile widget into a node that is then relocated breaks the
 * widget's cross-origin iframe handshake, producing a storm of
 *   "Failed to execute 'postMessage' ... target origin ... does not match"
 * errors and an unreliable / missing widget.
 *
 * Strategy
 * --------
 *  - PAGE widgets (not inside .hp-modal): render as soon as they are visible.
 *    These are never relocated, so an IntersectionObserver is safe.
 *
 *  - MODAL widgets (inside .hp-modal): render ONLY on FancyBox's afterShow,
 *    i.e. AFTER the node has been moved into the overlay and laid out. They are
 *    never rendered by the observer (which would fire mid-move). On afterClose
 *    we remove the widget so the next open renders a clean one in the relocated
 *    node.
 *
 *  - SUBMIT GATING: block a form's submit until a fresh token is present, and
 *    reset the widget after each submission (tokens are single-use).
 *
 *  - No turnstile.ready() (incompatible with deferred/async api.js); we poll
 *    for window.turnstile instead. No hard jQuery dependency for rendering.
 */

(function () {
	'use strict';

	var RENDER_FLAG = 'tfhpRendered';
	var WIDGET_ID   = 'tfhpWidgetId';
	var GATED_FLAG  = 'tfhpGated';

	/* ---- API readiness ------------------------------------------------- */

	function apiReady() {
		return typeof window.turnstile !== 'undefined' &&
			typeof window.turnstile.render === 'function';
	}

	function whenApiReady(cb) {
		if (apiReady()) { cb(); return; }
		var tries = 0;
		(function poll() {
			if (apiReady()) { cb(); }
			else if (tries++ < 200) { setTimeout(poll, 75); }
		})();
	}

	/* ---- helpers ------------------------------------------------------- */

	function isModalWidget(el) {
		return !!el.closest('.hp-modal');
	}

	function isVisible(el) {
		if (el.offsetParent === null && el.getClientRects().length === 0) {
			return false;
		}
		return el.getBoundingClientRect().width >= 10;
	}

	function readParams(el) {
		var params = { sitekey: el.getAttribute('data-sitekey') || '' };
		var map = {
			'data-theme': 'theme', 'data-language': 'language',
			'data-size': 'size', 'data-appearance': 'appearance',
			'data-retry': 'retry', 'data-retry-interval': 'retry-interval',
			'data-refresh-expired': 'refresh-expired',
			'data-refresh-timeout': 'refresh-timeout'
		};
		Object.keys(map).forEach(function (attr) {
			var v = el.getAttribute(attr);
			if (v !== null && v !== '') { params[map[attr]] = v; }
		});

		var cbName = el.getAttribute('data-callback');
		params.callback = function (token) {
			if (cbName && typeof window[cbName] === 'function') {
				try { window[cbName](token); } catch (e) {}
			}
		};
		var errName = el.getAttribute('data-error-callback');
		if (errName && typeof window[errName] === 'function') {
			params['error-callback'] = window[errName];
		}
		return params;
	}

	/* ---- render / reset / remove --------------------------------------- */

	function renderWidget(el) {
		if (!apiReady() || el.dataset[RENDER_FLAG] === '1') { return; }
		if (el.querySelector('iframe') || el.innerHTML.trim() !== '') {
			el.dataset[RENDER_FLAG] = '1';
			return;
		}
		if (!isVisible(el)) { return; }

		try {
			var id = window.turnstile.render(el, readParams(el));
			if (id !== undefined && id !== null) {
				el.dataset[RENDER_FLAG] = '1';
				el.dataset[WIDGET_ID] = id;
			}
		} catch (e) {
			el.dataset[RENDER_FLAG] = '1';
		}
	}

	function resetWidget(el) {
		if (!apiReady() || el.dataset[RENDER_FLAG] !== '1') { return; }
		try {
			if (typeof el.dataset[WIDGET_ID] !== 'undefined') {
				window.turnstile.reset(el.dataset[WIDGET_ID]);
			} else {
				window.turnstile.reset(el);
			}
		} catch (e) {}
	}

	function removeWidget(el) {
		if (!apiReady()) { return; }
		try {
			if (typeof el.dataset[WIDGET_ID] !== 'undefined') {
				window.turnstile.remove(el.dataset[WIDGET_ID]);
			}
		} catch (e) {}
		delete el.dataset[RENDER_FLAG];
		delete el.dataset[WIDGET_ID];
		el.innerHTML = '';
	}

	/* ---- page (non-modal) widgets -------------------------------------- */

	var io = null;
	function observePageWidget(el) {
		if (!('IntersectionObserver' in window)) {
			renderWidget(el);
			setTimeout(function () { renderWidget(el); }, 300);
			return;
		}
		if (!io) {
			io = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) { renderWidget(entry.target); }
				});
			}, { threshold: 0.01 });
		}
		io.observe(el);
	}

	function scanPageWidgets() {
		var widgets = document.querySelectorAll('.tfhp-turnstile');
		for (var i = 0; i < widgets.length; i++) {
			var el = widgets[i];
			if (isModalWidget(el)) { continue; } // modal widgets handled on afterShow
			if (el.dataset[RENDER_FLAG] !== '1') {
				observePageWidget(el);
				renderWidget(el);
			}
			gateForm(el.closest('form'));
		}
	}

	/* ---- submit gating ------------------------------------------------- */

	function hasToken(form) {
		var input = form.querySelector('input[name="cf-turnstile-response"]');
		return !!(input && input.value && input.value.length > 0);
	}

	function gateForm(form) {
		if (!form || form.dataset[GATED_FLAG] === '1') { return; }
		form.dataset[GATED_FLAG] = '1';

		form.addEventListener('submit', function (e) {
			if (!form.querySelector('.tfhp-turnstile')) { return; }
			if (!hasToken(form)) {
				e.preventDefault();
				e.stopImmediatePropagation();
				// Nudge the widget for this form to render.
				var w = form.querySelector('.tfhp-turnstile');
				if (w) { renderWidget(w); }
				var btn = form.querySelector('button[type="submit"], input[type="submit"]');
				if (btn) { btn.disabled = false; btn.removeAttribute('data-state'); }
			}
		}, true);
	}

	/* ---- modal widgets (FancyBox) --------------------------------------
	 *
	 * HivePress switches between login / register / reset-password by calling
	 * $.fancybox.close() then $.fancybox.open() in quick succession. In that
	 * race FancyBox's afterClose for the outgoing modal can fire late or be
	 * skipped, so we must NOT depend on afterClose having cleaned up.
	 *
	 * Instead, every afterShow is self-healing: we force-tear-down any existing
	 * (and now stale/relocated) widget in the modal being shown, then render a
	 * completely fresh one. This guarantees a working widget on every open,
	 * including switching away and back to the same modal.
	 * ------------------------------------------------------------------- */

	function forceReset(el) {
		// Remove any existing widget instance and clear all of our state so the
		// element is treated as brand new.
		if (apiReady() && typeof el.dataset[WIDGET_ID] !== 'undefined') {
			try { window.turnstile.remove(el.dataset[WIDGET_ID]); } catch (e) {}
		}
		delete el.dataset[RENDER_FLAG];
		delete el.dataset[WIDGET_ID];
		el.innerHTML = '';
	}

	function renderOpenModal($content) {
		if (!$content) { return; }

		// Force-reset every widget in the modal being shown ONCE, up front.
		// This clears any stale state left over from a previous open that was
		// closed via the close()+open() switch race (where afterClose may not
		// have fired). After this, each widget is guaranteed to render fresh.
		$content.find('.tfhp-turnstile').each(function () {
			forceReset(this);
		});

		// Then render, retrying across the open animation until the element has
		// layout. renderWidget is idempotent (RENDER_FLAG), so the retries don't
		// double-render.
		var attempts = 0;
		(function tryRender() {
			whenApiReady(function () {
				$content.find('.tfhp-turnstile').each(function () {
					renderWidget(this);
					gateForm(this.closest ? this.closest('form') : null);
				});
			});
			if (attempts++ < 12) { setTimeout(tryRender, 100); }
		})();
	}

	/* ---- jQuery-dependent features ------------------------------------- */

	function bindJqueryFeatures() {
		var $ = window.jQuery;

		$(document).on('afterShow.fb', function (e, instance, slide) {
			renderOpenModal(slide && slide.$content ? slide.$content : null);
		});

		// On close FancyBox moves the modal node back to its origin; remove the
		// widget so the next open renders a fresh one in the relocated node.
		$(document).on('beforeClose.fb afterClose.fb', function (e, instance, slide) {
			if (slide && slide.$content) {
				slide.$content.find('.tfhp-turnstile').each(function () {
					removeWidget(this);
				});
			}
		});

		// Reset widget after each HivePress submission (fresh single-use token).
		$(document).on('ajaxComplete', function (event, xhr, settings) {
			if (settings && settings.url && settings.url.indexOf('/hivepress/v1/') !== -1) {
				var widgets = document.querySelectorAll('.tfhp-turnstile');
				for (var i = 0; i < widgets.length; i++) {
					resetWidget(widgets[i]);
				}
			}
		});
	}

	function whenJqueryReady() {
		if (window.jQuery) { bindJqueryFeatures(); return; }
		var tries = 0;
		(function poll() {
			if (window.jQuery) { bindJqueryFeatures(); }
			else if (tries++ < 300) { setTimeout(poll, 100); }
		})();
	}

	/* ---- boot ---------------------------------------------------------- */

	function init() {
		whenApiReady(scanPageWidgets);

		// Observe for AJAX-injected PAGE forms only. Modal widgets are handled
		// exclusively on afterShow, so we debounce and re-scan page widgets.
		var debounce = null;
		var mo = new MutationObserver(function () {
			if (debounce) { clearTimeout(debounce); }
			debounce = setTimeout(function () {
				whenApiReady(scanPageWidgets);
			}, 200);
		});
		if (document.body) {
			mo.observe(document.body, { childList: true, subtree: true });
		}

		whenJqueryReady();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
