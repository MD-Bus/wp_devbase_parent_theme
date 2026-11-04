# Roadmap

What is planned, what is being considered, and what has been deliberately
ruled out. Not a schedule — this is a tool built alongside client work, so
items land when a project needs them.

## Next

Planned work, in order. Not a schedule.

**Who decides what is valid.** Two separate systems, and the site never decides
validity by itself.

- **Warden** is the software licence: updates, release downloads and the
  payment kill switch. Warden answers, and the code on the site enforces the
  answer (the updater here, the kill switch in each child).
- **Brand Relay** is the maintenance plan. It is the authority and it enforces
  its own side whatever the site does: `403 feature_off` when the plan lacks the
  service or the subscription is suspended, expired or not started, `429
  daily_limit`, `413 too_large`, and `401` for a revoked key. The site's job is
  to not make useless calls. It reads the `licence` object (services in force,
  their limits, status, `expires_at`) from the `/v1/site` pull it already makes,
  with no extra request. It turns a service on only while that service is in
  force, checks `expires_at` itself even when the cached answer is fresh, stops
  on `401` and `403`, backs off on `429`, and keeps the last good answer through
  an outage. The heartbeat is the one exception: any site with a key may send
  it, with no plan.

**1. Housekeeping.** Push 1.18.1 (the Licence key label). Remove the local test
leftovers (the dev server, the `theme-test-2` API key, the `ocwof.local` slot in
Warden, the stray `* 2.php` files in the OCWOF child). Write the live cutover
checklist: add `warden_licence_key` to the live Brand Relay, remove the two old
params, trigger a sync, then check both themes under their tags. First find out
which Brand Relay is deployed: `main` and its tags stop at 0.13.0 while `develop`
is at 0.28.x, and the services below exist only from 0.18 up.

**2. Heartbeat (1.19.0).** A daily report to `POST /v1/site/heartbeat` with
WordPress, PHP and theme versions, waiting updates, disk, cron health and the
last backup time, sent with the Brand Relay key the site already holds. This
step also brings the shared plumbing: keep the `licence` object from `/v1/site`
next to the params, give every service one "is it in force" test, and show the
last report and answer in a Maintenance block on the Licence page. The `theme`
field is one version string; send the child's and keep the parent's elsewhere.

**3. Error events (1.20.0).** Fatals and uncaught exceptions first (warnings and
notices flood), and failed scheduled jobs if they prove useful. Queue them, send
at the end of a request at most once every 30 seconds, 50 events and 64 KB per
batch, within the plan's daily count. Scrub before sending, never report the
reporter's own errors, and never put customer data in a message. Needs the
`events` service.

**4. Backups.** The largest piece, and Brand Relay only receives files: making
the backup is the site's job, uploaded in parts of exactly 50 MiB. Two options.
(A) Upload what a host or a backup plugin already produces, and report
`last_backup_at`: small and safe. (B) Make the backup inside the theme, a
database dump plus a files archive, written and uploaded in resumable steps by
a background task: much larger, and exposed to shared hosts' time, memory and
disk limits. Do A first and take B only if a client needs it. Needs the `backup`
service and its limits (per day, maximum size, versions kept).

**5. Warden follow-ups.** Decide whether a batch check (one request for both
tags, installed versions keyed by tag) is worth it; it saves one request per 12
hours. Move the children's kill switch from the status name `suspended` to
`valid: false`, since statuses now belong to each app. Lock whichever is chosen
in a parent test.

**6. The first real WooCommerce version update.** Bump the pin, build the zip,
release, and watch a site with the shop on take the new version at the end of
the request without a fatal error, while WordPress offers no update of its own.

**7. Watch upstream.** WordPress/ai is adding `core/content-create`,
`core/content-update` and `core/content-delete` (pull request 1025, open), and
core proposes `core/read-content` and `core/manage-content` for 7.1 and 7.2
or later. Neither covers custom fields, options pages or blocks, so our
abilities stay. Revisit our post abilities if those ship with meta support.

## Considered

