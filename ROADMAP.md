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
  an outage. The heartbeat is the one thing Brand Relay does not gate today (any
  site with a key may send it), but it is meant to become a plan service of its own,
  not a free one (owner's decision): build it behind the same "is it in force" test as
  the others once Brand Relay offers the service.

**When the licence lapses (done in 1.19.0).** No licence, no maintenance from us: the theme
stops managing the bundled WooCommerce (the update locks lift; it stays installed
and active), an administrator gets a notice, and updating it, security fixes included,
becomes the client's choice. Chosen over moving plugins out of vendor/ on expiry.

**1. Housekeeping.** (1.19.0, with the licence-lapse rule, is released.) Remove the local test
leftovers (the dev server, the `theme-test-2` API key, the `ocwof.local` slot in
Warden, the stray `* 2.php` files in the OCWOF child). Write the live cutover
checklist: add `warden_licence_key` to the live Brand Relay, remove the two old
params, trigger a sync, then check both themes under their tags. First find out
which Brand Relay is deployed: `main` and its tags stop at 0.13.0 while `develop`
is at 0.28.x, and the services below exist only from 0.18 up.

**2. Heartbeat (done in 1.23.0).** Site health reports, sent when Brand Relay's signed ping names `health`: Brand Relay is the clock,
because most sites get few visits and many have cron off. The plan is pulled before anything is sent. See
`inc/maintenance/AGENT.md`. Brand Relay 0.30.0 does its part (plans' "Trigger from Brand Relay"). WordPress's own Site Health results are in the report (see `inc/maintenance/AGENT.md`). Open: Brand Relay could read a `parent_theme` field; the slow network tests (loopback, background updates) could be a daily phase; the latest WordPress version and the outdated plugin and theme names are not sent yet.

**3. Error events (done in 1.24.0; warnings and notices added after).** Fatals and uncaught exceptions, then warnings, notices and deprecations
through a chained error handler that only counts, scrubbed and queued, sent when the ping names `events`, only while the plan has error tracking.
See `inc/maintenance/AGENT.md`. Open: failed scheduled jobs; warnings raised while a plugin is still loading are missed (the theme loads later).

**4. Backups (done, 1.26.0 to 1.30.6).** Option B, made through WPvivid Backup rather than our own engine: WPvivid is bundled like
WooCommerce, the theme runs its newer backup engine (the one its dashboard uses), uploads the merged file to Brand Relay in 50 MiB parts when the
ping names `backup`, reports `last_backup_at`, and removes its local copy. The Backups tab of the Licence page lists Brand Relay's backups and
downloads one back into WPvivid, where the client presses Restore. Clients cannot start a backup; only Brand Relay's ping does. The backup
includes WordPress core, and skips symlinked host folders (Jetpack, Akismet and the mu-plugins on WordPress.com). A restore onto a fresh site
was tried by the owner and works. See `inc/backups/AGENT.md`. Open: should a lapsed client still be able to download their own backups
(Brand Relay answers `403 feature_off` today, so that is a Brand Relay change); whether the Brand Relay site key lives in `wp-config.php`,
which a backup does not carry, so a restored site would need it entered again (not checked); a restored WordPress.com site has no usable
passwords, since its users sign in through WordPress.com, so the client needs a password reset after a restore (a note in the client guide).

**5. Warden follow-ups.** Decide whether a batch check (one request for both
tags, installed versions keyed by tag) is worth it; it saves one request per 12
hours. Move the children's kill switch from the status name `suspended` to
`valid: false`, since statuses now belong to each app. Lock whichever is chosen
in a parent test.

**6. The first real WooCommerce version update.** The bundled zip is already at 11.2.2. Bump the pin, build the zip,
release, and watch a site with the shop on take the new version at the end of
the request without a fatal error, while WordPress offers no update of its own.

**7. Watch upstream.** WordPress/ai is adding `core/content-create`,
`core/content-update` and `core/content-delete` (pull request 1025, open), and
core proposes `core/read-content` and `core/manage-content` for 7.1 and 7.2
or later. Neither covers custom fields, options pages or blocks, so our
abilities stay. Revisit our post abilities if those ship with meta support.

**8. SEO through The SEO Framework (released: 1.37.0).** The theme's own SEO code is gone and The SEO Framework
(TSF, `autodescription`, free, no premium version, GPLv3) is vendored like Secure Custom Fields and loaded by
`inc/dependencies/tsf/tsf.php`. It was chosen over a bundled plugin zip so it stays under the same test-then-release control as SCF and
ASE and a client site never has a second copy to install. Free means no licence key and no activation; its Extension Manager and
premium extensions (Local, Articles, Focus, Cord, Monitor, Honeypot) are deliberately not used. What remains of ours: the
**Organization Details** page (name, URL, logo, profile links, description, slogan, founding date, expertise, locations: merged into TSF's Organization entity, with the first four replacing TSF's own settings when filled)
and each archive's **SEO** tab (handed to TSF through its custom title and description filters). No database change: the old
values keep working, read by field key. TSF's branding and promotion are switched off from the theme (`devbase/tsf/hide_branding`). Left to do before release: measure page weight and queries with Query Monitor, and try a site that
still has SEOPress active (TSF shows its own conflict notice).
Known risks: one maintainer and bursty releases (a 10-month gap before 5.2.0). The loader depends on TSF internals (its
`plugins_loaded` start-up callback and its asset URL constant) and our hooks on its filters, none of which are a promised
API: `tests/Test_TSF_Loader.php` fails on the Dependabot pull request when a release moves any of them. If upstream is
abandoned or a fix is not shipped, `vendor/autodescription/` is already a full copy to fork from.
Not covered, as TSF's free version has none: Article, Product, FAQ, Review, Event and LocalBusiness schema, focus keywords,
a Google News sitemap, and SEO fields over REST (MCP cannot set a page's SEO title; it can set an archive's).

## Reliability audit (November 2026)

A read of the theme's own code for what could fail on a client site without anyone noticing. Ten findings; nine are fixed and
were released in 1.39.0, each with tests; the tenth was left as it is on purpose. The reasoning for each is in the `AGENT.md` of its folder.

| # | Finding | Status | Where |
| --- | --- | --- | --- |
| 1 | The release gate tested only on the developer's PHP (8.5); the theme promises 8.2, and CI ran after Warden had published | Fixed: the branch is pushed, CI on that commit must be green, then the tag. First used to release 1.39.0 | `bin/release.sh`, `tests/Test_Release_Script.php` |
| 2 | Theme auto-updates, the retention purge, backup retries and the credit refresh depended on WP-Cron | Fixed: every verified ping starts `wp-cron.php`; the purge runs past one batch | `inc/maintenance/maintenance.php`, `inc/forms/retention.php`, `tests/Test_Ping_Housekeeping.php` |
| 3 | A backup that stalled was never cleaned up: its archive stayed on disk, Brand Relay was never told | Fixed: asking again moves a waiting job on; a stalled one is failed and cleaned before the next starts | `inc/backups/backups.php`, `tests/Test_Backups.php` |
| 4 | Form notifications were sent inside the visitor's request (SMTP waited 300 s), and a failure was never retried | Fixed: 15 s timeout, sent after the answer on PHP-FPM, three tries per destination | `inc/forms/dispatch/orchestrator.php`, `inc/theme-settings/smtp/mailer.php`, `tests/Test_Form_Dispatch_Retry.php` |
| 5 | A newer bundled plugin waited for an administrator to open wp-admin | Fixed: a verified ping applies it | `inc/maintenance/maintenance.php`, `tests/Test_Ping_Housekeeping.php` |
| 6 | MCP could write the Tracking page's scripts, printed on every page | Fixed: the page and its fields are out of MCP's reach | `inc/abilities/helpers.php`, `tests/Test_Security_Hardening.php` |
| 7 | The honeypot was named `website`, which browsers fill in; a catch is silent | Fixed: renamed, and catches are counted on System Status | `inc/helpers/forms/submit.php`, `tests/Test_Form_Submit.php` |
| 8 | A plan expiry that could not be read counted as "never expires" | Fixed: milliseconds are understood; unreadable switches the service off and is recorded | `inc/maintenance/plan.php`, `tests/Test_Ping_Housekeeping.php` |
| 9 | Nothing tested the WPvivid internals the backup engine calls | Fixed: a contract test reads the bundled zip | `tests/Test_WPvivid_Engine_Contract.php` |
| 10 | `inc/updates/pinned-keys.php` is empty, so a Brand Relay account decides which signing key every site trusts | **Left as it is** (owner's decision, 2026-11-09): the key stays provisioned by Brand Relay and is not pinned in code. Not to be reopened without the owner asking | `inc/updates/pinned-keys.php` |

**Still to do for the fixed ones.** Find out which format Brand Relay sends the plan's `starts_at` and
`expires_at` in (finding 8 now handles all three, but nobody has looked). Tell the OCWOF child's author about the honeypot's
new name if that child prints its own field (the starter child does not).

**Suggested by the same audit, not built** (each needs its own decision):

- **A health check after a theme update, with rollback.** Load the home page after an update and restore the previous version
  on a fatal error. WordPress does this for nothing of a theme's.
- **A staged rollout.** Each site waits a random few hours before installing a release, so a bad one can be withdrawn in
  Warden before most sites have it.
- **Mail failures on System Status and in the health report.** Today only the "Send Test Email" button records anything;
  `wp_mail_failed` for real mail is not kept.
- **Reply-To on form notifications**, set to the submitter's address, and a **Resend** button on a submission.
- **Tracking only where it should run**: not on a non-production environment, not for a logged-in editor, and a filter a
  consent plugin can use.
- **An audit log for MCP writes**: what was changed, through which application password, with an option's previous value.
- **WordPress's personal data export and erasure** for form submissions.
- **Static analysis** (PHPStan) in the release gate.
- **Free disk space checked before a backup starts** (it is checked before a download).

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
