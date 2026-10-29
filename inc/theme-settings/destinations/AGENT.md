# inc/theme-settings/destinations/ — Form Destinations sub-page

Where an admin manages "where do form submissions go" for every form
registered on the site — one place, not scattered across a settings page
per channel plus a picker on each form. See `inc/forms/AGENT.md` for how
this fits into the whole feature.

```
destinations/
  settings.php   — registers the sub-page + field group + dynamic choices
  AGENT.md          — this file
```

Same self-registering `acf/init` + `acf_add_options_sub_page()` +
`acf_add_local_field_group()` pattern as every other sub-page under Theme
Settings (`smtp/settings.php` is the reference example).

## One repeater, each row a self-contained destination

`destinations` (`layout => 'block'`, since a row now has several fields
and two of them carry instructions text that need room):

- **Label** — identifies the row in the admin UI and in dispatch logs.
- **Type** — `SMTP (email)` or `Webhook`. Everything below is shown or
  hidden per-row based on this, via `conditional_logic` referencing the
  sibling `Type` field's key (a standard ACF mechanism, evaluated
  per-row — same as every `$enabled_condition` elsewhere in this theme,
  just against a select instead of a toggle).
- **Send To** (SMTP only) — the inbox.
- **Endpoint URL** / **Password / Key** / **Header Name** (Webhook only)
  — the POST target and an optional static auth header. See
  `inc/forms/dispatch/AGENT.md`'s `webhook.php` section for why this is a
  plain header, not HMAC signing.
- **Applies To** — a multi-select of registered form(s) this row routes
  to. Choices are populated dynamically (`acf/load_field/key=field_devbase_destination_apply_to`)
  from every `registered_form` post — `value` is the form's stable
  `form_name` meta (what `dispatch/orchestrator.php` actually matches
  against), `label` is the post title. Always reflects whichever forms
  are currently registered; nothing here is hand-maintained.

## Why one repeater instead of the old split (SMTP destinations + a
separate Webhook Settings page + a per-Registered-Form picker)

The earlier version needed an auto-generated stable key on every
destination row specifically because a *different* place (a Registered
Form's own edit screen) referenced a row by that key — so renaming or
restructuring a destination risked orphaning that reference. Making each
row self-contained (its own send config AND its own routing, via
`Applies To`, on the same row) removes the cross-reference entirely:
there's nothing elsewhere pointing at a row by ID, so nothing to keep in
sync and nothing that can silently orphan. The tradeoff, worth knowing:
"which destinations apply to form X" is no longer visible from that
form's own screen — you check this page and look for the form in each
row's `Applies To` list instead. Fine for a handful of destinations;
would need a rethink if a site ever accumulates dozens.

## The actual send

Lives in `inc/forms/dispatch/smtp.php` and `dispatch/webhook.php`, not
here — this folder is UI + storage only.

## Dev-data note

Destinations previously configured under the old field names
(`smtp_destinations`, `webhook_destinations`) and the old per-Registered-Form
picker are gone with this restructure — re-entered manually here, not
migrated (this is pre-production theme-dev data; see the root `AGENT.md`'s
rule against manual DB patches — the fix belongs in code/UI, and there's
nothing to "fix" here, just data to re-enter through the new UI).
