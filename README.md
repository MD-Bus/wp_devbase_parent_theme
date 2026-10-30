# Dev Base Parent Theme

The parent WordPress theme every client site is built on. It is
not a site in itself — it carries the functionality every project needs,
and each client runs a **child theme** on top of it holding only that
site's design and specifics.

Timber/Twig for templating, Secure Custom Fields for custom fields, Admin
Site Enhancements for admin cleanup and hardening — all vendored, none
installed as WordPress plugins.

![Theme Settings — System Status](docs/screenshots/system-status.png)

---

## Why it exists

Agency WordPress work repeats the same problems on every project: routing
mail through real SMTP, gating forms behind a CAPTCHA, logging submissions
somewhere an admin can read them, emitting valid schema.org markup,
wiring up analytics, keeping dependencies patched. Rebuilding that each
time is waste; copying it between projects means every fix lives in one
site and dies there.

This theme is that work, written once, delivered as a parent theme so a
fix reaches every client site through a single release.

## What's in it

| Area | What it does |
| --- | --- |
| **Forms** | Two CPTs (`registered_form`, `form_data`), a JSON registry, automatic submission logging and dispatch to email/webhook destinations. A form author calls three functions and gets CSRF, CAPTCHA gating, logging and notifications. |
| **SMTP** | Real SMTP transport with a "Send Test Email" button that surfaces the actual PHPMailer error, not a generic failure. |
| **Security** | Cloudflare Turnstile or Google reCAPTCHA v2, mutually exclusive and enforced server-side. Forms call one function and never learn which is active. |
| **Tracking** | GTM/GA4 IDs plus raw header/footer script fields. |
| **Schema** | Organization, Website and Breadcrumb JSON-LD, each independently toggleable. |
| **Archives** | Custom fields on a CPT archive (which ACF has no location rule for), an **SEO** tab on each archive that sets its search title and description, and CPT archives in the internal link picker. |
| **MCP** | Content abilities over the WordPress MCP Adapter — list/get/create/update posts and options values, generic across every post type, behind an opt-in allow-list. |
| **Updates** | Vendored dependencies track upstream on a daily schedule; both themes update themselves from [Warden](https://github.com/momardia54/Warden), a self-hosted licensing and release service. |

## Installing

1. Install this theme into `wp-content/themes/`. **Do not rename the
   directory** — a child theme binds to it by exact name.
2. Install a child theme and activate **that**, not this one. The
   [Dev Base Child Theme](../wp_devbase_child_theme) is the
   starter to copy.
3. Fill in Theme Settings as the site needs.

WordPress refuses to activate a child whose parent is missing, so a wrong
install order produces a clear message rather than a broken site.

### Requirements

- WordPress 7.1+ (the Abilities API the MCP features build on)
- PHP 8.2+ (Timber 2.x's own requirement)

## Settings

Everything lives under **Theme Settings** in wp-admin. Every field is
registered in code, so it is identical on every site running this theme.

### Licence & updates

![Licence settings](docs/screenshots/licence-connection.png)

The endpoint, signing key and one licence key per theme. These four fields
are **provisioned automatically and cannot be edited here**; contact the
developer for any licence issue. Leave a key empty and that theme simply
never checks for updates.

The only two fields to fill in are the **Brand Relay URL** and **Brand Relay
Site Key** (the *Brand Relay* tab). Saving them pulls everything else straight
away; see
[Provisioning from Brand Relay](#provisioning-from-brand-relay).

![Licence status](docs/screenshots/licence-status.png)

The Status tab answers "is this site actually receiving updates" — a
question that otherwise has half a dozen indistinguishable failure modes.
**Check for Updates Now** forces every check immediately rather than
waiting out the daily schedule.

### SMTP

![SMTP settings](docs/screenshots/smtp-settings.png)

Connection fields stay hidden until the feature is switched on, and are
only required while it is — ACF skips validation for conditionally hidden
fields, so "required when enabled" needs no custom JavaScript.

### Security

![Security settings](docs/screenshots/security-settings.png)

Turnstile and reCAPTCHA on separate tabs. Only one may be active; a
server-side `acf/validate_save_post` handler enforces that, with the
JavaScript toggle being a convenience that proves nothing.

**reCAPTCHA is v3 only**: invisible, score-based, with no checkbox. Use a
*score-based (v3)* key and add the site's domain to it in Google. A checkbox
(v2) or Enterprise key is the wrong type, and Google answers "ERROR for site
owner: Invalid key type". How it works:

- A form that prints `devbase_security_widget_html()` gets a hidden
  `g-recaptcha-response` field. `assets/js/recaptcha-v3.js` is the only script the
  page loads; it loads Google's (`api.js?render=<site key>`) **the first time a form
  is touched**, not with the page, and keeps a fresh one-use token in that field, so a
  normal post and an AJAX `FormData` both carry it. A submit before the token has
  arrived is held, the token fetched, and the submit sent again. Tokens last 2
  minutes, so they are refreshed every 90 seconds, when the tab comes back to the
  front, and after each submit.
- The server (`devbase_security_verify()`) accepts a token only when Google says
  it is genuine, it was made for the expected action (`submit`, filterable with
  `devbase/security/recaptcha_action`), and its score is at least the **Minimum
  Score** setting. Google scores a visitor from 0.0 (most likely a bot) to 1.0
  (most likely human); the default minimum is 0.5. An answer with no score is
  not a v3 answer and fails.
- Google's floating reCAPTCHA badge shows on pages with a form, as Google
  requires.

Upgrading from a version that used v2: a v2 key stops working here. Create a v3
key and enter it on the reCAPTCHA tab, or switch to Turnstile.

### Archive SEO

A post type archive (News, Events, ...) has no post, so an SEO plugin has nowhere to put
its title and description. Each archive's **Archive** options page now has an **SEO** tab
with two fields, **Search Title** and **Search Description**, and the theme prints them on
the archive's own URL. If a group of fields already targets that page (a child theme's
Banner, List and Search groups), the tab is added to it; otherwise the same two fields get a
small "SEO" box of their own.

- **Only when filled.** An empty field leaves the SEO plugin's, or WordPress's, default.
- **Title.** Set as the document title; on page 2 and after, " – Page N" is added, so the pages of a
  list do not share one title. Length is limited to 70 characters; aim for 50 to 60.
- **Description.** First page of the list only. `wp_head` is buffered on archive requests and an
  existing `<meta name="description">` is replaced (or one is added if there is none), so there is never a
  second tag whether or not an SEO plugin is active. Open Graph and Twitter title and description tags
  that something has already printed are updated to match; none are added. Limited to 170 characters;
  aim for 120 to 155.
- **MCP.** The fields are `seo_title` and `seo_description` on the page `archive-{post_type}`, so
  `devbase/update-options` can set them.
- `devbase/archive_seo/enabled` (`bool`, post type) switches it off for a type.

### Tracking

![Tracking settings](docs/screenshots/tracking-settings.png)

### Schema markup

![Schema settings](docs/screenshots/schema-settings.png)

Three types, deliberately — the ones nearly every site needs and that are
cheap to build well. Anything industry-specific belongs in that project's
child theme.

### Form destinations

![Form destinations](docs/screenshots/form-destinations.png)

One repeater where each row is a self-contained destination (email or
webhook) plus the forms it applies to.

### MCP access

![MCP access](docs/screenshots/mcp-access.png)

**Opt-in, always.** Nothing is readable or writable through MCP until an
admin explicitly ticks it here. A newly registered post type starts fully
locked down.

### Credit

**Theme Settings > Miscellaneous > Credit** takes one setting, the brand's
public address in [Brand Relay](https://github.com/momardia54/brand-relay), for example
`https://relay.example.com/v1/brand/yourbrand`. The theme downloads the brand's
public details, keeps a local copy, and builds the credit ("Designed by
*Brand*") from that copy.

- **When it downloads:** when the setting is saved, shortly after it is first
  set, and then once a day through WP-Cron. Brand Relay's own `ttl` is
  respected as a minimum gap between automatic downloads (never less than 15
  minutes, never more than a day). Clearing the address stops it.
- **Nothing is shown in the admin about it.** The tab has the one field and no
  status or preview.
- **Rendering never waits on the network.** Pages read the saved copy only. If
  Brand Relay is slow or down, nothing changes on the site.
- **Fails soft.** A failed or unexpected download keeps the last good copy and
  is written to the PHP error log. If there has never been a good copy, no
  credit is shown. A brand marked `show: false` shows none and replaces the old copy; an `unavailable` answer is treated as a failure and keeps the last good copy.
- **Only public data, and only named fields.** The address is fetched with no
  key. The saved copy is built from an allowlist (message, link text, link
  address, `rel`, brand name and address, logo address and alt text), each
  value validated and limited to `http(s)` addresses and sensible lengths. The
  response is never stored whole, so `params`, `rules`, `embed` and anything
  Brand Relay adds later are dropped, and a private value can never end up in
  the copy. Remote HTML and CSS are never printed: the markup is built here
  from the plain fields, so a compromised Brand Relay account can't inject
  markup into client sites.
- **A changed address drops the old copy** immediately; the credit of the brand
  you moved away from is never shown for the new one.

**Keeping it out of a client's admin.** Define the address in code instead of
typing it, in `wp-config.php` or the child theme:

```php
define( 'DEVBASE_CREDIT_BRAND_URL', 'https://relay.example.com/v1/brand/yourbrand' );
```

The constant wins over the field, and while it is defined the whole Credit tab
is removed from Theme Settings > Miscellaneous. The credit still downloads and
still shows on the site; only its settings are hidden. (`devbase/credit/hide_settings`
overrides the hiding.)

In a template:

```php
<?= devbase_credit_html() ?>   // 'Designed by <a href="…">Brand</a>', escaped; '' when none
```

```php
$credit = devbase_credit();    // array|null, from the saved copy only
// [ 'message' => 'Designed by',
//   'link'    => [ 'text', 'url', 'rel', 'follow' ],
//   'brand'   => [ 'name', 'url' ],
//   'logo'    => [ 'url', 'alt' ] | null,
//   'fetched_at' => 1767225600 ]
```

`devbase_credit_html()` prints the same markup as Brand Relay's own reference
snippets: the message, a space, then a plain direct link. A followed link has no
`rel`; an unfollowed one carries `rel="nofollow"`. There is no `target`, so
opening in a new tab is left to the theme. The `devbase/credit` filter can adjust the array.

It deliberately does **not** print Brand Relay's `embed` HTML/CSS, which the
reference snippets do; see the allowlist note above for why.
The site's language is sent as `?lang=` so a multilingual brand answers in it.

## The front end

This theme ships exactly one template — a branded placeholder, so a fresh
install shows something intentional rather than a blank page.

![Front end placeholder](docs/screenshots/front-end.png)

Real templates are the child theme's job, since they are the part every
project replaces. Timber resolves templates child-first, so a child's
`views/index.twig` takes over automatically.

## Extending it from a child theme

A child theme adds what it needs and inherits the rest. It never copies or
forks parent code.

```php
// Keep the blog on this client's site.
add_filter( 'devbase/admin_menu/hidden_items', function ( $items ) {
    return array_diff( $items, array( 'edit.php', 'edit-comments.php' ) );
} );

// This client's forms.
add_filter( 'devbase/forms/registered_forms', function ( $forms ) {
    $forms[] = array( 'name' => 'contact_form', 'label' => 'Contact Form' );
    return $forms;
} );
```

| Filter | Purpose |
| --- | --- |
| `devbase/ase/defaults` | Adjust an admin-hardening default. Fills gaps only — a value the site has already saved wins. |
| `devbase/ase/enforced` | Adjust it authoritatively. Reasserted on every admin page load. |
| `devbase/forms/registered_forms` | Register this site's forms. |
| `devbase/admin_menu/hidden_items` | Change which admin menu items are hidden. |
| `devbase/schema/tabs` | Contribute an extra schema.org type. |
| `devbase/updates/tracks` | Override update track detection (rarely needed). |
| `devbase/consent/stylesheet_url` | Reserved for consent integrations. |

**One rule.** WordPress loads a child's `functions.php` *before* the
parent's, so no parent function exists yet while it runs. Register filters
at the top level — they only store a callable. Never *call* a `devbase_*`
function there, and never guard one with `function_exists()`: it will be
false, the code will silently do nothing, and the cause is not obvious.

### Provisioning from Brand Relay

Licences are delivered by [Brand Relay](https://github.com/momardia54/brand-relay), so a licence can be
issued, replaced or fixed from a distance without opening the site, and a
removed or altered key is restored on the next sync. Warden remains the
authority: it still validates every key and binds it to the domain. Brand
Relay only carries the values.

**One name per value.** Each is a *site* param in Brand Relay (never a brand
param, which is public) named exactly like the field it fills:

| Param | Fills | Behaviour |
|---|---|---|
| `warden_parent_licence` | Base Theme Licence Key | always brought back in line |
| `warden_child_licence` | Child Theme Licence Key | always brought back in line |
| `warden_base_url` | Warden URL | **fill-only**: accepted when empty, never overwritten; `https` only |
| `warden_public_key` | Warden Public Key | **fill-only**: accepted when empty, never overwritten; must be a base64 Ed25519 key |

The address and signing key are fill-only because they decide where theme
code is downloaded from and which signature is trusted; they are not
changeable by whoever controls a Brand Relay account. An empty or missing
param means "no change", never "clear": revoke a licence in Warden.

**When it syncs:** only when asked. There is no schedule. A sync runs on a
ping (below), when an administrator saves the Licence page, and on **Check for
Updates Now**. A value changed or removed on the site is therefore restored by
the next ping, not before, so ping the site whenever a licence is changed in
Brand Relay. If Brand
Relay cannot be reached the current values stay, and the Status tab shows
the last error.

**Baking it in.** The Brand Relay URL and site key are what a sync needs, so a
sync cannot restore them. To keep a site's users from clearing them, set them
in code instead of the fields; a constant wins over its field, and the field
then shows locked:

```php
define( 'DEVBASE_RELAY_URL',      'https://relay.example.com' );
define( 'DEVBASE_RELAY_SITE_KEY', 'brs_...' );
```

in `wp-config.php` or the site's child theme. Anyone with file access can still
remove them; this stops the admin screen, not a determined developer.

#### The ping endpoint

```
POST /wp-json/devbase/v1/sync
X-Relay-Timestamp: 1767225600
X-Relay-Nonce:     7f3c9a1e5b2d4c8e
Origin:            https://relay.example.com
X-Relay-Signature: <hex>
```

A ping carries **no licence data**. It only tells the site to pull from the
configured Brand Relay, so a forged or replayed ping can cause one
rate-limited fetch and nothing else. The route exists only when both the
Brand Relay URL and the site key are set.

**Only the configured Brand Relay may ping.** The `Origin` header must equal
the Brand Relay URL's origin (`scheme://host[:port]`, compared without regard
to case or a trailing slash); a missing or different origin is refused with
`401`. The origin is part of the signed string, so a signature cannot be
reused under another origin. Note that a header is a claim a server-to-server
caller can write; the signature is what proves the caller holds the site key.
The origin rule stops a pinger set up for a different Brand Relay from being
accepted by mistake.

**Rate limits.** Two, because they protect different things. Every request to
the route counts against its IP, signed or not, at 10 a minute (adjustable with
the `devbase/relay/ping_limit` filter); this is checked first, so a flood of
forged requests is cut off before any signature work. Separately, a valid ping
can trigger at most one sync every 30 seconds, which protects the outbound call
to Brand Relay. Behind a proxy or CDN the IP is the proxy's, so the first limit
is shared by everything behind it.

- `X-Relay-Timestamp`: Unix seconds; refused if more than 5 minutes from the
  site's clock.
- `X-Relay-Nonce`: 16–64 characters of `A-Z a-z 0-9 _ -`, single use.
- `Origin`: the Brand Relay's origin, exactly as configured on the site.
- `X-Relay-Signature`: lowercase hex HMAC-SHA256 of
  `{timestamp}.{nonce}.{origin}.{body}` (the origin lowercased without a
  trailing slash; the raw request body, empty is fine) under the **ping secret**,
  which is `HMAC-SHA256("devbase-relay-ping", site key)` as hex. There is no
  second secret to provision: whoever holds the site key can derive it.

| Status | Meaning |
|---|---|
| `200` | Verified. Body `{ "ok": true, "error": "", "changed": ["warden_parent_licence"] }` (names only, never values). `ok: false` with an `error` when Brand Relay could not be read. |
| `401` | Wrong or missing `Origin`, bad signature, stale timestamp, malformed headers or a reused nonce. |
| `404` | Not wired to Brand Relay (no URL or site key). |
| `429` | Too many requests from this IP (more than 10 a minute, valid or not), or a sync already ran in the last 30 seconds. |

Signing, in PHP:

```php
$secret = hash_hmac( 'sha256', 'devbase-relay-ping', $site_key );
$ts     = (string) time();
$nonce  = bin2hex( random_bytes( 12 ) );
$origin = 'https://relay.example.com';
$sig    = hash_hmac( 'sha256', "$ts.$nonce.$origin.", $secret ); // empty body

// POST https://client-site.org/wp-json/devbase/v1/sync with the four headers.
```

Or from a shell:

```bash
SECRET=$(printf 'devbase-relay-ping' | openssl dgst -sha256 -hmac "$SITE_KEY" -hex | awk '{print $NF}')
TS=$(date +%s); NONCE=$(openssl rand -hex 12); ORIGIN=https://relay.example.com
SIG=$(printf '%s.%s.%s.' "$TS" "$NONCE" "$ORIGIN" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')
curl -X POST https://client-site.org/wp-json/devbase/v1/sync \
  -H "Origin: $ORIGIN" -H "X-Relay-Timestamp: $TS" -H "X-Relay-Nonce: $NONCE" -H "X-Relay-Signature: $SIG"
```

## Updates

Both themes keep themselves current, and so do the vendored dependencies —
on a client site nobody needs to do anything.

**Dependencies** (Secure Custom Fields, Admin Site Enhancements, MCP
Adapter) track their own upstreams on a daily WP-Cron event. That event
fires on any request, including front-end traffic, specifically so a site
whose owner never opens wp-admin still receives them.

**The themes themselves** are delivered by [Warden](https://github.com/momardia54/Warden) — a self-hosted
licensing and release service, and a separate project. Each theme has its
own release track and its own licence key, so the parent and a client's
child theme update independently.

The theme asks Warden what release is available for the version and domain
it reports, then hands the answer to WordPress's own `Theme_Upgrader`,
which does the downloading, swapping and rollback. That means the update
notice, the Dashboard → Updates screen, `wp theme update` and unattended
auto-updates all work exactly as they do for any other theme.

Three properties are worth knowing, since they decide how this behaves
when something goes wrong:

- **Every response is verified** with an Ed25519 signature over the raw
  body before any of it is trusted, and carries a nonce the response must
  echo. A response that fails either check is a transport error, never a
  verdict — it matters most because that response carries a download URL
  WordPress would install as the active theme.
- **Failure is soft.** On an outage the last verified answer stands, so a
  service problem cannot lock a site out or make a valid licence look
  invalid.
- **But bounded.** That cached answer stops authorising installs after
  seven days, so a response that can no longer be re-verified cannot keep
  approving updates indefinitely.

No licence key means no update checks for that theme — which is the right
state for a staging clone or a local copy.

## Versioning

The `Version:` header in `style.css` is the single source of truth.
WordPress reads it, the updater reports it, and [Warden](https://github.com/momardia54/Warden) compares
against it to decide whether a release applies.

```bash
./bin/bump-version.sh patch    # 1.0.0 -> 1.0.1
./bin/bump-version.sh minor    # 1.0.0 -> 1.1.0
./bin/bump-version.sh 2.0.0    # explicit
```

A release built without bumping it reaches nobody — every site reports the
version being offered and correctly concludes there is nothing to do. The
script refuses to move the version backwards for the same reason.

## Releasing

A release is a pushed `vX.Y.Z` tag: Warden publishes GitHub's archive of
it to every site on the theme's track. Cut one only with:

```bash
./bin/bump-version.sh patch    # then add the CHANGELOG section, commit
./bin/release.sh --dry-run     # every check, nothing tagged
./bin/release.sh               # checks, asks, then tags and pushes
```

It refuses unless the tree is clean and on `main`, `style.css` carries a
version newer than every existing tag, `CHANGELOG.md` has a dated, non-empty
section for it directly under an empty `[Unreleased]`, the branch is not
behind `origin`, `vendor/` matches `composer.lock`, and the test suite
passes. The branch and the annotated tag are then pushed in one atomic
push.

## Packaging a zip

```bash
./bin/build-zip.sh
```

Two gates run before a zip is produced:

1. **Version drift** — the vendored dependency versions on disk must match
   `composer.lock`. The zip's dependencies are rebuilt from the lock file,
   not copied from the working tree, so building while the two disagree
   would ship older code than sites already run.
2. **The test suite** — a zip is only produced from code that passes.

The result excludes `tests/`, `bin/`, development translation sources and
internal documentation.

## Tests

```bash
cd tests && composer install && cd ..   # once
php tests/vendor/bin/phpunit
```

Runs against a real WordPress install and a **separate** database — the
core test suite drops and recreates tables, so it must never point at a
site's real database.

## Contributing

Issues and pull requests are welcome. Please read [SECURITY.md](SECURITY.md)
before reporting anything security-related, and note that `tests/` is
expected to pass before a change is considered complete.

## Licence

GPL v2 or later — see [LICENSE](LICENSE). This is not a stylistic choice:
the theme bundles GPLv2+ code, so a non-GPL licence would conflict with
what it ships.
