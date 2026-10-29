# inc/theme-settings/security/ — Security Settings sub-page

Lets a site use Cloudflare Turnstile or Google reCAPTCHA (v2 checkbox) on
its forms, without any form template needing to know or care which one is
actually configured.

```
security/
  settings.php   — registers the sub-page, its two-tab field group, the
                     mutual-exclusivity validation, and enqueues security.js
  verify.php       — the functions forms actually call (see below)
  security.js         — admin-only: auto-toggles the other switch off for UX
  AGENT.md               — this file
```

This is another example of the "one subfolder per sub-menu" pattern
described in `inc/theme-settings/AGENT.md` — copy `smtp/` or this folder
for the next one, not `theme-settings.php` itself.

## `settings.php`

Registers the sub-page (`menu_slug: security-settings`, under
`theme-settings`) and one field group with two ACF **tabs** — Turnstile
first, reCAPTCHA second — each holding an enable toggle plus a site key
(text) and secret key (password) field. Both key fields are `'required' =>
1` but conditional on their own tab's enable toggle, so — same mechanism
as SMTP Settings (see `inc/theme-settings/smtp/AGENT.md`) — ACF only
enforces "must be filled in" while that provider is actually switched on.

**Only one provider may be enabled at a time.** This is enforced in two
layers, and only one of them actually matters:

- `security.js`, enqueued only on this admin page, listens for either
  toggle switching on and immediately clicks the other one off — purely
  so the admin sees consistent state without needing to hit save first.
  This runs in the browser and proves nothing.
- The real enforcement is server-side: an `acf/validate_save_post` handler
  in `settings.php` reads `$_POST['acf']` directly and calls
  `acf_add_validation_error()` if both enable fields come through true,
  which blocks the save entirely. This is what actually guarantees the
  invariant — it runs regardless of JS, browser state, or a malformed
  request.

## `verify.php` — what forms actually call

Nothing else in this theme should call Turnstile's or reCAPTCHA's APIs
directly — everything goes through these functions, so a form template
never needs an `if (turnstile) { ... } else if (recaptcha) { ... }` of its
own:

- **`devbase_security_active_provider()`** — returns `'turnstile'`,
  `'recaptcha'`, or `null`. Because of the mutual-exclusivity enforcement
  above, this is never ambiguous.
- **`devbase_security_widget_html()`** — returns the ready-to-echo widget
  markup (`<div class="cf-turnstile" ...>` or `<div class="g-recaptcha"
  ...>`) for whichever provider is active, and enqueues that provider's JS
  API script. Returns an empty string if no provider is active or its site
  key isn't set — a form should just skip rendering anything in that case,
  not show a broken widget.
- **`devbase_security_verify( $token )`** — verifies a token against
  whichever provider is active via that provider's `siteverify` endpoint
  (`wp_remote_post()`). Returns `true` if no provider is active at all —
  "nothing to check" is treated as "not blocked," matching how the SMTP
  fields behave when disabled. Returns `false` only when a provider is
  active and the token is missing, empty, or actually fails verification.
- **`devbase_security_verify_request()`** — convenience wrapper: reads
  the correct `$_POST` field name for whichever provider is active
  (`cf-turnstile-response` vs `g-recaptcha-response`) and calls
  `devbase_security_verify()` for you. This is the one most form
  handlers should actually call.

**Scope note:** reCAPTCHA support is v2 (checkbox) only, matching
Turnstile's simplicity — both render as a single visible widget dropped
into a form and verified the same way after submit. reCAPTCHA v3's
invisible/execute-on-submit/score-based flow is a different integration
shape entirely and isn't supported here; add it as a separate provider
option later if a client specifically needs it, don't bend this one to
cover both.
