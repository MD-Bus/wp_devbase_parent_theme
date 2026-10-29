# inc/updates/ — delivering theme updates from Warden

How this theme, and the client child theme sitting on top of it, keep
themselves current on a live client site.

```
inc/updates/
  AGENT.md            — this file
  warden.php             — the signed Warden API client
  theme-updates.php        — wiring Warden's answer into WordPress's own
                               theme-update pipeline
```

Settings (the Warden URL, the signing key, one licence key per theme) live
on **Theme Settings > Licence** — `inc/theme-settings/licence/AGENT.md`.
This folder only reads them.

## What Warden is

[Warden](https://github.com/momardia54/Warden) is our own self-hosted
licensing and update-delivery service — a Cloudflare Worker with D1 and R2
behind it, plus a dashboard for issuing licences and publishing releases.
It is not a third-party service and not a WordPress-specific one.

Two of its endpoints matter to a client site; neither needs a credential
beyond the licence key itself:

```
GET {base}/check/{licence key}?domain={host}&version={installed}&nonce={random}
GET {base}/download/{licence key}/{file id}?domain={host}
```

### The shape of the answer (and a bug it caused)

`/check` returns the release **nested**: `update.available` (is it newer than
the version the site sent) and the release under `update.latest`
(`version`, `download_url`, `sha256`, `signature`, ...). That is Warden's
documented contract (its `guide/releases.md`).

`devbase_warden_pending_update()` once read `update.version` and
`update.download_url` at the top level, which Warden never sends. A real
release was therefore reported as "Up to date" and never offered, on both the
Status tab and WordPress's own update list. It now reads `available` +
`latest`. Keep it that way: if Warden's shape ever changes, change this one
function and its tests (`tests/Test_Warden_Updates.php`), not the callers.

A site running the broken parent can never see the fixed parent, because it
cannot see updates at all: install the fixed `inc/updates/warden.php` by hand
once, after which updates flow normally.

The management API (creating and renewing licences with a
`Authorization: Bearer wk_...` key) is deliberately **not** implemented
here. A client site must never hold a credential capable of minting
licences, so the only thing this theme can do is ask about its own.

## `warden.php`

One real function — `devbase_warden_check( $track, $licence, $version )` —
returning Warden's decoded response, or the last known good one.

Three of Warden's documented client rules are implemented here rather than
left to callers, because getting any of them wrong is a security or
availability bug rather than a style preference:

**1. The Ed25519 signature is verified before the body is trusted at all.**
Every response carries a detached signature over the raw body in the
`X-Warden-Signature-Ed25519` header, checked against the public key from
the Licence page via `sodium_crypto_sign_verify_detached()`. An
unverifiable response is treated as a *transport error*, never as a
verdict. This is what stops a hostile network from answering on Warden's
behalf — and the dangerous direction isn't a forged "your licence is
invalid", it's a forged `update.download_url` pointing at an attacker's
zip, which WordPress would then install as the active theme.

If libsodium is somehow unavailable, verification **fails** rather than
being skipped. "We couldn't check" has to behave exactly like "the check
failed", or the guarantee is worth nothing on precisely the hosts where it
matters.

**2. The nonce is echoed, and the licence key is matched.** Each request
carries a fresh random nonce; a response that doesn't return the same one,
or that is about a different `license_key`, is rejected. Without this a
single captured response could be replayed forever — including one
carrying an old `update` block.

**3. Failure is soft, never closed — but bounded.** On a timeout, a 5xx, a
parse failure or a signature failure, the **last known good response** is
returned and the error is recorded separately for the Status tab. A Warden
outage must never make a valid licence look invalid or lock a client out
of their own site.

Unbounded, though, "soft" becomes "forever", and that was a real hole —
found by testing the scenario rather than reasoning about it:

1. A licence is valid and its response carries an update offer.
2. The licence is then **deleted** from Warden, so `/check/` starts
   returning an unsigned 404.
3. An unsigned non-200 is a transport error, not a verdict — correctly,
   since an unauthenticated response must never be able to revoke a
   licence. So the cached response stands…
4. …and kept offering version 99.0.0 **indefinitely**. Confirmed: it did
   exactly that before the fix.

Never site-breaking (Warden would reject the download, so `Theme_Upgrader`
simply fails and the site keeps running on its current version), but
WordPress would show a permanent update that can never install, retried
daily by auto-update, for a licence that no longer exists.

`devbase_warden_offer_window()` closes it: a cached response stops
authorising **installs** once the last *successful* check is older than
`DEVBASE_WARDEN_OFFER_WINDOW` (7 days, filterable via
`devbase/updates/offer_window`). Inside the window, fail-soft applies
fully — a brief outage can't cut a paying client off. The cached response
is still returned for *display* either way, so the Status tab keeps
showing the last known licence state next to the error, which beats a
blank.

Note that an **expired or suspended** licence never depends on this window:
Warden answers those with a signed 200 carrying `valid: false`, which
`devbase_warden_pending_update()` rejects outright. The window covers only
the case where Warden stops answering about a licence at all.

### The domain is as load-bearing as the key

Warden identifies an *installation*, not just a licence — `domain` is a
**required** query param, and a licence carries a domain allow-list and a
site-count limit. A key alone doesn't tell Warden where it's being used.

It is **not** a settings field, deliberately. `devbase_warden_domain()`
derives it from `wp_parse_url( home_url(), PHP_URL_HOST )` on every
request, so it is always whatever the site actually is. A field would be
one more thing to fill in, to get wrong, and to go stale after a domain
change or a staging clone — and WordPress already knows the answer.

`home_url()` rather than `site_url()`: the public address the site is
served on, which is what a licence's allow-list is written against.
(They're the same here; they differ on a WordPress-in-a-subdirectory
install, where `site_url()` points at the WordPress files instead.)

Operational consequences worth knowing before issuing licences:

- **www vs non-www matters.** Whatever is in Settings > General is what
  gets sent. Warden does subdomain matching, but `example.com` and
  `www.example.com` need to both resolve against the allow-list you set.
- **A staging clone checks in as its own site.** `staging.client.com`
  sends its own domain and, against a site-count-limited licence, consumes
  an activation. Either add the staging domain to that licence, give
  staging its own, or simply leave the licence field empty there — an
  empty key means the track is skipped entirely, which is usually what you
  want on staging.
- **Local dev too.** This dev site sends `dev-blueprint.local`, which no
  real licence will have allow-listed. That resolves to a failed check and
  fail-soft, not a broken site — which is part of why fail-soft matters.

**The download URL is passed through verbatim.** Warden's `update.download_url`
goes straight into the transient's `package` with no rewriting, so
whatever Warden puts there (including the `?domain=` the documented
`/download/{key}/{file}` endpoint takes) is what `Theme_Upgrader` fetches.
Not verified against a real deployment yet — if a download is ever
rejected while the check succeeds, this is the first thing to look at.

**Cadence**: one real request per 12 hours per track
(`DEVBASE_WARDEN_CACHE_TTL`), which is what Warden's own docs ask for.
That matters because `pre_set_site_transient_update_themes` can fire
several times in a single request — without the cache, one admin page load
could mean several round-trips.

`devbase_warden_pending_update()` re-checks the offered version against
what's installed with `version_compare()`, even though Warden already
decided whether to include an `update` block. A release track
misconfigured to a lower version must never produce a *downgrade* that
WordPress would dutifully install.

## `theme-updates.php`

Hooks `pre_set_site_transient_update_themes` and writes Warden's answer
into the transient WordPress already uses for theme updates. That's all.
**WordPress does the downloading, extracting, swapping and rolling back.**

### Why core's pipeline, and not this theme's own swap code

`inc/dependencies/dependency-updater.php` already has a perfectly good
staged, rename-based swap with a rollback path — but it is built for
`vendor/<slug>/` subfolders. Reusing it for a *theme* would mean:

- renaming the directory of the theme that is currently executing, and
- reimplementing maintenance mode, filesystem credentials (hosts that need
  FTP rather than direct writes), and failure rollback —

all of which `Theme_Upgrader` already does, including core's own
rollback-on-failure since WP 6.3.

Everything downstream then comes free: the update notice on Appearance >
Themes, the Dashboard > Updates screen, `wp theme update`, and genuine
unattended auto-updates on core's twice-daily cron. That last one is the
actual goal — a client site staying current with nobody remembering to log
in.

### Two tracks, detected rather than declared

There are exactly two themes in this model and WordPress already knows
which is which: `get_template()` is the parent, `get_stylesheet()` is the
child (they're the same value when no child is active). So
`devbase_update_tracks()` works these out itself — neither theme
registers, and a child physically cannot misregister itself. The
`devbase/updates/tracks` filter exists for a non-standard setup, but
nothing in either theme uses it.

A track whose licence key is empty is skipped entirely. An unlicensed or
not-yet-configured site simply never sees an update offer, rather than
erroring or nagging.

### The `no_update` entry is not optional

When there's nothing to install, the theme is still added to
`$transient->no_update`. That's what makes WordPress render the
**auto-update toggle** for it on Appearance > Themes at all — a theme
absent from both `response` and `no_update` is treated as unmanaged and
gets no toggle, which would make the Licence page's own auto-update
setting look broken.

### Auto-updates default to ON, with an escape hatch

`auto_update_theme` returns the Licence page's setting for our two themes
and passes `$update` through untouched for every other theme, so this
never changes auto-update behaviour for anything but ours. The setting
defaults to **on** — the point of the system is sites that maintain
themselves — but it's a field rather than hardcoded for one specific
reason: a theme updating underneath you *while you're building the site*
is genuinely disruptive, and turning that off for one site shouldn't need
a code change.

## Forcing a check by hand

Theme Settings > Licence > Status has a **Check for Updates Now** button
that runs everything immediately — both dependency checks and a Warden
check per track, the last with `$force = true` so it bypasses the 12-hour
response cache. It calls the same functions cron does, and it also clears
core's `update_themes` transient so a newly-found update actually appears
on Appearance > Themes rather than waiting for core's own next check.

Nothing on a client site needs it; it's for verifying a licence or a
just-published release without waiting out the schedule. See
`../theme-settings/licence/AGENT.md`.

## What is NOT gated or approved here

Nothing. There is no allow-list, no per-version approval step, no second
opinion. The only thing deciding what a site installs is **which release
is published on that track in Warden** — the dashboard is the gate.

This is a deliberate simplification of what came before: SCF/ASE updates
used to be filtered through a curated approval list served by a separate
Cloudflare Worker (`devbase-dependency-worker`), which has been removed
entirely — see `../dependencies/AGENT.md`. One place to decide what ships
beats two.

## One track for the whole base theme — considered alternatives, and why not

Per-folder Warden tracks were seriously considered: `inc/` with its own
updater, `languages/` with its own, `views/` with its own, and possibly
`vendor/timber/`, `vendor/twig/` and `vendor/symfony/` each with theirs.
Rejected, for two reasons — the first of which is decisive and not a
matter of preference.

**1. A theme update deletes the entire theme directory.**
`Theme_Upgrader::upgrade()` registers `delete_old_theme()` on
`upgrader_clear_destination`, which does
`$wp_filesystem->delete( $themes_dir . $theme, true )` — a recursive
delete of the whole folder — before installing the new copy. WordPress's
update granularity is a *theme* or a *plugin*, never a subdirectory of
one. So a folder inside this theme cannot be independently updated by
WordPress at all, and cannot survive a theme update however it is tracked.
Per-folder tracks would have needed an entirely bespoke updater per
folder, outside WordPress's pipeline, with no admin UI — and they still
would not have prevented the overwrite, because the directory's *location*
is what determines that, not how many tracks point at it.

**2. More tracks would have ADDED skew risk, not removed it.** The
motivation was "stop one directory's update moving another by mistake" —
but one theme means one artifact, one version, applied atomically, which
is precisely the configuration where accidental skew is impossible.
Splitting into N independently-versioned pieces creates the opposite
problem, and Warden is a per-licence version check, not a dependency
resolver. Concretely: `vendor/timber/timber/composer.json` requires
`twig/twig: ^3.27`. Composer enforces that today. On separate tracks
nothing would stop a site landing on Twig 4.0 alongside Timber 2.5 —
broken, with no mechanism anywhere to catch it. (The autoloader itself
would mostly cope: `Twig\` and `Timber\` are PSR-4 directory mappings
with an essentially empty classmap, so a folder swap resolves lazily. The
fragility is the unenforced version constraint, not class loading.)

**The decision: one Warden track for the whole base theme.** Timber, Twig,
the Symfony polyfills, Composer's autoloader and all of this theme's own
code move together, occasionally, in one release. The vendored *plugins*
(SCF, ASE, MCP Adapter) keep updating themselves daily on top of that, as
documented in `../dependencies/AGENT.md`.

### The accepted tradeoff, and why it's genuinely small

A release built from a stale baseline can push SCF/ASE/MCP Adapter
*backwards* on sites whose own daily check had already moved them forward.
That is accepted deliberately rather than engineered around, because those
three recover on their own: the daily WP-Cron check fires on any request
(verified — a single anonymous front-end `GET /` both scheduled and
executed them), so a site is back on current within 24 hours.

`bin/build-zip.sh`'s version-drift gate prevents it happening by accident
anyway, by refusing to build while `vendor/` is ahead of `composer.lock`.
Belt and braces: the gate stops it, and the daily check would undo it.

**Timber, Twig and the Symfony polyfills have no such recovery path.**
Nothing on a live site will ever move them — a
release is the only mechanism. That makes the release cadence a real
security dependency for those four, not just a convenience, which is why
`bin/AGENT.md`'s runbook puts `composer update timber/timber` and
`composer audit` in the release steps rather than leaving them to memory.

## The interaction worth knowing about: a parent update overwrites `vendor/`

The parent theme's zip carries `vendor/` (SCF, ASE, MCP Adapter), and a
theme update replaces the whole theme directory. So **every parent theme
update reverts those three to whatever versions that release was built
from**, discarding any newer copy a site's own daily dependency check had
already installed.

It is self-healing — the next daily check moves them forward again — but
for up to a day the site runs what the zip shipped, which could be an
older release with a known issue. The mitigation is procedural, and it's
written into `bin/build-zip.sh` as a release-time warning: refresh the
committed `vendor/` baseline before cutting a parent release. See
`../dependencies/AGENT.md`'s "committed to git" section.

## Verified

`tests/Test_Warden_Updates.php` — 22 tests. These use a **real Ed25519
keypair generated with libsodium** and sign real response bodies with it,
rather than stubbing signature verification out. That was deliberate: the
signature check is the only thing between this theme and a hostile network
handing it a download URL, so a test that mocked it would assert nothing
about the property that actually matters. Each rejection path is exercised
by returning a genuinely wrong value — signed with the wrong key, echoing
the wrong nonce, naming a different licence key, carrying no signature at
all — and asserting the response is refused and the reason recorded.

Also covered: the documented URL shape and nonce length bounds, a fresh
nonce per request, fail-soft on both a timeout and a 503, the 12-hour
cache suppressing a second request, downgrade refusal, no offer on an
invalid licence, transient injection, the `no_update` entry, and that
auto-update behaviour for unrelated themes passes through unchanged.

**Every licence failure mode** has its own test, because "does this break
the site" is the question that actually matters: expired, suspended, a key
Warden has never heard of, and the deleted-licence case above (asserting
both halves — still offered inside the window, no longer offered past it).
Plus `test_a_failed_check_never_fatals_the_update_transient`, which drives
a timeout, a 500, an unsigned 404 and a non-JSON 200 through
`pre_set_site_transient_update_themes` and asserts each one still returns
a usable object with the theme listed under `no_update` — because
WordPress stores and reads that transient on every admin page load, so a
fatal there would break wp-admin rather than just the update screen.

Confirmed live on this dev site with the child theme really activated: both
themes are detected as tracks, the Licence page registers with all its
fields, and the Status tab renders both rows correctly (reporting "no
licence key entered", which is the true state here — this dev site has no
Warden deployment configured, so the signed round-trip against a real
Warden Worker has NOT been exercised end to end yet and should be before
relying on it for a client's go-live).

## Where to go from here

- **The Licence settings sub-page these functions read:** `../theme-settings/licence/AGENT.md`
- **The vendored dependency updater (separate system, same daily-check shape):** `../dependencies/AGENT.md`
- **Packaging the artifact Warden serves:** `../../bin/AGENT.md`
- **Back up to how the theme boots overall:** `../AGENT.md`


## `relay-sync.php` — provisioning from Brand Relay (1.1.0)

Pulls `warden_base_url`, `warden_public_key`, `warden_parent_licence` and `warden_child_licence`
from Brand Relay's `GET /v1/site` (Bearer = the site key) into the Licence options. Param names equal
field names on purpose. Warden stays the authority; this file only copies values.

- **Why:** a licence typed by hand can be removed by the site's users, which cuts us off from fixing
  it remotely. Here the value lives in Brand Relay and is restored on the next sync.
- **Runs only when asked:** a signed ping, an admin saving the Licence page, or "Check for Updates
  Now". There is deliberately no WP-Cron schedule; the init hook only removes the old event.
- **Fill-only:** the Warden URL and public key are accepted when empty and never overwritten (they
  decide where code is downloaded from and which signature is trusted). Licence keys always update.
- **Empty or missing param = no change.** Revoking is done in Warden.
- **A changed key** drops that track's `devbase_warden_state_*` and calls `wp_update_themes()`, so the
  new key is checked immediately.
- **The ping** (`POST /wp-json/devbase/v1/sync`): order is secret present (else 404), per-IP throttle
  10/min (429), `Origin` == Brand Relay origin, timestamp window, HMAC over `ts.nonce.origin.body`,
  single-use nonce, then a sync limited to one per 30 s. The secret is `HMAC("devbase-relay-ping", site key)`.
  Origin is a claim a non-browser caller can write; the signature is what proves the caller. It stops
  browsers and mis-pointed pingers. An Ed25519 signature from Brand Relay would be the stronger option.
- **Root of the chain:** the Brand Relay URL and site key cannot be restored by a sync. To keep users
  from clearing them, define `DEVBASE_RELAY_URL` / `DEVBASE_RELAY_SITE_KEY` in code; the fields then lock.
- **Gotcha:** `acf/prepare_field` runs AFTER `acf_prepare_field()` has replaced `$field['name']` with the
  field key, so filters must match `$field['key']`. A name check silently never matches.
- **Tests:** `tests/Test_Relay_Sync.php` fakes only Brand Relay's URLs; anything else (wordpress.org,
  Warden) gets a canned answer, because a sync ends in `wp_update_themes()`.