**A header and footer template pair.** The theme ships one placeholder
template on the basis that templates are what every project replaces. If
most child themes end up writing the same layout scaffolding, that
judgement was wrong and a base layout should move here.

**More schema types.** The current three are the ones nearly every site
needs. Product, FAQ and Review come up often enough that a shared
implementation may eventually earn its place — but each additional type is
a maintenance commitment against a spec that changes.

**A richer MCP surface.** Deleting posts, managing taxonomy terms, and
listing or editing existing media (upload exists since 1.6.0) are all
plausible. So is editing one block of a page in place; today a page's
`blocks` are always written whole. Each widens what an AI client can do
to a live site, so each needs its own argument rather than being added for
completeness. Upstream is adding content abilities of its own; see "Watch
upstream" under Next.

**WooCommerce, what is left.** WooCommerce shipped in 1.15.0 as a plugin bundled
in the theme (a committed zip, installed into `wp-content/plugins/` only for a
child that asks, locked against WordPress updates, released only with the
theme). Still to check on the first real store: an exception in the editor's
block allow-list for WooCommerce's blocks, what suspension does to cart and
checkout requests, and an update on a staging store with a real checkout before
it ships.

**A setting for who sees the Credit tab.** The credit's settings are hidden
from clients by a filter in each child (`devbase/credit/hide_settings`) that
lists the developer logins, so every client's child repeats it. A parent
setting holding the developer logins would leave a child needing only its
default address.

**Show Warden's message only when it applies.** The Licence status table prints
whatever message Warden sends, so a message left on an active licence (for
example an old "Payment overdue" from a first installation) shows to the
client's admins beside an active status. It could be shown only when the status
is not plainly active, or during a grace period.

**A stronger ping.** The sync ping proves the sender holds the site key, but its
`Origin` header is a claim a non-browser caller can write, and an IP allowlist
does not work for a Cloudflare Worker. Brand Relay signing the ping with its
own Ed25519 key, with the public key stored here as Warden's is, would prove
the sender is Brand Relay itself, and would stop a stolen site key being enough.
It needs a change in Brand Relay.

**Per-site credit overrides.** The brand credit uses Brand Relay's public brand
address on purpose: the site address carries private values. A site that needs
a different link text or target would be a separate, allowlisted feature that
copies only named fields.

**A private home for the AGENT.md notes.** They are git-ignored, so they exist
on one disk and are not backed up by git. A private repository or a backup rule
would fix that.

**reCAPTCHA Enterprise.** The theme supports reCAPTCHA v3 through Google's classic
`siteverify`, and Turnstile. A key made in Google Cloud as Enterprise is checked
through a different API (an assessment with a Cloud project and API key) and
loads a different script, so it is not supported. Worth building only if a client
needs Enterprise specifically; Turnstile avoids the Cloud project entirely.

**Per-destination retry for form dispatch.** A failed webhook is recorded
but not retried. Worth building when a client is actually affected.

## Not planned

**A general-purpose schema mapping engine.** Mapping arbitrary fields to
arbitrary schema.org types is a large thing to build and a larger thing to
maintain correctly. The curated list stays curated.

**Cookie consent.** Previously part of this theme, removed. Not every
client needs it, consent requirements vary by jurisdiction, and a single
fixed implementation serves that badly. Use a dedicated plugin, or build
it for the project that needs it.

**A navigation settings page.** Tried and removed. Navigation varies too
much between sites — flat lists, mega menus, multi-level nesting — for one
generic repeater to fit. Use WordPress's own menu system per site.

**A company information page.** Also tried and removed. It duplicated what
Organization schema already stores.

**Splitting the theme into independently updatable modules.** WordPress
updates themes as a whole — a theme update replaces the entire directory,
so a subdirectory cannot be updated on its own. Independent pieces would
also need dependency resolution between them, which the update system does
not do and Composer already handles for the parts that need it.

## Contributing

Items here are not assignments. If you want to work on one, open an issue
first so the design can be agreed before code is written — particularly
for anything touching the update system or MCP access, where the security
model matters more than the feature.
