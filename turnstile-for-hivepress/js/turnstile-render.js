/**
 * Turnstile for HivePress: explicit render + submission lifecycle.
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

	// Set once the polling budget (~15s) is spent without api.js appearing,
	// e.g. Cloudflare is unreachable or the script was blocked. The submit
	// gate stands down at that point so forms are not bricked: submissions go
	// through to the server, which remains the authority (it rejects missing
	// tokens unless SCT's failsafe allows them).
	var apiFailed = false;

	function apiReady() {
		return typeof window.turnstile !== 'undefined' &&
			typeof window.turnstile.render === 'function';
	}

	function whenApiReady(cb) {
		if (apiReady()) { cb(); return; }
		var tries = 0;
		(function poll() {
			if (apiReady()) { apiFailed = false; cb(); }
			else if (tries++ < 200) { setTimeout(poll, 75); }
			else { apiFailed = true; }
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
			clearWidgetError(el);
			if (cbName && typeof window[cbName] === 'function') {
				try { window[cbName](token); } catch (e) {}
			}
		};

		// SCT's "failure message" feature: the text arrives on the widget
		// (data-failure-message, plain text) and is shown below it when
		// Turnstile reports an error, cleared again on success.
		var failureMessage = el.getAttribute('data-failure-message');
		if (failureMessage) {
			params['error-callback'] = function () {
				showWidgetError(el, failureMessage);
			};
		}
		return params;
	}

	/* ---- widget-level failure message ----------------------------------- */

	function findWidgetError(el) {
		var next = el.nextElementSibling;
		return (next && next.classList.contains('tfhp-turnstile-error')) ? next : null;
	}

	function showWidgetError(el, message) {
		var note = findWidgetError(el);
		if (!note) {
			note = document.createElement('div');
			note.className = 'tfhp-turnstile-error';
			note.style.color = '#b32d2e';
			note.style.fontSize = '0.875em';
			note.style.marginTop = '6px';
			if (el.parentNode) {
				el.parentNode.insertBefore(note, el.nextSibling);
			}
		}
		note.textContent = message;
	}

	function clearWidgetError(el) {
		var note = findWidgetError(el);
		if (note && note.parentNode) {
			note.parentNode.removeChild(note);
		}
	}

	/* ---- render / reset / remove --------------------------------------- */

	// Turnstile's widget is a fixed-width box (300px for the 'normal' size)
	// that does not shrink with its container, so in tight modals on narrow
	// phones it overflows the form and clips at the modal edge (proven at
	// 320px viewports, where the container is ~228px). When the container is
	// narrower than the widget, scale the widget down to fit; a CSS transform
	// keeps the iframe fully interactive. The page-level scrollWidth stays
	// clean either way because modals clip internally, so this must run on
	// every render and on resize, not only when overflow is externally
	// measurable.
	function fitWidget(el) {
		el.style.transform = '';
		el.style.transformOrigin = '';
		el.style.height = '';

		var natural = el.scrollWidth;
		var avail = el.clientWidth;

		if (!natural || !avail || avail >= natural) { return; }

		var scale = avail / natural;
		el.style.transform = 'scale(' + scale + ')';
		el.style.transformOrigin = '0 0';
		el.style.height = Math.ceil(el.offsetHeight * scale) + 'px';
	}

	function fitAllWidgets() {
		var widgets = document.querySelectorAll('.tfhp-turnstile');
		for (var i = 0; i < widgets.length; i++) {
			if (widgets[i].dataset[RENDER_FLAG] === '1') { fitWidget(widgets[i]); }
		}
	}

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
				// The iframe lays out asynchronously after render(), so an
				// immediate fit can measure an empty box; re-fit as it settles.
				fitWidget(el);
				setTimeout(function () { fitWidget(el); }, 400);
				setTimeout(function () { fitWidget(el); }, 1200);
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
		el.style.transform = '';
		el.style.transformOrigin = '';
		el.style.height = '';
		clearWidgetError(el);
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

	// Show the "please verify" message in the form's own messages container,
	// mirroring how HivePress renders its error responses there. Core clears
	// the container at the start of its next (unblocked) submit run.
	function showBlockedMessage(form) {
		var container = form.querySelector('.hp-form__messages');
		if (!container) { return; }

		var message = (window.tfhpTurnstileData && window.tfhpTurnstileData.blockedMessage) ||
			'Please verify that you are human.';

		container.textContent = '';
		var div = document.createElement('div');
		div.textContent = message;
		container.appendChild(div);
		container.classList.add('hp-form__messages--error');
		container.style.display = 'block';
	}

	function gateForm(form) {
		if (!form || form.dataset[GATED_FLAG] === '1') { return; }
		form.dataset[GATED_FLAG] = '1';

		form.addEventListener('submit', function (e) {
			if (!form.querySelector('.tfhp-turnstile')) { return; }
			// The Turnstile API never loaded (blocked or Cloudflare down):
			// stand down and let the server decide, instead of bricking the
			// form for the whole outage.
			if (apiFailed && !hasToken(form)) { return; }
			if (!hasToken(form)) {
				e.preventDefault();
				e.stopImmediatePropagation();
				// Nudge the widget for this form to render, and tell the user
				// why nothing was submitted.
				var w = form.querySelector('.tfhp-turnstile');
				if (w) { renderWidget(w); }
				showBlockedMessage(form);
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
		el.style.transform = '';
		el.style.transformOrigin = '';
		el.style.height = '';
		clearWidgetError(el);
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

		// Reset a widget after ITS OWN form is submitted (tokens are single-use).
		//
		// Matching /hivepress/v1/ anywhere in the URL and then resetting every
		// widget on the page was far too wide. That namespace carries photo
		// uploads, favourite toggles and tag autocompletes, not just form
		// submissions, and the listing_submit page runs a Turnstile widget, an
		// uploader and an autocomplete together: each of up to ten photo
		// uploads wiped the token the visitor had already earned, so pressing
		// Submit answered "please verify that you are human" to somebody who
		// just had. Reset only the widget whose own form made the request,
		// which is what HivePress core does for reCAPTCHA (it resets from the
		// form's own complete callback, common.js:1185).
		$(document).on('ajaxComplete', function (event, xhr, settings) {
			var url = settings && settings.url ? settings.url : '';

			if (!url) { return; }

			var widgets = document.querySelectorAll('.tfhp-turnstile');

			for (var i = 0; i < widgets.length; i++) {
				var form = widgets[i].closest ? widgets[i].closest('form') : null;

				// HivePress posts a form to its own data-action URL
				// (common.js:1161), so that is the only URL that can mean
				// "this form was just submitted".
				if (form && sameUrl($(form).data('action'), url)) {
					resetWidget(widgets[i]);
				}
			}
		});
	}

	// Compares two URLs as the browser resolves them, so a relative action and
	// an absolute request URL still match.
	function sameUrl(a, b) {
		if (!a || !b) { return false; }
		if (a === b) { return true; }

		try {
			return new URL(a, window.location.href).href === new URL(b, window.location.href).href;
		} catch (e) {
			return false;
		}
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

		// Re-fit scaled widgets when the viewport changes (rotation, resize).
		var fitDebounce = null;
		window.addEventListener('resize', function () {
			if (fitDebounce) { clearTimeout(fitDebounce); }
			fitDebounce = setTimeout(fitAllWidgets, 150);
		});

		whenJqueryReady();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}

})();
