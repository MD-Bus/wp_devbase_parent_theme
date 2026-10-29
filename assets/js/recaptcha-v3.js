/* reCAPTCHA v3 for the theme's forms (inc/theme-settings/security/verify.php).
 *
 * v3 has no box to tick. Google gives each page view a one-use token that proves
 * how human the visitor looks, and the server checks the score. This keeps a fresh
 * token in every <input data-devbase-recaptcha> inside a form, so whatever submits
 * the form (a normal post or an AJAX FormData) carries it as g-recaptcha-response.
 *
 *  - Tokens expire after 2 minutes and work once, so they are refreshed every 90 s,
 *    again when the tab comes back to the front (browsers slow timers in background
 *    tabs), and again right after every submit.
 *  - A refresh fails quietly: no token means the server rejects the submit and the
 *    form shows its own error. It never blocks the page.
 */
(function () {
	var inputs = Array.prototype.slice.call(document.querySelectorAll('input[data-devbase-recaptcha]'));
	if (!inputs.length) { return; }

	function whenReady(callback, tries) {
		if (window.grecaptcha && window.grecaptcha.ready) {
			window.grecaptcha.ready(callback);
		} else if ((tries || 0) < 100) {
			window.setTimeout(function () { whenReady(callback, (tries || 0) + 1); }, 150);
		}
	}

	function refresh() {
		whenReady(function () {
			inputs.forEach(function (input) {
				window.grecaptcha
					.execute(input.getAttribute('data-sitekey'), { action: input.getAttribute('data-action') || 'submit' })
					.then(function (token) { input.value = token; }, function () { input.value = ''; });
			});
		});
	}

	refresh();
	window.setInterval(refresh, 90000);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
	// The token just sent is spent: get the next one once the submit has gone out.
	document.addEventListener('submit', function () { window.setTimeout(refresh, 1500); }, true);
}());
