/* reCAPTCHA v3 for the theme's forms (inc/theme-settings/security/verify.php).
 *
 * v3 has no box to tick. Google gives each page view a one-use token that proves how human the
 * visitor looks, and the server checks the score. A form carries it in a hidden
 * <input data-devbase-recaptcha> named g-recaptcha-response, so whatever submits the form (a normal
 * post or an AJAX FormData) sends it.
 *
 * GOOGLE'S SCRIPT IS LOADED ON DEMAND, not with the page. It is large (about 350 KiB of script plus a
 * frame, a stylesheet and a font) and a form such as a newsletter box in the footer is on every page,
 * so loading it up front made every page slower for the few visitors who use a form. It loads the first
 * time someone touches a form that has a token field (focus, click, touch or key press inside it), and if
 * they submit before a token has arrived, the submit is held for a moment, the token fetched, and the
 * submit sent again.
 *
 *  - Tokens expire after 2 minutes and work once, so once loaded they are refreshed every 90 s, when the
 *    tab comes back to the front (browsers slow timers in background tabs), and after each submit.
 *  - A failed load or token never blocks the page: the server rejects a submit with no token and the form
 *    shows its own error.
 */
(function () {
	var inputs = Array.prototype.slice.call(document.querySelectorAll('input[data-devbase-recaptcha]'));
	if (!inputs.length) { return; }

	var key = inputs[0].getAttribute('data-sitekey');
	var loading = null;
	var timer = null;
	var MAX_AGE = 100000; // a token older than this is treated as spent

	// Loads Google's script once and resolves when grecaptcha is ready.
	function load() {
		if (loading) { return loading; }

		loading = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = 'https://www.google.com/recaptcha/api.js?render=' + encodeURIComponent(key);
			script.async = true;
			script.onload = function () { window.grecaptcha.ready(resolve); };
			script.onerror = function () { loading = null; reject(new Error('reCAPTCHA did not load')); };
			document.head.appendChild(script);
		});

		return loading;
	}

	function fresh(input) {
		return !!input.value && (Date.now() - Number(input.getAttribute('data-token-at') || 0)) < MAX_AGE;
	}

	// Puts a new token in each field (or only the ones given), loading Google's script if needed.
	function refresh(list) {
		return load().then(function () {
			return Promise.all((list || inputs).map(function (input) {
				return window.grecaptcha
					.execute(input.getAttribute('data-sitekey'), { action: input.getAttribute('data-action') || 'submit' })
					.then(function (token) {
						input.value = token;
						input.setAttribute('data-token-at', String(Date.now()));
					}, function () { input.value = ''; });
			}));
		}, function () { /* no script: the server will refuse the submit, and the form says so */ });
	}

	function keepFresh() {
		if (timer) { return; }
		timer = window.setInterval(function () { if (!document.hidden) { refresh(); } }, 90000);
		document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
	}

	// First touch of a form with a token field: load Google's script and get the first token.
	function firstTouch(event) {
		var form = event.target && event.target.closest ? event.target.closest('form') : null;
		if (!form || !form.querySelector('input[data-devbase-recaptcha]')) { return; }

		['focusin', 'pointerdown', 'touchstart', 'keydown'].forEach(function (type) { document.removeEventListener(type, firstTouch, true); });
		refresh().then(keepFresh);
	}

	['focusin', 'pointerdown', 'touchstart', 'keydown'].forEach(function (type) {
		document.addEventListener(type, firstTouch, true);
	});

	// A submit with no usable token yet is held, the token fetched, then the submit is sent again.
	document.addEventListener('submit', function (event) {
		var form = event.target;
		var input = form && form.querySelector ? form.querySelector('input[data-devbase-recaptcha]') : null;

		if (!input) { return; }

		if (fresh(input)) {
			// This submit uses the token in the field; the next one needs a new token.
			window.setTimeout(function () { refresh(); }, 1500);
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();

		refresh([input]).then(function () {
			if (typeof form.requestSubmit === 'function') {
				form.requestSubmit(event.submitter || undefined);
			} else {
				form.submit();
			}
		});
	}, true);
}());
