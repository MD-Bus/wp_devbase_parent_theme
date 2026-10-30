/* The shared form script (inc/helpers/forms/fields.php loads it). It sends any <form data-devbase-form>
 * to its action over AJAX and shows the answer devbase_form_submit() gives, so a form needs no script of
 * its own: everything it needs is markup.
 *
 * Checking before sending: the browser's own rules on each field (required, type="email", minlength,
 * pattern...), with surrounding spaces ignored for required fields. The message shown for a failing
 * field is its data-error attribute, else the browser's.
 *
 * Where errors go:
 *   [data-error-for="email"]  the message for the field named email (an element anywhere in the form)
 *   [data-form-message]       anything with no place of its own, and the server's general refusals
 * A failing field also gets aria-invalid="true" and the class in the form's data-invalid-class
 * (default "is-invalid").
 *
 * On success: the form is replaced by the content of its <template data-form-success>, with {key} in
 * its text filled from the server's reply data (devbase_form_submit()'s 'success'). No template: the
 * form is reset and the [data-form-message] shows the form's data-success text.
 * On failure with no message from the server: the form's data-failure text.
 *
 * Events, for a form with more to do: "devbase:form-success" and "devbase:form-error" on the form,
 * with the reply in event.detail. Cancelling devbase:form-success (preventDefault) keeps the form.
 *
 * The reCAPTCHA v3 script holds a submit until it has a token, then submits again, so this listens on
 * the form (it runs after that one, which listens on the document in the capture phase). A Turnstile
 * token works once, so the widget is reset after every reply.
 */
(function () {
	function each(list, fn) { Array.prototype.forEach.call(list, fn); }

	function setup(form) {
		var invalidClass = form.getAttribute('data-invalid-class') || 'is-invalid';
		var general = form.querySelector('[data-form-message]');
		var button = form.querySelector('[type=submit]');

		function clear() {
			each(form.querySelectorAll('[aria-invalid]'), function (el) {
				el.removeAttribute('aria-invalid');
				el.classList.remove(invalidClass);
			});
			each(form.querySelectorAll('[data-error-for]'), function (el) { el.textContent = ''; });
			if (general) { general.textContent = ''; general.hidden = true; }
		}

		function say(text) {
			if (!general) { return; }
			general.textContent = text || '';
			general.hidden = !text;
		}

		// Shows an error for a field (or the general one); returns the field to focus.
		function fail(name, text) {
			var field = name ? form.querySelector('[name="' + name + '"]') : null;
			var place = name ? form.querySelector('[data-error-for="' + name + '"]') : null;

			if (field) {
				field.setAttribute('aria-invalid', 'true');
				field.classList.add(invalidClass);
			}

			if (place) { place.textContent = text; } else { say(text); }

			return field;
		}

		function check() {
			var first = null;

			each(form.elements, function (field) {
				if (!field.name || field.disabled || field.type === 'hidden' || !field.willValidate) { return; }

				var blank = field.required && typeof field.value === 'string' && field.value.trim() === '' && field.type !== 'checkbox';

				if (blank || !field.checkValidity()) {
					var el = fail(field.name, field.getAttribute('data-error') || field.validationMessage);
					first = first || el;
				}
			});

			return first;
		}

		function fill(node, data) {
			if (node.nodeType === 3) {
				node.textContent = node.textContent.replace(/\{(\w+)\}/g, function (all, key) {
					return data && data[key] != null ? String(data[key]) : '';
				});
				return;
			}
			each(node.childNodes, function (child) { fill(child, data); });
		}

		function resetCaptcha() {
			var widget = form.querySelector('.cf-turnstile');
			if (widget && window.turnstile) { try { window.turnstile.reset(widget); } catch (e) { /* not rendered yet */ } }
		}

		function emit(type, detail) {
			var event = new CustomEvent(type, { bubbles: true, cancelable: true, detail: detail });
			return form.dispatchEvent(event);
		}

		function succeed(data) {
			if (!emit('devbase:form-success', data)) { return; }

			var template = form.querySelector('template[data-form-success]');

			if (template) {
				var box = document.createElement('div');
				box.className = template.getAttribute('data-class') || '';
				box.setAttribute('role', 'status');
				box.appendChild(template.content.cloneNode(true));
				fill(box, data);
				form.replaceWith(box);
				return;
			}

			form.reset();
			say(form.getAttribute('data-success') || '');
		}

		function refuse(data) {
			emit('devbase:form-error', data);

			var errors = data && data.errors ? data.errors : (data && data.message ? [{ field: data.field, message: data.message }] : []);
			var first = null;

			if (!errors.length) { errors = [{ field: null, message: form.getAttribute('data-failure') || '' }]; }

			errors.forEach(function (e) {
				var el = fail(e.field, e.message || form.getAttribute('data-failure') || '');
				first = first || el;
			});

			if (first) { first.focus(); }
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();
			clear();

			var first = check();
			if (first) { first.focus(); return; }

			if (button) { button.disabled = true; }
			form.setAttribute('aria-busy', 'true');

			fetch(form.getAttribute('action') || form.getAttribute('data-ajax'), { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
				.then(function (r) { return r.json().catch(function () { return { success: false, data: {} }; }); })
				.then(function (res) {
					if (res.success) { succeed(res.data || {}); } else { refuse(res.data || {}); }
				}, function () { refuse({}); })
				.then(function () {
					if (button) { button.disabled = false; }
					form.removeAttribute('aria-busy');
					resetCaptcha();
				});
		});
	}

	each(document.querySelectorAll('form[data-devbase-form]'), setup);
}());
