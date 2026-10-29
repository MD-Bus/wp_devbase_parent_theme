# inc/helpers/forms/ — the form-logging helpers

The public surface of the centralized form-logging feature (full
internals: `inc/forms/AGENT.md`). If you're building a form and want
CSRF protection + logging + spam-gating + notification dispatch without
writing any of that yourself, these three files are what you need to
know about — used together.

```
forms/
  AGENT.md              — this file
  log-submission.php       — devbase_form_log_submission()
  build-content.php          — devbase_form_build_content()
  nonce-field.php               — devbase_form_nonce_field()
```

## `devbase_form_build_content( $post, $fields )`

Turns raw submitted values (`$_POST`, typically) into the `$content` shape
`devbase_form_log_submission()` expects, so you never hand-write that
array structure or re-pick the right sanitizer per field.

```php
$content = devbase_form_build_content( $_POST, array(
    'name'    => 'Name',
    'email'   => array( 'label' => 'Email Address', 'sanitize' => 'sanitize_email' ),
    'message' => array( 'label' => 'Message', 'sanitize' => 'sanitize_textarea_field' ),
) );
```

Each entry in `$fields` is either a plain string (the label — sanitized
with `sanitize_text_field()` by default) or an array with `label` and an
optional `sanitize` callable. A `$_POST` key with nothing submitted
becomes an empty string, never a PHP notice.

**Can't be fully automatic — and that's fine.** `aria_label` is an HTML
attribute PHP never sees in `$_POST`, so you still supply the
`field_name => label` map. What this function actually removes is the
array-shaping boilerplate and the "which sanitizer for which field type"
decision-making, which is the part that was genuinely repeated,
error-prone busywork.

## `devbase_form_nonce_field( $form_name )`

```php
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php devbase_form_nonce_field( 'contact_form' ); ?>
    <!-- your fields -->
</form>
```

Echoes a CSRF nonce field, namespaced per form name (action
`devbase_form_{$form_name}`, field `_devbase_form_nonce`) — not one
shared action for every form on the site, so a token for one form can't
be replayed against a different one. Call this once, anywhere inside
your `<form>` markup, for every form that calls
`devbase_form_log_submission()` below with the same `$form_name`.

## `devbase_form_log_submission( $form_name, $content )`

```php
$result = devbase_form_log_submission( 'contact_form', $content ); // from devbase_form_build_content() above

if ( ! $result ) {
    // Blocked (missing/invalid nonce, failed CAPTCHA, or bad input) —
    // show an error, don't assume it's necessarily spam; check
    // devbase_security_active_provider() if you need to distinguish.
}
```

**What it does, automatically, so you don't have to:**
1. Verifies the nonce `devbase_form_nonce_field()` rendered — CSRF
   protection is not optional or opt-in; a form that doesn't render that
   field will have every submission blocked here.
2. Runs the submission through whichever CAPTCHA provider (Turnstile /
   reCAPTCHA) is currently configured in Security Settings — you never
   call `devbase_security_verify_request()` yourself.
3. Logs the submission as a `form_data` post.
4. Triggers dispatch (email/webhook) to whichever destination(s) have
   this form name in their "Applies To" list on the Form Destinations
   page (`inc/theme-settings/destinations/`) — entirely automatic, fires
   from a WordPress hook once the post is inserted.

**What you're responsible for:**
- Rendering `devbase_form_nonce_field( $form_name )` above, with the
  SAME `$form_name` you'll pass here.
- Adding `{"name": "your_form_name", "label": "..."}` to
  `inc/forms/registry/registered-forms.json` (in the same commit that
  builds the form) — this is what makes the name selectable on the Form
  Destinations/Retention Policy pages, and what ties dispatch back to
  your form. See `inc/forms/registry/AGENT.md`.
- Rendering `devbase_security_widget_html()` (from
  `inc/theme-settings/security/verify.php`) somewhere in your form markup
  if you want the CAPTCHA widget to actually show up — this function
  gates the *submission*, it doesn't render anything.

**Reference implementation:** `inc/forms/test-form.php` is a complete,
working example — front-end form, `devbase_form_nonce_field()`,
`admin-post.php` handler, both calls above, redirect on success/blocked.
Notably it does NOT do its own separate nonce check on top of this —
that redundancy was removed specifically to demonstrate the intended
usage: let this function's own CSRF gate be the only one, rather than
every form author hand-rolling their own in addition.

## Why these live here, not in `inc/forms/`

See `inc/helpers/AGENT.md`'s "The pattern" section — the short version:
these are the only pieces of the forms feature a caller ever needs to
read or call directly. Everything else in `inc/forms/` (the CPTs, the
registry sync, dispatch) is internal, reacting to `log-submission.php`'s
`wp_insert_post()` via a WordPress hook rather than being called by it
directly — so splitting the public entry points out into their own,
smaller-surface folder costs nothing and makes "what do I call" a
two-file answer.

## Verified

`tests/Test_Form_Build_Content.php` covers the string-shorthand default,
a custom sanitizer, the array-spec-without-`sanitize`-key fallback, a
missing `$_POST` key, and — the test that actually matters most — feeding
this function's output straight into `devbase_form_log_submission()`
and confirming the round-trip, proving the two are shape-compatible, not
just similar-looking. Also reverified with a real HTTP submission through
`test-form.php` (now using this helper instead of hand-built arrays):
whitespace correctly trimmed, `sanitize_email()` correctly applied, and
the resulting webhook body matched exactly.

`tests/Test_Form_Submission.php` covers the nonce gate directly: missing
nonce blocks (no `form_data` post created), a nonce valid for a
DIFFERENT form name also blocks (proves the action is genuinely
namespaced per form, not "any nonce will do"), and a correct nonce lets
the submission through. Reverified with real HTTP through
`test-form.php`: a POST with no `_devbase_form_nonce` field redirected
to `?blocked=1`; the same POST with the nonce actually rendered by
`devbase_form_nonce_field()` redirected to `?submitted=1`.
