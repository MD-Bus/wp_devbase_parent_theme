# inc/forms/dispatch/ — the send side

Fires automatically whenever a submission is logged, finds every
configured destination that applies to that form, and sends to each. See
`inc/forms/AGENT.md` for how this fits into the whole feature, and
`inc/theme-settings/destinations/AGENT.md` for where destinations
themselves are defined.

```
dispatch/
  AGENT.md               — this file
  orchestrator.php           — save_post_form_data hook + orchestrator
  smtp.php                     — sends to one SMTP destination row
  webhook.php                    — sends to one webhook destination row
  status.php                       — aggregates recent dispatch outcomes
                                       for the System Status tab
```

## `orchestrator.php`

**Trigger:** `save_post_form_data` (fires only for the `form_data` CPT).

**Double-fire guard** — two layers: standard `wp_is_post_revision()` /
`wp_is_post_autosave()` bail, plus a `_devbase_dispatched` postmeta flag
checked before doing any work and set immediately before dispatching. The
meta flag is the important guard — `wp_insert_post()` can trigger
`save_post` more than once in some paths, and it also prevents
re-dispatch if an admin later manually re-saves a Form Data post in
wp-admin. Verified directly:
`tests/Test_Form_Dispatch.php::test_webhook_dispatch_sends_expected_json_body_once`
asserts a manual re-save does NOT trigger a second webhook call.

**`devbase_forms_dispatch( $post_id )`** — the orchestrator:
1. Read `form_name` + `content` postmeta.
2. `get_field( 'destinations', 'option' )` — the ONE repeater on
   `inc/theme-settings/destinations/`, no per-form lookup. If it's empty
   entirely, log + record a dispatch-log entry saying so.
3. Filter to rows where `in_array( $form_name, $row['apply_to'] ?: [], true )`.
   A submission for a form that matches zero rows isn't an error — it's
   logged as `"No destination applies to form ..."` (both `error_log` and
   `_devbase_dispatch_log`) so it's debuggable, not silent.
4. For each matching row, branch on `$row['type']` to
   `devbase_forms_dispatch_smtp( $row, $form_name, $content )` or
   `devbase_forms_dispatch_webhook( $row, $form_name, $content )` — the
   row is passed directly; there's no separate by-key lookup step, since
   (unlike the earlier design) a destination row is fully self-contained.
5. Every attempt — success or failure, per destination — gets an
   `error_log()` line prefixed `[Boostway Forms]`, AND gets saved as a
   `_devbase_dispatch_log` postmeta entry
   (`{success, label, message}` per destination) via the
   `devbase_forms_save_dispatch_log()` helper, which is what
   `inc/forms/form-data-admin-display.php`'s Submission meta box reads.
   `error_log` is the always-available dev trail; the postmeta is what
   makes "why didn't this form notify anyone" answerable from the post
   itself in wp-admin.

## `smtp.php`

`devbase_forms_dispatch_smtp( array $row, $form_name, $content )` —
takes the destination row directly (no lookup); builds a generic HTML
key/value table from `$content` (`aria_label` as row label, `value` as
row value, both `esc_html()`'d — no per-form templates, subject
`New submission: {form_name}`); sends via `wp_mail()`, which automatically
routes through this theme's existing SMTP transport
(`inc/theme-settings/smtp/mailer.php`'s `phpmailer_init` hook) whenever
`smtp_enabled` is on — no new mail-transport code here at all.

## `webhook.php`

`devbase_forms_dispatch_webhook( array $row, $form_name, $content )` —
takes the destination row directly; POSTs `{"form_name", "timestamp",
"data": $content}` as JSON via `wp_remote_post()`. `form_name` sits at
the top level (not nested inside `data`) specifically so a receiver
(n8n, Zapier, Make, a custom endpoint) can route on it without parsing
into the submission body first.

**Authentication is a plain static header, not HMAC signing**: if the row
has both a Password/Key (`auth_value`) and Header Name
(`auth_header_name`) set, that value is sent as-is under that header name
— e.g. Header Name `X-API-Key`, Password/Key `sk_live_...` sends
`X-API-Key: sk_live_...`. Deliberately simple: most real receivers (n8n,
Zapier, Make, a client's own API) just expect a fixed API key or bearer
token in a header they name themselves, not signature verification on
their end. Either field left blank means the request goes out
unauthenticated — a valid choice too.

## A real bug this surfaced: `update_post_meta()` silently corrupts pre-escaped JSON

`orchestrator.php`'s `devbase_forms_save_dispatch_log()` and
`inc/helpers/forms/log-submission.php`'s `content` meta both store
`wp_json_encode()`'d strings as postmeta. `update_post_meta()` (and
`wp_insert_post()`'s `meta_input`) call `wp_unslash()` on the value before
storing — WordPress assumes meta input arrives already magic-quoted, the
same way `$_POST` would be, and undoes that. If the value already
contains **genuine** backslash-escapes (exactly what `wp_json_encode()`
adds around any literal `"` in submitted text or a destination label),
`wp_unslash()` strips those backslashes too, turning valid JSON into
broken JSON — silently: no error, no exception, just a stored string that
`json_decode()` returns `null` for later. Confirmed directly: a submitted
message containing a quote character (`He said "hello" to me`) produced
unparseable stored `content` before the fix.

**Fix: `wp_slash()` the encoded string immediately before it reaches
`update_post_meta()`/`meta_input`** — `wp_slash( wp_json_encode( $x ) )`,
not `wp_json_encode( $x )` alone. This is the correct, standard-WordPress
counter to the internal unslash. `get_post_meta()` reading it back applies
no corresponding re-slash, so what comes back is the original, correct
JSON string. Covered by
`tests/Test_Form_Submission.php::test_content_with_literal_quotes_round_trips_correctly`
and reverified with a real submission over real HTTP (see
`inc/forms/AGENT.md`'s "Verified end to end" section) — any future code
in this theme that stores a `wp_json_encode()`'d string as postmeta needs
the same `wp_slash()` wrap, this is not specific to forms.

## `status.php`

`devbase_forms_get_recent_dispatch_summary( $window = 50 )` — reads the
most recent `$window` `form_data` submissions' `_devbase_dispatch_log`
postmeta (the same data the Submission meta box shows per-post) and
rolls them into `{checked, success, failed, recent_failures}`.
Deliberately a recent window, not all-time — an old failure that's since
been fixed shouldn't keep showing as a current problem on
`inc/theme-settings/miscellaneous/`'s System Status tab, which is what
actually consumes this via `devbase_forms_dispatch_status_message()`
(the human-readable version, with an HTML failure table). See
`inc/theme-settings/miscellaneous/AGENT.md`.
