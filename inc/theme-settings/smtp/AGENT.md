# inc/theme-settings/smtp/ — SMTP Settings sub-page

Lets a site send outgoing mail through a real SMTP provider (Gmail,
SendGrid, a client's own mail host, etc.) instead of PHP's default
`mail()`, which is frequently unreliable or entirely broken depending on
hosting.

```
smtp/
  settings.php   — registers the sub-page + field group + enqueues test.js
  mailer.php       — phpmailer_init hook that actually applies the config
  test.php           — AJAX handler for the "Send Test Email" button
  test.js              — the button's click handling
  AGENT.md               — this file
```

This folder is also the reference example for the "one subfolder per
sub-menu" pattern described in `inc/theme-settings/AGENT.md` — copy this
structure for the next sub-menu, not `theme-settings.php` itself.

## `settings.php`

Registers the sub-page (`menu_slug: smtp-settings`, under `theme-settings`)
and its field group via `acf_add_local_field_group()` — built in code, not
through the SCF UI, so it's identical on every site running this theme.
Fields: an enable toggle, then host/port/encryption, username/password,
from-email/from-name — laid out in three rows using ACF's `wrapper.width`
(e.g. `array('width' => '50')`) rather than one field stacked per line,
and each connection field hidden via `conditional_logic` tied to the
enable toggle until it's switched on.

**Required-field validation is free, not custom-built.** ACF skips
required-field validation for any field currently hidden by conditional
logic. So marking host/port/username/password/from-email as
`'required' => 1` **only actually blocks saving while the enable toggle is
on** — exactly "if SMTP is enabled, don't let save go through until
everything is filled in," with zero custom JS validation needed. Two
fields are deliberately left optional:
- **Encryption** — "None" is a legitimate choice whose underlying value is
  an empty string; marking it required would make that specific choice
  impossible to save.
- **From Name** — `mailer.php` falls back to the site name if it's blank,
  so there's nothing to enforce.

This file also enqueues `test.js`, but only on this specific admin page
(checks `$_GET['page'] === 'smtp-settings'` in an `admin_enqueue_scripts`
callback) — no reason to ship it site-wide.

**No Destinations here anymore.** SMTP destination configuration used to
live on this page (a `smtp_destinations` repeater), but was moved to the
unified `inc/theme-settings/destinations/` sub-page (one repeater
covering both SMTP and webhook destinations, each row picking its own
type) — see `inc/theme-settings/destinations/AGENT.md`. This page is back
to being purely the mail-server connection config; `inc/forms/dispatch/smtp.php`
still reuses this file's `mailer.php` transport via `wp_mail()` unchanged
whenever a Form Destinations row of type `smtp` fires.

## `mailer.php`

The part that actually does something: hooks `phpmailer_init` and
configures the SMTP transport (`isSMTP()`, `Host`, `Port`, `SMTPAuth`,
`Username`, `Password`, `SMTPSecure`, `setFrom()`) from the saved fields.
Bails immediately unless `smtp_enabled` is true **and** a host is actually
set — an incomplete or disabled config leaves WordPress's default mail
behavior completely untouched, never a half-configured broken state.

## `test.php` + `test.js`

The "Send Test Email" button, added as an ACF `message`-type field (raw
HTML: a button + a result `<span>`) rather than a real settings field,
since it doesn't store anything. `test.js` posts to
`admin-ajax.php?action=devbase_smtp_test` with a nonce; `test.php`
registers the matching `wp_ajax_devbase_smtp_test` handler, sends a real
test email to the current admin's own address via `wp_mail()`, and — this
is the part worth remembering — **captures the actual PHPMailer failure
reason** via a temporary `wp_mail_failed` hook rather than reporting a
generic "it failed." A bad host/port/credentials shows the admin the real
SMTP error string (e.g. "Could not connect to SMTP host"), not a dead end.

The test always runs against **saved** settings, not whatever's currently
typed into the form but not yet saved — save first, then test. Building a
test-against-unsaved-values flow would mean serializing the in-progress
form state into the AJAX request instead of just reading options, which
adds real complexity for a marginal convenience; not done here.

**Every test run is also recorded**, success or failure, to a
`devbase_smtp_last_test` option (`{time, success, message}`) —
`devbase_smtp_last_test_message()` (also in `test.php`) turns that into
a human-readable line for `inc/theme-settings/miscellaneous/`'s System
Status tab, so "is SMTP actually working" is answerable without
revisiting this page and clicking the button again. This is purely a
read of what the button last recorded — the Status tab never triggers a
test itself.

## A real bug this surfaced (worth remembering)

While building this, a routine action (creating a new WordPress user, which
triggers a welcome-email `wp_mail()` call) took **~13 seconds**. It looked
like a code problem at first. It wasn't: `mailer.php` was correctly
configured with real Gmail credentials, and a raw `nc smtp.gmail.com 587`
connection test — completely outside PHP/WordPress — took the same ~10
seconds just to establish a TCP connection, while `nc smtp.gmail.com 465`
connected in under 40ms. The network path was throttling/blocking port
587 specifically (common on some ISPs/routers as an anti-spam measure);
switching the Encryption field to SSL and the Port to 465 fixed it
immediately, no code change.

Lesson: when something built here feels slow, verify with a tool *outside*
the code (`nc`, `curl`, timing a raw call) before assuming the PHP is at
fault — this session's actual "slow code" incidents have all turned out to
be either environment/network conditions or a stale value already sitting
in the database, not inefficient code.
