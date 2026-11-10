# Changelog

All notable changes to this theme are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The version in `style.css` is what the update system compares against —
see the README's Versioning section. Every release needs a section here.

## [Unreleased]

## [1.39.0] — 2026-11-09

Nine fixes from the November 2026 reliability audit (tracked in `ROADMAP.md`, "Reliability audit").

**Upgrade notes.**
- An MCP client can no longer read or write the **Tracking** page, even where it was ticked under MCP Access. Nothing to do; the tick is ignored.
- A form that **prints its own honeypot field called `website`** (instead of calling `devbase_form_fields()`) must pass `'honeypot' => 'website'`
  to `devbase_form_submit()` / `devbase_form_send()`, or rename the field: the default name changed. A form built with `devbase_form_fields()`
  needs nothing.
- Cutting a release now needs a GitHub token that can read the repository's Actions runs (`gh auth login`, or git's stored credential, is enough).

### Security

- **MCP can no longer write the Tracking page.** Its Custom Scripts fields are printed unescaped on every page, so an AI client allowed onto that page
  (and acting on text it had read: prompt injection) could have put a script in every visitor's browser with `devbase/update-options`. `tracking-settings`
  joins the pages MCP never reaches, and `header_scripts`, `footer_scripts`, `gtm_id` and `ga4_id` join the protected fields, wherever a child theme puts them.

### Fixed

- **Releases are checked on PHP 8.2 before sites get them.** `bin/release.sh` ran the suite only on the developer's PHP (8.5), and CI ran after Warden had
  already published the tag. It now pushes the branch first, waits for the Tests workflow on that exact commit (PHP 8.2 and 8.4), and creates the tag only
  when it is green; a red, missing or unfinished run tags nothing. Before that, when a PHP of the minimum version is on the machine (Local installs one),
  every shipped PHP file is parsed with it. `RELEASE_SKIP_CI=1` is the emergency way round, and says so.
- **Scheduled tasks no longer depend on visits.** Every verified Brand Relay ping now starts `wp-cron.php` when something is due, whether or not
  `DISABLE_WP_CRON` is set. Theme auto-updates, the retention purge, a backup's retry and the credit refresh all ran only on WP-Cron, which a quiet site
  or a host with cron off never fires. Filter: `devbase/maintenance/spawn_cron`.
- **A bundled plugin is updated without waiting for an administrator.** A newer WooCommerce or WPvivid shipped in a theme release was applied only when
  someone with the right to manage plugins loaded wp-admin, while WordPress's own update for it is locked. A verified ping now applies it too. Filter:
  `devbase/maintenance/sync_plugins_on_ping`.
- **A backup that stops moving is finished off, not abandoned.** Asking for a backup while one has not moved for 90 seconds now moves it on (it was only
  answered `idle`, and waited for a WP-Cron event). A job stalled past six hours is failed properly before the next one starts: aborted at Brand Relay and
  its local archive of the whole site deleted. Before, the new job overwrote the record and that archive stayed in the backup folder for good. A failed
  request to Brand Relay keeps its reason ("Operation timed out…") instead of "no answer".
- **A slow mail server or webhook no longer holds the visitor's form.** With SMTP on, a server that did not answer was waited on for PHPMailer's 300
  seconds; it is now 15 (`devbase/smtp/timeout`). And on PHP-FPM a submission is sent to its destinations after the visitor has their answer, not before.
- **A destination that fails is tried again**: three tries in all (at once, after 5 minutes, after 30), a destination at a time, so one that already
  received the submission never gets it twice. A request that dies between storing and sending is picked up the same way. The submission screen says how
  often it tried. Filters: `devbase/forms/defer_dispatch`, `devbase/forms/dispatch_max_attempts`, `devbase/forms/dispatch_retry_delay`.
- **The honeypot field is no longer called `website`**, a name browsers and password managers fill in by themselves: a filled honeypot is answered as a
  success with nothing stored, so a real visitor's message could vanish silently. It is now `devbase_ref` (`devbase/forms/honeypot_name`), and what the
  honeypot discards is counted per form on Miscellaneous > System Status > Spam Trap (a count and a time, nothing of the submission).
- **A plan date that cannot be read no longer means "no date".** Brand Relay sends some times as epoch milliseconds; read with `strtotime()` alone, such
  an expiry was ignored and the plan never ended. Dates, seconds and milliseconds are now all understood, and one that is present but unreadable switches
  the plan's services off and is recorded (`problem` in the saved plan, and the PHP error log).
- **The retention purge finishes.** It deleted at most 200 old submissions per policy row per run, so a flooded form never caught up. It now goes on,
  batch after batch, for up to 20 seconds, and says so when it had to stop (`devbase/forms/retention_batch`, `devbase/forms/retention_budget`).

### Added

- `tests/Test_WPvivid_Engine_Contract.php` reads the bundled WPvivid zip and fails when a class, method, option or nonce the backup engine calls is
  renamed or removed. Every other backup test replaces the engine, so such a release passed the suite; it now fails on the Dependabot pull request.

### Changed

- **WPvivid Backup is locked down to what the backup flow uses**, while the site's plan includes backups (a WPvivid a client installed for themselves is untouched). Every admin-ajax action of WPvivid that is not on an allowlist is
  unhooked (165 registered, about 45 left: watching a backup, the list, deleting one, restore, logs; its Settings tab is gone too); starting a backup from a screen is no longer possible (only Brand Relay's ping starts one). Its cloud, migration, staging,
  snapshot, export/import and image-cleaner features, their tabs and menus, and its sidebar promotions are gone from the screen as well. All through WPvivid's own hooks, no WPvivid file edited. Filters: `devbase/wpvivid/lockdown`
  and `devbase/wpvivid/allowed_*`. Not removed: WPvivid's site-to-site receiver (it is registered before a theme can run; it needs a transfer key). Verified with a real 131 MB backup through our job.

## [1.38.0] — 2026-11-08

### Added

- **MCP: a page's SEO title and description.** `devbase/create-post` and `devbase/update-post` take an optional `seo` object (`title`, `description`; a key left out is kept, an empty string clears it)
  and `devbase/get-post`, create and update return it. It is saved through The SEO Framework's own save routine, only while TSF is running, and needs the same permission as editing the post.
  Organization Details and an archive's SEO tab were already reachable through the options-page abilities once the owner allows the page under Miscellaneous > MCP Access; tests now cover that.

## [1.37.1] — 2026-11-08

### Changed

- Dependencies: Admin Site Enhancements 9.3.1 -> 9.3.2 and WPvivid Backup 0.9.139 -> 0.9.140 (two Dependabot pull requests, tested and merged: vendor drift check clean,
  the full test suite passing, and the site's front end and admin loading without errors on ASE 9.3.2).

## [1.37.0] — 2026-11-08

**Upgrade note:** SEO is now The SEO Framework and it is **off by default**. A site that updates has no theme SEO output (titles, descriptions, social tags, structured data, sitemap) until someone turns it on in Theme Settings > Miscellaneous > SEO > Enable The SEO Framework. The old Schema Markup settings are replaced by Organization Details.

### Changed (breaking)

- **SEO is The SEO Framework (TSF), version 5.2.0, vendored** in `vendor/autodescription/` like Secure Custom Fields and loaded by
  `inc/dependencies/tsf/tsf.php`. It prints titles, descriptions, canonicals, Open Graph and Twitter tags, the JSON-LD graph,
  breadcrumbs, the sitemap and `robots.txt`. A site that already runs TSF as a plugin keeps its own copy (`devbase_dependency_is_foreign( 'tsf' )`).
  Dependabot moves the pin, `dependabot-vendor.yml` commits `vendor/`, and `bin/check-vendor-drift.sh` and the System Status tab now
  know about it. `tests/Test_TSF_Loader.php` fails a Dependabot pull request if a TSF release moves what the loader or our hooks rely on.
- **The SEO Framework is OFF by default**, and **Enable The SEO Framework** (Theme Settings > Miscellaneous > SEO, a tab of its own that is always visible) turns it on. While it is off TSF is not loaded at all, not just hidden,
  for a client who runs their own SEO plugin. The Organization Details page and the archive SEO tab, which only TSF reads, are hidden with it; nothing saved is deleted. `devbase/tsf/enabled` decides it in code.
  TSF also stays off while another SEO plugin (Yoast, All in One SEO, SEOPress, Rank Math, ...) is active, so tags are never printed twice; `devbase/tsf/conflicting_plugins` changes the list.
  System Status shows the bundled version as "(disabled)".
- TSF's own **Presence** tab (Organization or Person, name, logo, profile links) is hidden in its Schema settings box with its
  `the_seo_framework_schema_settings_tabs` filter, because Organization Details is the one place for them. Nothing saved is deleted.
  `devbase/tsf/hide_organization_fields` (default true) keeps the tab; a site's own TSF is never changed.
- TSF's own promotion is switched off for the theme's copy, from the theme with TSF's own switches (nothing in `vendor/autodescription/` is touched): the
  comments in the page source, the "Generated by" link on the styled sitemap, the "Extensions" button and suggestions in its settings
  header (`TSF_DISABLE_SUGGESTIONS`), and the install/update thank-you notices. `devbase/tsf/hide_branding` (default true) turns it back on.
- The theme now declares `title-tag` support. Nothing declared it before, so WordPress printed no `<title>` and no SEO plugin's title
  could reach the page. The placeholder `views/index.twig` no longer hardcodes a `<title>`.
- **Organization Details** replaces the Schema Markup page, with every organization field in one place: name, URL, logo, social profile
  links, description, slogan, founding date, areas of expertise and locations. They are merged into TSF's own Organization entity through
  `the_seo_framework_schema_graph_data`. A filled name, URL, logo or profile list replaces TSF's own Schema setting for it, an empty one leaves
  TSF's, and the rest is only added (TSF's Schema box says so at the top). The page slug, field names and field keys are unchanged, so saved
  values keep working with no migration and no database write. The old "Enable Organization Schema" switch is gone: TSF's knowledge-graph setting
  is the master switch. Read by field key, so a site saved before the theme rename (`field_boostway_...` reference rows) now outputs its
  expertise and address too, which it did not before.
- **Organization or Person.** Organization Details has a select (default Organization) that shows each type's own fields; a Person has Name, URL,
  Photo, Job Title, Description, Areas of Expertise and Profile Links. It drives TSF's own Organization/Person type at read time, only once saved, so nothing
  is written to TSF's settings and a site that never saved it keeps TSF's choice. A post author's Person entity is never touched.
- **Archive SEO** is handed to TSF through `the_seo_framework_title_from_custom_field` and `the_seo_framework_custom_field_description`.
  The `<head>` buffering and HTML rewriting are gone; TSF prints the tags, adds " - Page N" and its own branding. A value filled in an
  archive's SEO tab wins over TSF's own Post Type Archive Settings for it.

### Removed

- The Organization enable switch, and the Website and Breadcrumb schema types: TSF prints those. Saved values stay in the database.
- The `devbase/schema/tabs` filter (a child that used it needs TSF's `the_seo_framework_schema_graph_data` instead; the starter
  child's `inc/content/schema.php` only holds a commented example, so it is inert, not broken).
- The standalone Organization, WebSite and BreadcrumbList JSON-LD blocks, which duplicated TSF's WebSite entity.

## [1.36.2] — 2026-11-07

### Changed

- A test release with no change to the code: it exists so a site has a theme update waiting, to check that Brand Relay's site health report counts it.

## [1.36.1] — 2026-11-07

### Changed

- Dependencies: WPvivid Backup 0.9.138 -> 0.9.139 (Dependabot pull request, merged; the first one found through WP Packages). WPvivid's own changelog: "an issue that
  prevented version 0.9.138 from being properly installed". Compared with 0.9.138, only the version strings and the readme differ, so the backup driver's calls
  into WPvivid are unaffected.

## [1.36.0] — 2026-11-07

### Changed

- **Bundled plugins now come from WP Packages instead of WPackagist.** WPackagist is a community mirror that lags behind WordPress.org (WPvivid 0.9.139 was
  eight days old and still missing). Both `composer.json` files point at `https://repo.wp-packages.org` and use the `wp-plugin/` names; the scripts, the registry,
  the test and the README follow. Nothing ships differently: the pinned versions are unchanged and the bundled zips and `vendor/` plugin files are byte-for-byte the
  same content. Dependabot reads the repository from the composer files, so its pull requests now see new versions as soon as WP Packages does.

## [1.35.0] — 2026-11-05

### Added

- **The Credit and Management tabs of Theme Settings > Miscellaneous are out of sight until asked for.** Press L twice quickly, or R twice quickly, to show them, and C
  twice quickly to hide them; they are hidden again on every page load. The keys are ignored while typing in a field. This only hides the tabs: their fields stay in the
  form and keep their values.

## [1.34.1] — 2026-11-05

### Removed

- **A sync no longer touches the Brand Relay address or site key.** The `relay_base_url` and `relay_site_key` params of 1.34.0 were added by mistake: both are entered
  by hand on the Licence page's Remote setup tab, as before 1.34.0. The Warden address, public key and licence key still change on sync.

## [1.34.0] — 2026-11-05

### Added

- **The Brand Relay address and site key can be changed from Brand Relay**, through the site params `relay_base_url` and `relay_site_key`. They are saved only
  after a request with the new values succeeds, so a wrong one cannot cut the site off.
- **The ping's answer lists `refused`**: params sent but not applied (invalid, set in code, or a Brand Relay address or key that failed). Names only.

### Changed

- **The Warden address and public key are no longer fill-only.** A sync now changes them like the licence key, and drops the cached Warden answer so the new values
  are checked at once. A site could not otherwise move to another Warden without someone on it. A Brand Relay account therefore decides where theme code comes from;
  to refuse that on a site, set the value in code (`DEVBASE_WARDEN_URL`, `DEVBASE_WARDEN_PUBLIC_KEY`, `inc/updates/pinned-keys.php`).

## [1.33.0] — 2026-11-05

### Security

- **Theme Settings now needs an administrator.** No options page set a capability, so SCF's default (`edit_posts`) applied and any Contributor, Author or Editor
  could open and save Licence, SMTP, Security, Tracking, Form Destinations and Miscellaneous: read the secrets in the page source, and post a new update server and
  signing key (which leads to code execution through a theme update), a webhook that copies every submission, or an SMTP server that receives password resets.
  Every page under Theme Settings now needs `manage_options` (filter `devbase/settings/capability`), the Archive pages `edit_pages`
  (`devbase/archive/capability`), including pages a child adds (inc/theme-settings/access.php).
- **"Read-only" fields are enforced on the server.** A save from the screen never changes the update server, verification key or licence key (only the Brand
  Relay sync and `update_field()` do), nor the Remote setup fields while that tab is hidden or the value is set in code. Password fields are no longer drawn back into
  the page; an empty one keeps the stored value.
- **Theme packages are verified before they are installed.** The downloaded zip must match the `sha256` of the signed Warden answer, and the release
  `signature` must verify with a trusted key (`upgrader_pre_download`). A release without a checksum is refused.
- **The Warden key can be pinned in code.** List it in `inc/updates/pinned-keys.php` (or `DEVBASE_WARDEN_PUBLIC_KEY`, `DEVBASE_WARDEN_URL` in wp-config.php); the
  provisioned field is then ignored and Brand Relay no longer provisions it. Empty by default: nothing changes until a key is added. Paste EXACTLY the key the sites
  already have, or every site rejects every answer.
- **MCP never reaches secrets.** The Licence, SMTP, Security, Form Destinations and top Theme Settings pages are not offered or allowed for MCP access, secret and
  provisioned fields are protected on every page, and password fields are never returned. `devbase/get-post` no longer returns the blocks of a password-protected
  post to a caller who cannot edit it.
- **Backups accept only what they should from Brand Relay.** File names must be plain names (no folder, no `..`), upload and complete addresses must be Brand
  Relay backup paths (a path like `@other.host` could send the site key elsewhere), a downloaded file must match the checksum listed for it (required), a failed
  rename fails the job, and the administrator a backup runs as is dropped before shutdown hooks.
- **Webhook and credit addresses are fetched with `wp_safe_remote_*`**, so they cannot reach private or local addresses (the server's own network). A webhook
  keeps working on any port of a public address (n8n on `:5678`): the port of the destination's own URL is allowed for that one request. A receiver that lives on a
  private address is allowed with `devbase/forms/webhook_unsafe_ok`; a local environment is exempt.
- **The test form is off on live sites.** `?devbase_test_form=1` and its handler load only when `WP_ENVIRONMENT_TYPE` is local or development (or
  `devbase/test_form/enabled`).
- **CAPTCHA tokens must come from this site.** Turnstile and reCAPTCHA answers naming another hostname are refused (more names with
  `devbase/security/allowed_hostnames`); a reCAPTCHA answer without an action is refused.
- **A Brand Relay ping cannot be replayed by sending it twice at once.** Its nonce is recorded with one atomic insert.

### Fixed

- **Forms on cached pages no longer stop working after a day.** `forms.js` asks for a fresh nonce (`devbase_form_nonce`, never cached) just before sending.
- **A field posted as an array no longer makes a form answer 500.** WordPress's string sanitizers receive `''`; the submission screen and the notification email
  show a list value one item per line.
- **Error tracking no longer loses errors queued while a batch was being sent.**
- `bin/bump-version.sh` works on Linux and in CI (it used the BSD-only `sed -i ''`).

## [1.32.0] — 2026-11-04

### Added

- **Site health reports now carry WordPress's own Site Health results.** The report gets a `site_health` block: how many of WordPress's local tests passed, were
  "recommended" or "critical", and for each test that did not pass its id and WordPress's own sentence (critical first, 30 at most). Nineteen tests run, in 6 to
  30 ms, only when a ping asks for the report. The slow ones that make network calls (loopback, background updates, WordPress.org, HTTPS status, REST) are not
  run; the one that asks api.wordpress.org about the PHP version (WordPress keeps the answer for a week) gets 3 seconds at most. Needs Brand Relay 0.37.0 or
  later to show it (older versions ignore the block).

## [1.31.0] — 2026-11-04

### Added

- **Error tracking now collects warnings, notices and deprecations, not only fatals.** A chained PHP error handler (installed only while the plan has error
  tracking) notes each error with an array lookup and a counter (about 0.2 microseconds), keeps one row per place with a count, and queues them once at the end of a
  request that had something. It calls the earlier handler and returns its answer, leaves `@`-silenced errors alone, and notes at most 25 places per request. The queue
  holds 200 errors and drops the least severe first, so notices never push out a fatal; an error queued in the last minute is not written again. Brand Relay's four
  levels are used (`fatal`, `error`, `warning`, `notice`), and a deprecation is sent as type `deprecated`.

### Fixed

- **Queued errors are sent with the time they happened.** The time went as a number, which Brand Relay ignores, so every event was dated by the moment its batch arrived.
- **A fatal is sent before notices.** A batch is the most severe first, so a fatal does not wait behind a full queue of older notices.

## [1.30.6] — 2026-11-04

### Changed

- **A backup includes WordPress core again** (`wp-admin`, `wp-includes`, about 23 MB zipped), as WPvivid's own backup does. Plugins and hosts can edit core files, and
  a restore that reinstalled a clean copy would lose those edits. 1.30.4 and 1.30.5 left it out; the backup is the whole site once more.

## [1.30.5] — 2026-11-04

### Changed

- **Backups are made by WPvivid's newer engine, the one its own dashboard uses.** The older engine we called follows symlinked folders; the newer one does
  not. On WordPress.com Jetpack, Akismet and the mu-plugins are symlinks to shared host copies, so a backup of ocwof.org came to 157 MB (about 311 MB of host files
  inside) against 68 MB from WPvivid's dashboard. The backup is still the database plus `wp-content` without WordPress core, and `upgrade-temp-backup` and
  `debug.log` are still skipped. The newer engine has no size estimate, so a site too large for Brand Relay's 10 files is now refused after the backup is made
  (it was refused before), not before it.

## [1.30.4] — 2026-11-04

### Changed

- **The Backups tab has no "Back up now" button any more**, and the server no longer accepts that action: clients cannot start a backup; Brand Relay's schedule
  (or its Run now button) does.
- **Backups no longer contain WordPress itself.** WPvivid always adds a copy of core (`wp-admin`, `wp-includes`, about 95 MB, 23 MB zipped) to a
  "files and database" backup; a restore never needs it, so a Brand Relay backup is now the database plus `wp-content`. The leftover `upgrade-temp-backup`
  and `wpvivid_uploads` folders and `debug.log` are also skipped.

## [1.30.3] — 2026-11-04

### Fixed

- **The Backups tab no longer sticks on "Loading…".** Its script kept the address and version (`page.js?ver=1.0.0`) it had when Backups was a page of its own, so a
  host or browser that had cached the old script went on running it, and it never fetched the tab's content (seen on ocwof.org). The script's version is now
  the theme's version, so every release fetches a fresh copy, and the tab also loads when the page is clicked, not only when it is seen.

## [1.30.2] — 2026-11-04

### Changed

- **Backups is now a tab of Theme Settings > Licence** (second, after Status), no longer a page of its own; the old address redirects there. Its content loads
  the first time the tab is opened, so the Licence page never waits on Brand Relay.
- **A plan without backups gets a calm message** in that tab instead of buttons: backups are not part of the plan at the moment, and the client is invited to
  get in touch with their developer.

### Fixed

- **The backup list showed no date** for real backups: Brand Relay sends its times as epoch milliseconds, which the list did not understand.

## [1.30.1] — 2026-11-04

### Fixed

- **A download that fails part way no longer leaves part of a split backup in WPvivid's folder.** WPvivid files the parts it finds together by their task id
  and does not flag a set with missing parts as incomplete, so a later rescan could have listed a half backup as a whole one. A failed download now removes
  the parts it had already placed, and WPvivid is asked to rescan only once every file is in place. Tried end to end with a real 6-file backup: uploaded,
  completed at Brand Relay, downloaded byte for byte identical, listed by WPvivid as one backup.
- **A big site no longer exceeds Brand Relay's 10 files per backup.** WPvivid splits its archive every 200 MB of source files by default (the live site's
  backup came to 4 files), so a 5 GB site would have been 25 files and refused. Our backups now split at 4080 MB, the most WPvivid's zip engine can write
  (no ZIP64, so no archive over 4 GiB), set only while our backup is prepared: the client's own WPvivid setting is untouched. A site too big even for
  that (about 36 GB) is refused up front with a plain message, and a backup that still comes to more than 10 files fails before anything is sent.

- **Hide WPvivid Backup only applies while the plan includes backups**, which is when the theme is the one using WPvivid. A WPvivid a client installed and
  uses keeps its menu, admin bar entry and notices on a site whose plan has no backups.
- **A plugin the site already had is never removed by the theme**, whichever one: WooCommerce and WPvivid alike are upgraded if older, never downgraded,
  kept active while required, and left exactly as they are when nothing requires them any more. (Before, only WPvivid was treated this way; a hand-installed
  WooCommerce a child later stopped asking for would have been removed.) A copy under another folder name is used and no second one is installed.
- **A dependency the site already runs as a plugin is used, not duplicated** (`inc/dependencies/foreign.php`): Secure Custom Fields / ACF, Admin Site
  Enhancements and the MCP Adapter. The theme's loader for that dependency does nothing, so its asset URL overrides no longer break the other copy, its ASE
  defaults are no longer written into the site's own ASE settings, and "Hide Secure Custom Fields" leaves their SCF's screens alone.

## [1.30.0] — 2026-11-04

### Changed

- **A new Theme Settings menu icon**: three sliders, drawn for the WordPress admin menu (fills only, so WordPress recolours it for every admin colour
  scheme and for hover and current states). It replaces the "B" of an older company's logo, whose file is removed.

## [1.29.0] — 2026-11-04

### Added

- **Hide Secure Custom Fields** (Theme Settings > Miscellaneous > Management, on by default). The Custom Fields menu and its screens (field groups, post types,
  taxonomies, options pages, tools) are hidden and closed, so nobody changes the site's structure by accident. It uses SCF's own `acf/settings/show_admin`.
  Field groups, Theme Settings, blocks and the abilities keep working (checked on a real site: 35 field groups, 68 abilities, unchanged). To edit field
  groups, switch it off first.

### Changed

- **All three Management switches are on by default**: Lock remote setup, Hide WPvivid Backup and Hide Secure Custom Fields. A site where nobody has
  saved them is locked down; one where somebody saved a switch off keeps it off. They are read from the saved option itself (`devbase_switch_on()`),
  so the default also holds early in the load, before any field is registered.
- Dependencies: Secure Custom Fields 6.9.7 -> 6.9.8 and WooCommerce 11.2.1 -> 11.2.2 (Dependabot pull requests 4 and 5, merged; SCF's registrations were
  compared before and after on a real site and are identical).

### Fixed

- The Backups page had become the page Theme Settings opens on (the first sub-page registered wins). It now loads after the others, so the menu opens on
  Licence again.

## [1.28.0] — 2026-11-04

### Added

- **Hide WPvivid Backup** (Theme Settings > Miscellaneous > Management, a switch of its own beside "Lock remote setup", off by default). When on, WPvivid
  disappears from the admin menu, the admin bar, the Plugins list and the notices it puts on every admin screen; backups keep working. Hidden is not
  blocked: its page still opens by its address so a restore stays possible. The Backups page links to it only while the switch is off, and otherwise
  tells the client to ask their developer.
- Installing WPvivid no longer sends the admin who loads the next page to its own screen (its activation redirect is cancelled).

## [1.27.0] — 2026-11-04

Backups need Brand Relay 0.34.0 for its Run now buttons and work with 0.30.0 and later. Sites need 1.26.0's bundled WPvivid, which this release builds on.

### Added

- **Backups** (`inc/backups/`, needs Brand Relay 0.34.0 for "Run now"; works with 0.30.0+). When Brand Relay's signed ping names `backup`, the site
  starts a job: WPvivid Backup (bundled since 1.26.0) makes ONE merged archive of the files and the database, kept on the site only and without
  touching WPvivid's own remote storage, schedule or retention; the job declares it to Brand Relay, sends it in parts of exactly 50 MiB streamed
  from the file (flat memory, a 5 GB backup is fine), completes it, reports `last_backup_at` in the health report and deletes the local copy.
  It works in time-limited steps run by signed loopback requests (WPvivid only sets itself up in an admin request), resumes after a failed part,
  and is aborted at Brand Relay after repeated failures. The answer to the ping is `started`.
- **Theme Settings > Backups**: what Brand Relay holds for this site (also after a reinstall with the same site key), **Back up now**, and
  **Download to WPvivid** per backup: a resumable, checksum-checked download into WPvivid's folder, then WPvivid rescans it, so the owner only
  presses Restore in WPvivid. Restore itself is deliberately manual.

### Changed

- **The site has no delay of its own for Brand Relay's services.** The 30-second minimum gap between two runs is gone: when Brand Relay asks (its
  plan schedule or its **Run now** buttons), the site runs and reports. The only refusal left is another ping running at that moment (`skipped`).
  A plan pulled more than 10 seconds ago (was 60) is pulled again before a service runs.

## [1.26.0] — 2026-11-04

Needs nothing new from Brand Relay. This is the first piece of backups: the plugin itself. The backup driver and the Brand Relay page follow.

### Added

- **WPvivid Backup is bundled**, the first piece of backups (the rest follows in later releases). It ships as a zip in `bundled-plugins/` like WooCommerce
  (pinned in `bundled-plugins/composer.json`, so Dependabot's existing `/bundled-plugins` entry proposes new versions and `dependabot-vendor.yml`
  rebuilds the zip), but the PARENT requires it, and only while the site's Brand Relay plan has the Backups service in force:
  installed and activated then; deactivated and its files deleted at once when the service goes off (a buffer after expiry is Brand Relay's to give); never
  while a backup or restore is running. A WPvivid already on the site is adopted for updates and never removed; a copy under another folder name is used and not
  doubled; the Pro edition blocks the install with a notice. See `inc/bundled-plugins/AGENT.md`.
- **Check licence and updates now** (Theme Settings > Licence > Status; was "Check for updates now"): besides the Warden check it now also applies the
  plan right away: it installs, activates, deactivates or removes the plugins the plan decides (WPvivid Backup) and says what it did, instead of
  waiting for the next wp-admin page load.

## [1.25.0] — 2026-11-04

Needs Brand Relay 0.32.0 or later (older ones accept the empty report too, but show nothing for it).

### Added

- **"No errors" reports.** When Brand Relay's ping names `events` and nothing is queued, the site now sends an empty batch (`{"events":[]}`)
  and answers the trigger `clear`, instead of staying silent and answering `skipped`. Brand Relay shows it as "No errors", so a quiet
  site can be told apart from one that did not run. `skipped` now means only that it ran moments ago (Brand Relay answered 429).
- The Error tracking line on the Remote setup tab says when the site last checked and found no errors.

## [1.24.0] — 2026-11-04

Needs Brand Relay 0.30.0 or later. Error tracking is a plan service there; its "Ask for errors" setting makes Brand Relay ask the site for them.

### Added

- **Error tracking** (`inc/maintenance/events.php`). The site keeps its fatal errors and uncaught exceptions and sends them to Brand Relay when its
  signed ping names `events` (`{"run":["events"]}`), in batches of up to 50 events and 60 KB. It collects only while the site's plan has
  error tracking in force, so a plan without it costs nothing. Two capture points, because WordPress's own fatal-error handler ends the
  request before a plain shutdown hook of ours would run: our shutdown function and the `wp_die_handler` filter, once per request.
- **Scrubbed before it is kept:** first line of the message only (the stack trace, with its arguments, is never kept), server paths cut
  to `wp-content/...` (folders with spaces in their names too), emails, tokens and URL query strings removed, the request's path
  only, never its data. The same error again raises a `count` instead of filling the queue, which holds 100 at most.
- The Remote setup tab shows an **Error tracking** line: errors waiting, when the last were sent, and the last failure.

### Known limit

- A fatal while a plugin is still loading, before the theme, cannot be seen by theme code. That is a total outage, which Brand Relay's
  uptime check reports. Warnings and notices are not collected.

## [1.23.0] — 2026-11-03

Needs Brand Relay 0.30.0 or later: 0.29.0 made site health a plan service, and 0.30.0 is the version that asks sites to
report (its plans' "Trigger from Brand Relay" setting, which sends the signed body). Verified end to end against 0.30.0.

### Added

- **Site health reports** (`inc/maintenance/`). When Brand Relay asks, a site tells it how it is doing: WordPress, PHP and theme
  versions (the child's, and the base theme's beside it), updates waiting, free disk space, whether scheduled tasks run, and
  the last backup when there is one. Nothing from the site's content. It is a plan service: it is sent only while the site's plan
  includes **Site health**.
- **Brand Relay is the clock: the ping names what to run.** The signed sync ping (`POST /wp-json/devbase/v1/sync`, the same URL
  as before) may carry a signed JSON body such as `{ "run": ["settings", "health", "events", "backup", "uptime"] }`. An empty body
  is the old ping and still means settings only. The site runs the names it knows, ignores the rest, and answers
  `ran: { settings: "ok", health: "sent", events: "skipped", ... }` (`ok`, `sent`, `not_in_plan`, `skipped` or `error`). Nothing runs
  on a website visit or on WP-Cron, since most sites get few visits and many switch cron off. Events and backups are accepted
  and answered `skipped` until they are built; `uptime` is Brand Relay's own and is always `skipped`.
- **Never sends in the blind.** Before a service runs, the plan is pulled (`GET /v1/site`, unless the same ping just pulled it) and
  the service runs only if the plan says it is in force; an expired plan stops it at once. A ping flood sends nothing extra.
- The Remote setup tab shows a **Site health** line: when the last report was sent, and whether the plan includes it.
- New: `devbase_relay_service_in_force()`, `devbase_relay_service_limits()`, `devbase_maintenance_requested()`,
  `devbase_maintenance_run()`, and the filters `devbase/maintenance/services`, `devbase/maintenance/report` and
  `devbase/maintenance/last_backup_at`.

### Changed

- The sync reads Brand Relay's whole `/v1/site` answer (`devbase_relay_fetch_site()`), not only its params, and keeps the
  `licence` part. The params are used exactly as before.

## [1.22.0] — 2026-11-03

### Added

- **A switch for the Remote setup tab**: Theme Settings > Miscellaneous > **Management** > **Lock remote setup**. On,
  the Licence page's Remote setup tab is hidden; off (the default), it is visible. Tucked away on a page a client does
  not go to, which is enough. `devbase/licence/hide_remote_setup` can still decide it in code.

### Removed

- **The developer-logins list of 1.21.0** (`devbase/developer_logins`, `DEVBASE_DEVELOPER_LOGINS`, `inc/helpers/developer.php`)
  is gone, replaced by the switch above. The **Credit** tab goes back to being shown unless `DEVBASE_CREDIT_BRAND_URL` is
  set in code or `devbase/credit/hide_settings` says otherwise. A child that still registers `devbase/developer_logins`
  can remove that line; nothing reads it.

## [1.21.0] — 2026-11-03

### Added

- **One place to say who the developer is.** A child theme (or `wp-config.php`) names the developer logins once,
  with the filter `devbase/developer_logins` or the constant `DEVBASE_DEVELOPER_LOGINS`; the theme holds no logins
  itself. Once the list has logins, the developer-only settings, the **Credit** tab and the Licence page's **Remote
  setup** tab, are hidden from everyone else. With no list, nothing is hidden, so a fresh install still works.
  Replaces the copy of the same rule each child kept; `devbase/credit/hide_settings` and
  `devbase/licence/hide_remote_setup` still work and win when set.

## [1.20.0] — 2026-11-03

### Changed

- **The Licence page is rewritten in plain words.** Theme Settings > Licence now has three tabs, opening on
  **Status** (the table of each theme's licence and updates, and **Check for updates now**), then **Licence**
  (**Licence key**, **Update server**, **Verification key**, read-only, with one calm notice that your developer
  manages them instead of the same line under every field), then **Remote setup** (**Management server
  address**, **Site access key**). The product names Warden and Brand Relay no longer appear anywhere a client
  sees, nor the tags. The status reads "Active", "Valid until 30 July 2027", "Version 1.4.0 available",
  "Paused: no active licence". The messages of the check button are plainer too. French translations included.
  The fields, their values and the way they are provisioned are unchanged.

### Added

- **The Remote setup tab can be hidden from clients**: the filter `devbase/licence/hide_remote_setup` (a child
  returns true for everyone but the developer, as the Credit tab does with `devbase/credit/hide_settings`). It is
  hidden by default when both `DEVBASE_RELAY_URL` and `DEVBASE_RELAY_SITE_KEY` are set in code. The developer
  still sees, on the Status tab, each theme's tag and app name and the time of the last remote update.

## [1.19.0] — 2026-11-03

### Changed

- **When the licence no longer covers a site, the theme stops managing the bundled WooCommerce, and the client can.**
  The locks that kept WooCommerce on the theme's version (no update notice, no automatic, one-click or bulk
  update, no uploaded zip over it) now hold only while Warden's last signed answer for the base theme says the
  licence is valid. They lift when that answer says it is not valid (expired, suspended, anything else), or when
  the site has no licence key at all. Nothing is pushed to the site and auto-updates stay off unless switched on:
  the site keeps the tested version it has, and updating it, with any security fix, becomes the client's
  choice. A failed check, an outage, or a key Warden has never answered leaves the locks as they are.
- **WooCommerce still cannot be deactivated or deleted** from WordPress while the child requires it, licence or
  not, because the shop does not work without it. (Update locks and presence locks are separate in
  `inc/bundled-plugins/lock.php`.)
- **A notice for administrators** on the Plugins screen and the Dashboard while the licence has lapsed: "Theme
  updates are paused: this site has no active licence", naming the plugin and saying the client now updates it. The
  plugin's row on the Plugins screen says the same.
- The Licence page names its key field **Theme Update Licence Key** (it was "Licence Key"), so it says what the
  key is for. Same field, same value.
- New: `devbase_bundled_plugins_licence_lapsed()` and the filter `devbase/bundled_plugins/licence_lapsed`.

### Fixed

- `bin/build-zip.sh` (manual installs only; a release tag is unaffected) left `bundled-plugins/woocommerce.zip` out of the
  zip, because it excludes every `*.zip`, and included the theme's own `.github/`. The plugin zips are kept now and
  `.github/` is left out. Tooling only: nothing changes on sites, no release needed.

## [1.18.0] — 2026-11-03

Works with Warden 0.5.0 or later (one licence key for several apps, selected by tag). A site's tags in Warden
must be `parent-theme` (this theme) and `child-theme` (the site's own theme).

### Changed

- **One licence key instead of two.** Theme Settings > Licence has a single **Licence Key** field
  (`warden_licence_key`) in place of *Base Theme Licence Key* and *Child Theme Licence Key*. The base theme is
  checked under the tag `parent-theme` and the active child theme under `child-theme`, with the same key, so one licence
  and one site slot cover both. The checks send `?tag=`, and an answer for the other tag, or the untagged
  `apps` list a multi-app licence gives when no tag is sent, is rejected instead of being read as this
  theme's verdict. A licence with no app under our tag is reported by name ("this licence has no app tagged
  parent-theme").
- **Brand Relay provisions one param, `warden_licence_key`,** in place of `warden_parent_licence` and
  `warden_child_licence`. The two old names are no longer read. When the new key arrives, the two old keys are
  deleted from the site and the cached answers are dropped so the next check asks about the new key.
- The Status tab shows each theme's tag and the app name Warden reports, or "Old per-theme key, no tag".

### Compatible

- **A site that still holds the two old keys keeps updating** (untagged checks, which Warden still answers for
  a licence with one app until its 1.0) until the single key is provisioned. Nothing breaks on update.
- `devbase_warden_parent_licence()`, `devbase_warden_child_licence()` and `devbase_warden_check( $track,
  $licence, $version, $force )` keep their names and signatures, so child themes (their payment kill switch
  reads the same stored answer, top-level `status`) need no change. New: `devbase_warden_licence()`,
  `devbase_warden_tag()`, the constants `DEVBASE_WARDEN_TAG_PARENT` / `DEVBASE_WARDEN_TAG_CHILD`, and the
  filter `devbase/warden/tag`.

### Not in this release

- Batch checks, per-tag update delivery changes and anything Brand Relay does beyond provisioning (health
  reports, error events, backups) are planned separately.

## [1.17.0] — 2026-11-03

### Removed

- **The payment kill switch is gone from the parent** (added in 1.16.0, never enabled by a released child
  theme except OCWOF 1.3.0, which has since handed it back). It belongs with each site's own code, where a
  parent release cannot remove or change it: it is now part of the starter child theme (1.2.0) and the OCWOF
  child (1.3.1). Removed: `inc/suspension/`, the `devbase/suspension/enabled` and `devbase/suspension/config`
  filters, and their strings. **Release order: a child that carries its own copy (OCWOF 1.3.1) first, then this
  parent.** A site on OCWOF 1.3.0 with this parent has no kill switch until the child updates.

## [1.16.1] — 2026-11-03

### Fixed

- **A fatal error when WooCommerce is removed or upgraded.** The files of a plugin that was running in the
  current request were deleted or replaced mid-request, so WooCommerce (which loads classes lazily and runs
  Action Scheduler at shutdown) failed with "Failed opening required" / "Class ActionScheduler_Lock not
  found" on that one page load, then recovered. The plugin is now deactivated at once and its files are
  deleted or swapped at the very end of the request, after every shutdown hook; the page-reload workaround
  for upgrades is gone.

## [1.16.0] — 2026-11-03

### Added

- **Payment kill switch** (`inc/suspension/`), moved from the OCWOF child so any client site can use it. The
  site follows its Warden licence status: Suspended in Warden means public visitors get a 503 "unavailable"
  page at the next licence check (the REST API and logged-out AJAX too); setting it back restores the site.
  `wp-admin` and `wp-login.php` stay open, only the status `suspended` locks, and a failed check changes
  nothing. **Off unless a child theme turns it on** (`devbase/suspension/enabled`; message, contact button
  and fonts through `devbase/suspension/config`), so no site changes on update.

### Fixed

- **Warnings on the login screen.** The parent switches Admin Site Enhancements' login-attempt limit on but
  never set that feature's own settings, so `wp-login.php` printed four "Undefined array key" warnings
  (`login_fails_allowed`, `login_lockout_maxcount`, `change_login_url`, `custom_login_slug`) on every site.
  They now get ASE's own defaults (3, 3, off, `backend`). Gap-fill only: a saved value, or a child's
  `devbase/ase/defaults` / `devbase/ase/enforced`, still wins.

### Changed

- README: the Shops and Payment kill switch features, how a child turns them on, and the committed
  WooCommerce zip under Packaging.

## [1.15.0] — 2026-11-03

### Added

- **WooCommerce can ship with the theme** (WooCommerce 11.2.1), for child themes that are shops. Off unless
  the child asks: a child adds `'woocommerce'` through the `devbase/required_plugins` filter and the site
  then gets WooCommerce installed into `wp-content/plugins/` from a zip in the theme (`bundled-plugins/`,
  `inc/bundled-plugins/`), activated, and kept active. Sites whose child doesn't ask are unaffected apart
  from the larger download (about 17 MB).
- **Only a theme release changes it.** While a child requires it, WordPress's automatic updates, the update
  notice, one-click and bulk updates, an uploaded zip over it, Deactivate and Delete are all blocked. A
  release installs its WooCommerce version when it is newer than the site's; it never downgrades.
- **Removing it:** switching to a theme that isn't built on this parent removes WooCommerce's files (the
  shop's orders, products and settings stay in the database). Switching to another child leaves it to that
  child.
- A problem installing it (the host won't allow file writes, a broken package) shows as an admin notice.
- `bin/bundle-plugins.sh` builds the zip from `bundled-plugins/composer.lock`; `bin/check-vendor-drift.sh`
  (so `bin/release.sh` and CI) refuses a zip that doesn't hold the locked version; Dependabot watches the pin
  and `dependabot-vendor.yml` rebuilds the zip on its pull request.
- System Status lists WooCommerce's bundled version on a site that requires it.

## [1.14.2] — 2026-11-02

### Changed

- **Admin Site Enhancements 9.3.0 → 9.3.1** (bundled; Dependabot pull request #3).

### Added

- **Dependabot vendor sync** (`.github/workflows/dependabot-vendor.yml`): on a Dependabot pull request that
  changes `composer.lock`, runs `composer install`, commits the updated `vendor/` to the PR branch and starts
  `tests.yml` on it again, so **Release-ready** goes green by itself and the PR is merge-ready once its checks
  pass. Until now every such PR was red until someone ran the same steps by hand. Runs only for
  Dependabot-authored PRs from this repository, with Composer scripts off. Dependabot no longer updates a PR
  the workflow has pushed to (`@dependabot recreate` rebuilds it). CI tooling only (`.github/` is
  export-ignored): no code change on sites, no release needed.

## [1.14.1] — 2026-10-30

### Fixed

- Sites that ran the removed sign-in (1.11.0 or 1.13.0) no longer keep its leftover data: registered clients,
  connected-app grants and the on/off setting are deleted once (`inc/legacy-mcp-oauth-cleanup.php`). It does
  nothing while sign-in code is loaded and never runs again, so it cannot affect a sign-in that returns.

## [1.14.0] — 2026-10-30

### Removed

- **Sign-in for AI Apps** (OAuth on the MCP endpoint) is removed again: `inc/mcp-oauth/`, the
  `mcp_oauth_enabled` setting, Connected AI Apps on the user profile, and the OAuth note in the MCP Adapter
  release issue. Restored in 1.13.0 but ChatGPT could not discover it on the live site. Application
  Passwords are unchanged. Sites that ran 1.11.0 or 1.13.0 keep unused `devbase_mcp_oauth_*` data, which is
  harmless.

## [1.13.0] — 2026-10-30

### Added

- **Sign-in for AI Apps is back** (removed in 1.12.0, first added in 1.11.0): OAuth for the MCP endpoint
  so Claude's "Add custom connector" and ChatGPT can connect with just the site's address. Off by default
  (Theme Settings → Miscellaneous → MCP Access). Same code and tests as 1.11.0, plus the fix below.

### Fixed

- Sign-in no longer steps aside when SEOPress is active. SEOPress registers its own MCP OAuth routes
  (`/seopress/mcp/v1/oauth-*`); the check for "another sign-in in charge" counted them. Only the MCP
  Adapter's own `/mcp/` namespace (or `devbase/mcp_oauth/other_provider`) counts now.

## [1.12.0] — 2026-10-30

### Removed

- **Sign-in for AI Apps** (OAuth on the MCP endpoint, added in 1.11.0) and everything that came with it:
  `inc/mcp-oauth/`, the `mcp_oauth_enabled` setting, Connected AI Apps on the user profile, and the
  OAuth note in the MCP Adapter release issue. It could clash with other plugins; the MCP Adapter is
  expected to ship its own sign-in. Application Passwords are unchanged. Sites that ran 1.11.0 keep
  unused `devbase_mcp_oauth_*` data, which is harmless.

## [1.11.0] — 2026-10-30

### Added

- **Sign-in for AI Apps** (Theme Settings → Miscellaneous → MCP Access, `mcp_oauth_enabled`, off by
  default): OAuth for the MCP endpoint, so Claude's "Add custom connector" and ChatGPT can connect with
  just the site's address. The person logs in on the site and clicks Allow. It follows the MCP authorization spec
  (2026-07-28): `/.well-known/` metadata, a `WWW-Authenticate` 401, PKCE S256, Client ID Metadata Documents
  and dynamic registration, `resource`-bound tokens, `iss`, refresh rotation with reuse detection.
  `inc/mcp-oauth/`.
  - Application Passwords keep working on the same endpoint. Bearer tokens are accepted on the MCP route
    only.
  - **Connected AI Apps** on the user profile lists connections, with Disconnect.
  - A status line says whether it is on and, if not, why (HTTPS, permalinks, or another sign-in in
    charge).
  - **Steps aside** when another sign-in for the endpoint exists, such as the MCP Adapter's planned one,
    or anything named through `devbase/mcp_oauth/other_provider`.
  - Protected from MCP writes.
  - Tests: `tests/Test_MCP_OAuth.php` (28).
- The MCP Adapter release issue now flags releases whose notes mention OAuth.

## [1.10.0] — 2026-10-30

### Added

- **Instructions for AI Assistants** (Theme Settings → Miscellaneous → MCP Access, `mcp_site_guide`): the
  owner's brief for an AI connected through MCP — who the site is for, the tone, how its pages are built.
  Returned by the new **`devbase/get-site-guide`** ability (`inc/abilities/site-guide.php`) with the site's
  name, tagline, address and language; `devbase/list-blocks` and `devbase/list-post-types` tell a client to
  read it first. Protected like the allow-lists: an MCP client reads it but cannot rewrite it through
  `update-options`. Tests: `tests/Test_MCP_Site_Guide.php` (5).

## [1.9.0] — 2026-10-30

### Added

- **A rate limit on every form** (`inc/helpers/forms/rate-limit.php`): 5 requests per visitor per form in
  10 minutes, counted in the pipeline, so `devbase_form_log_submission()` and the new submit step both
  apply it and no form can forget it. Changeable per form with `devbase/forms/rate_limit`; a site behind
  a proxy returns the real address from `devbase/forms/client_ip`.
- **`devbase_form_submit()` / `devbase_form_send()`** (`inc/helpers/forms/submit.php`): the server side of
  an AJAX form in one call. A form passes its fields, its rules and its reply; the step runs the honeypot,
  rate limit, nonce, validation (every failing field, 422), CAPTCHA, then stores and dispatches, and
  answers with one JSON shape. Validation runs before the CAPTCHA so a typo does not spend the one-time
  token. `before_log`/`after_log` hooks cover rules such as "the same signup twice is not stored again".
- **`devbase_form_fields()`** (`inc/helpers/forms/fields.php`): prints the AJAX action, the per-form
  nonce (without the duplicate `id` two forms on a page used to share), a honeypot and the CAPTCHA, and
  loads the shared script.
- **`assets/js/forms.js`**: sends any `<form data-devbase-form>`, checks the fields' own rules first,
  shows each error where the form says (`data-error-for`, `data-form-message`), and swaps in the
  `<template data-form-success>`; resets a Turnstile widget after each reply.
- Tests: `tests/Test_Form_Submit.php` (20), including the fail-closed cases: no valid nonce, or a CAPTCHA
  provider on without a good token, stores nothing.

### Changed

- `devbase_form_log_submission()`, `devbase_security_verify_request()` and the new
  `devbase_form_nonce_valid()` take the submitted data as an argument (default `$_POST`, so every
  existing call works unchanged).

## [1.8.2] — 2026-10-29

### Changed

- **Author and description.** The theme header credits Momar (https://momar-web.ca), and the description
  shown under Appearance → Themes now says what the theme provides and that the child theme requires it.
  It also declares `Requires PHP: 8.2`.

### Fixed

- **Licence file.** `License.md` carried the MIT licence while the theme header and README said GPL v2 or
  later, the licence the bundled plugins require. It now holds the GPL v2 text with the copyright notice.

## [1.8.1] — 2026-10-29

### Fixed

- **CI failed on PHP 8.2**: the test tooling's `tests/composer.lock` had been resolved on PHP 8.4 and pinned
  doctrine/instantiator 2.1.0, which requires PHP 8.4. The lock is removed (and ignored), so the test tools
  resolve for whichever PHP runs them. Tooling only; no code change on sites.

## [1.8.0] — 2026-10-29

### Added

- **Continuous integration** (`.github/workflows/tests.yml`), on every push to `main`, pull request and
  release tag. **Tests**: a fresh, latest WordPress, a MySQL service, `composer install` from `composer.lock`
  (so a Dependabot pull request is tested with the versions it proposes) and the suite on PHP 8.2 and 8.4.
  **Release-ready**: `bin/check-vendor-drift.sh`, red on a Dependabot pull request until `vendor/` is
  committed. CI only reports; `bin/release.sh` stays the gate, since Warden publishes a tag on the push.
  Rehearsed locally in a fresh WordPress 7.1.4, including a real pending update (symfony/polyfill-mbstring
  1.43.0 -> 1.43.3): drift reported, suite passing with the new version. Tooling only; no code change on sites.

## [1.7.0] — 2026-10-29

### Removed

- **Sites no longer update Secure Custom Fields, Admin Site Enhancements or the MCP Adapter themselves.** The
  daily checks (`inc/dependencies/dependency-updater.php`, `inc/dependencies/mcp-adapter/mcp-adapter-updater.php`)
  are gone. A theme update replaces the whole theme folder, `vendor/` included, so whenever a release carried an
  older copy than a site had fetched for itself, installing it downgraded that plugin (1.6.0 did), and a site
  could run plugin versions never tested with the theme. The bundled dependencies now change only in a theme
  release: every site runs exactly what the release shipped and the test suite passed with.
- Their status lines on Theme Settings > Miscellaneous > System Status, and their part in **Check for Updates
  Now** (which now checks Warden only).

### Added

- **System Status > Bundled Dependencies**: the versions this release ships (SCF, ASE, MCP Adapter, Timber),
  read from `vendor/`.
- A one-off cleanup on `init` that removes the two retired cron events and their stored status from sites that
  ran an earlier version.
- **Dependabot now covers SCF and ASE too**, alongside Timber, Twig and Symfony, and checks **daily** (was
  weekly, with the two plugins ignored).
- `.github/workflows/mcp-adapter-release.yml`: a daily GitHub workflow that opens an issue when the MCP Adapter
  (not on Packagist, so invisible to Dependabot) has a newer release than the committed copy. One issue per
  version, open or closed, so closing an issue skips that version. `.github/` is not in the package.
- 5 tests (`tests/Test_Bundled_Versions.php`); the 21 tests of the removed updaters are removed with them.
  Checked on the OCWOF site: one request on the new code cleared both retired events and their records.

## [1.6.1] — 2026-10-29

### Fixed

- **1.6.0 shipped older plugins than sites already run, so installing it downgraded them.** A theme update
  replaces the whole theme folder, `vendor/` included, and 1.6.0 carried Secure Custom Fields 6.9.5, Admin Site
  Enhancements 9.2.1 and MCP Adapter 0.7.0, while each site's daily dependency check had already moved them to
  6.9.7, 9.3.0 and 0.7.1. Installing 1.6.0 moved them back until the next daily check (a newer plugin's
  database changes running under an older one). This release ships the current versions: SCF 6.9.7, ASE 9.3.0,
  MCP Adapter 0.7.1. Full suite passing; checked on the OCWOF site.

## [1.6.0] — 2026-10-29

### Added

- **MCP can build and read pages from the site's blocks** (`inc/abilities/blocks.php`). The parent knows no
  site's blocks: it discovers what the active child registers.
  - `devbase/list-blocks`: the blocks the editor allows for a post type (its own `allowed_block_types_all`
    allow-list), with title, description, category and whether each can be written.
  - `devbase/get-block-schema`: one block's fields from its field group, with required, default, choices,
    repeater min/max, sub-fields and the shape of each value (image = attachment ID, link = object, ...).
  - `devbase/create-post` and `update-post` take `blocks`, `[{ "block": "acf/<name>", "fields": {...} }]`, as the
    page's whole content (not combined with `content`). The markup is produced by Secure Custom Fields' own
    save logic captured in memory (`acf_setup_meta()`), so the flat storage (`items: 2`, `items_0_title`,
    `_items_0_title`) is exactly what the editor writes. Everything is checked against the field group first:
    an unknown or disallowed block, a non-ACF block, an unknown field or sub-field at any depth, a choice that
    isn't one, a repeater that isn't a list, an image that isn't an attachment, all refused with the exact
    path, and nothing is saved.
  - `devbase/get-post`, `create-post` and `update-post` responses carry `built_from_blocks` and `blocks`, ACF
    blocks as `{ block, fields }` in the shape the writer takes, other blocks as `{ block, markup }`. Content
    written this way round-trips identically; a page saved in the editor renders the same after a round trip
    (every OCWOF page checked). Sub-fields never stored are left out of a read, so a round trip doesn't add
    empty values.
- **`devbase/upload-media`** (`inc/abilities/media.php`): adds one file from a public URL (fetched with
  `wp_safe_remote_get`, so private and local addresses are refused) or base64 data, with alt text, and returns
  its attachment ID. **Off by default**: Theme Settings > Miscellaneous > MCP Access > **Allow Media Uploads**.
  Needs `upload_files`; WordPress decides which file types are allowed. Base64 limited to 15 MB
  (filter `devbase_abilities_media_max_bytes`).
- The new switch is protected like the allow-lists: never readable or writable through MCP
  (`devbase_abilities_protected_option_fields()`, now the single list both options abilities use).
- 29 tests (`tests/Test_MCP_Abilities_Blocks.php`). Also checked on the OCWOF site's real blocks and pages.

### Changed

- `devbase_abilities_shape_fields()` takes an optional `$detailed` flag (used by the block schema). Without it
  the output of the existing schema abilities is unchanged.
- `tests/bootstrap.php` creates SCF's local meta store before any test runs. Created inside a test, it lost its
  hooks when the test framework restored them, and block values read back as defaults in later tests. Test-only.

## [1.5.0] — 2026-10-29

### Added

- **Dependabot** (`.github/dependabot.yml`): a weekly pull request when Timber, Twig or their Symfony
  dependencies have a new version (grouped into one), and a monthly one for the test tooling in `tests/`.
  The two wpackagist plugins are ignored, since sites update those daily on their own. `.github/` is not in
  the package.
- `bin/check-vendor-drift.sh` now also checks the other direction: every package in `composer.lock` must be
  in `vendor/` at the same version (read from Composer's `vendor/composer/installed.json`), and nothing extra.
  A Dependabot pull request changes the lock but not the committed `vendor/`, which is what a tag ships, so
  `bin/release.sh` and `bin/build-zip.sh` now refuse until `composer install` has been run and `vendor/`
  committed with the lock.

### Changed

- When the tag already exists, `bin/release.sh` now suggests `minor` for anything added and `patch` for
  fixes only, instead of always `patch`.

## [1.4.2] — 2026-10-29

### Added

- `bin/release.sh`, the gate before a release tag. It refuses unless the tree is clean and on `main`,
  `style.css` has an X.Y.Z version newer than every existing tag (and not already tagged locally or on
  `origin`), `CHANGELOG.md` has a dated, non-empty section for it directly under an empty `[Unreleased]`,
  the branch is not behind `origin`, `vendor/` matches `composer.lock`, and the test suite passes. Then,
  after a confirmation (`--yes` to skip, `--dry-run` to tag nothing), it creates the annotated tag and
  pushes the branch and the tag in one atomic push; a failed push removes the local tag. Tooling only:
  `bin/` is not in the package, so this release changes no code on a site.

### Changed

- The vendor drift check moved from `bin/build-zip.sh` into `bin/check-vendor-drift.sh`, shared by
  both scripts. Same check, same output.

## [1.4.1] — 2026-10-29

### Changed

- **reCAPTCHA no longer loads with the page.** Google's script is about 350 KiB, plus a frame, a stylesheet and a
  font, and it was loaded on every page that has a form, including a newsletter box in the footer. It now loads the
  first time a visitor touches a form that has a token field (focus, click, touch or key press), and a submit sent
  before a token has arrived is held for a moment, the token fetched, and the submit sent again. Measured in a real
  browser: a page view makes no request to Google at all (before, four or more, about 450 KiB); clicking into the form
  loads it. Only `assets/js/recaptcha-v3.js` is enqueued now, deferred, in the footer; Google's `api.js` is no longer
  enqueued by PHP. This also stops Google's own console message ("requestStorageAccess: Permission denied") appearing on
  every page: it comes from Google's frame and now only shows once a form is used.
- 2 tests changed to match.

## [1.4.0] — 2026-10-29

### Added

- **SEO tab on every post type archive.** An archive has no post, so no SEO plugin can attach a title and
  description to it. Each Archive options page now gets an **SEO** tab with **Search Title** (`seo_title`) and
  **Search Description** (`seo_description`): a real tab inside the field group that already targets the page,
  or a small "SEO" box of its own where there is none (`inc/archive/seo.php`).
- The theme prints them on the archive's own URL, only when filled. The title replaces the document title
  (with " – Page N" after the first page). The description is printed on the first page only, by buffering
  `wp_head` for archive requests and replacing any existing description tag, or adding one, so there is never
  a duplicate whether or not an SEO plugin is active. Open Graph and Twitter title and description tags that
  something has already printed are updated to match; none are added.
- The fields are visible to MCP (`devbase/update-options` on `archive-{post_type}`).
- Filter `devbase/archive_seo/enabled` to switch it off for a post type.
- 18 tests.

## [1.3.0] — 2026-10-29

### Changed

- **reCAPTCHA is now v3 (score-based, invisible) instead of the v2 checkbox. A v2 key no longer works.**
  A site that uses reCAPTCHA must enter a v3 key on Theme Settings > Security > reCAPTCHA (or switch to
  Turnstile). The theme rendered the v2 checkbox widget, so a v3 key, or any key made in Google Cloud as
  score-based or Enterprise, showed "ERROR for site owner: Invalid key type".
- Forms now carry a hidden `g-recaptcha-response` field filled by the new `assets/js/recaptcha-v3.js`, which
  keeps a fresh one-use token in it (refreshed every 90 seconds, when the tab returns to the front, and after
  each submit). Google's script is loaded as `api.js?render=<site key>`.
- The server accepts a token only when Google reports success, the token's action matches (`submit`,
  filterable: `devbase/security/recaptcha_action`), and the score is at least the minimum. An answer with
  no score fails.

### Added

- **Minimum Score** setting on the reCAPTCHA tab (0.1 to 1, default 0.5).
- 13 tests for the v3 widget, script and verification.

## [1.2.8] — 2026-10-29

### Changed

- `screenshot.png` now says "Built by Momar" at the bottom right. No code change.

## [1.2.7] — 2026-10-29

### Added

- `screenshot.png` (1200x900): the preview shown for the theme in Appearance > Themes. It was missing, so the
  theme showed an empty box. It shows the parent/child layering, with no version number so it never goes stale.

## [1.2.6] — 2026-10-29

### Changed

- The theme's display name is now "Dev Base Parent Theme (DO NOT REMOVE)", so nobody deletes it from
  Appearance > Themes thinking it is unused: the active child theme depends on it. Display name only; the
  folder name `wp_devbase_parent_theme`, which is what the child's `Template:` header and Warden use, is
  unchanged.

## [1.2.5] — 2026-10-29

### Changed

- A release tag now publishes the same files `bin/build-zip.sh` does. Warden publishes GitHub's archive of the
  tag, so the development files are marked `export-ignore` in a new `.gitattributes`: `tests/`, `bin/`,
  `docs/`, `.github/`, `phpunit.xml.dist`, the development `README`/`CHANGELOG`/`ROADMAP`/`SECURITY`,
  translation sources and the dotfiles. Before, a tag shipped the whole repository to client sites, so those
  files were publicly readable on them. `License.md` still ships. No code change.

## [1.2.4] — 2026-10-29

### Fixed

- **MCP options abilities wrote to the wrong storage for the Archive pages.** `devbase/update-options`
  and `devbase/get-options` always used the shared `option` store, but the Archive pages created for each
  post type with an archive keep their values under their own storage (their menu slug), which is what
  their templates read. A value written through MCP to an Archive page was saved where nothing reads it,
  the same-named fields of different archives (`hero_title`, `hero_image`, ...) overwrote each other there,
  and the call reported success because it read the value back from the same wrong place. Both abilities
  now use the page's own storage, and other options pages are unaffected. Nothing saved by the old
  behaviour is migrated: write the Archive pages' values again through MCP (or the admin).
- 4 new tests that fail on the old code.

## [1.2.3] — 2026-10-29

### Fixed

- The credit markup now matches Brand Relay's reference snippets: `message <a href rel>text</a>`, with
  `rel` only when non-empty and no `target`. It previously added `target="_blank"` and `rel="noopener"`
  to every link.
- A `status: "unavailable"` answer now keeps the last good credit instead of being stored, and a
  `show: false` answer without a link now replaces the old credit instead of being rejected (which left
  a credit the brand had turned off showing).

## [1.2.2] — 2026-10-29

### Removed

- The "Current Credit" status block on Theme Settings > Miscellaneous > Credit ("Downloaded … ago",
  the credit preview, the last error). The tab is now just the one field; the credit downloads and is
  used without anything about it appearing in the admin. A failed download is still written to the PHP
  error log.

## [1.2.1] — 2026-10-29

### Added

- `DEVBASE_CREDIT_BRAND_URL` constant: sets the credit's brand address in code and removes the
  Credit tab from Theme Settings > Miscellaneous, so a client with admin access doesn't see it. The
  credit itself is unchanged. Filters `devbase/credit/brand_url` and `devbase/credit/hide_settings`.

## [1.2.0] — 2026-10-29

### Added

- **Brand credit** (`inc/theme-settings/miscellaneous/credit.php`). Theme Settings > Miscellaneous >
  **Credit** takes one setting, a brand's public Brand Relay address. The theme downloads the
  brand's public details (on save, shortly after first set, then daily; Brand Relay's `ttl` is a
  minimum gap), keeps a local copy in one non-autoloaded option, and builds the credit from it.
  `devbase_credit()` returns the data and `devbase_credit_html()` returns the escaped markup.
- Pages never wait on the network: rendering reads the saved copy only. A failed download keeps the
  last good copy; a brand marked `show: false` shows no credit; a changed address drops the old copy.
- The saved copy is built from an allowlist of fields, never stored whole, so `params`, `rules`,
  `embed` and private values cannot reach it, and remote HTML/CSS is never printed.
- 17 PHPUnit tests, including one proving a licence key in the response never reaches the stored copy.

## [1.1.0] — 2026-10-29

### Added

- **Provisioning from Brand Relay** (`inc/updates/relay-sync.php`). The Warden URL, Warden public
  key and both licence keys are delivered by [Brand Relay](https://github.com/momardia54/brand-relay)
  and written into the Licence page, so a licence can be set or fixed from a distance and a value
  removed on the site is restored by the next sync. Brand Relay params use the same names as the
  fields (`warden_base_url`, `warden_public_key`, `warden_parent_licence`, `warden_child_licence`).
  The Warden URL and public key are fill-only: accepted when empty, never overwritten.
- **Ping endpoint** `POST /wp-json/devbase/v1/sync`. It carries no licence data and only makes the site
  pull from its configured Brand Relay. It requires the `Origin` to equal the Brand Relay URL and an
  HMAC-SHA256 signature (derived from the site key) over timestamp, nonce, origin and body, with a
  five-minute window and single-use nonces. Rate limited to 10 requests a minute per IP (signed or
  not) and one sync per 30 seconds. The route exists only when a Brand Relay URL and site key are set.
- Theme Settings > Licence > **Brand Relay** tab: Brand Relay URL and Brand Relay Site Key, the only
  two fields to fill in. `DEVBASE_RELAY_URL` and `DEVBASE_RELAY_SITE_KEY` constants win over the
  fields, which then show locked, so a site's users cannot clear them.
- A sync status line on the Licence page, and a sync step in "Check for Updates Now".
- 21 PHPUnit tests for the sync and the ping.

### Changed

- The four Warden fields on the Licence page are now always shown but not editable, and the red
  message reads "These fields are provisioned automatically. Please contact the developer for any
  licence-related issue."
- There is no scheduled sync. It runs only on a signed ping, when an administrator saves the Licence
  page, or on "Check for Updates Now".

## [1.0.1] — 2026-10-28

### Changed

- Theme Settings > Licence: the two licence keys are now masked (password fields), and the
  page opens with a red warning not to edit it and to contact the developer for any licence issue.

### Removed

- The "Install Updates Automatically" setting. Theme updates always install themselves; a
  leftover stored value is ignored.

### Fixed

- Updates from Warden were never offered: `devbase_warden_pending_update()`
  read `update.version` and `update.download_url` at the top level, but Warden
  documents (and sends) `update.available` with the release nested under
  `update.latest`. A real release showed as "Up to date" on the Licence status
  tab and in WordPress's update list. It now reads the documented shape, with
  tests. Sites already running the old code must install the fixed
  `inc/updates/warden.php` by hand once; after that updates flow normally.

## [1.0.0] — 2026-10-11

Initial release.

### Added

**Forms**

- `registered_form` and `form_data` post types, with an admin-only
  capability model — editors and authors cannot read submitted data.
- A JSON form registry that syncs into admin posts, extensible by a child
  theme through the `devbase/forms/registered_forms` filter.
- Three public helpers covering the whole submission path:
  `devbase_form_nonce_field()`, `devbase_form_build_content()` and
  `devbase_form_log_submission()`.
- Per-form CSRF nonces, verified before anything else.
- Automatic dispatch to email and webhook destinations, matched per form.
- An optional retention policy, off by default, with a manual run button
  and a staleness indicator.

**SMTP**

- SMTP transport with connection fields that are required only while the
  feature is enabled.
- A test-email button that reports the real PHPMailer error.

**Security**

- Cloudflare Turnstile and Google reCAPTCHA v2, mutually exclusive and
  enforced server-side.
- `devbase_security_widget_html()` and `devbase_security_verify_request()`
  so a form never needs to know which provider is active.

**Tracking**

- Google Tag Manager and GA4 IDs, plus raw header and footer script fields.

**Schema**

- Organization, Website and Breadcrumb JSON-LD, each independently
  toggleable, each emitting its own script tag.
- A `devbase/schema/tabs` filter so a child theme can contribute a type.

**Archives**

- An options page per CPT archive, with isolated storage per archive.
- CPT archives injected into the internal link picker.

**MCP**

- Content abilities — list, read, create and update posts and options
  values — generic across every post type.
- An opt-in allow-list; nothing is exposed until explicitly enabled.
- The allow-list fields are themselves excluded from those abilities, so an
  MCP client cannot widen its own access.

**Updates**

- Vendored Secure Custom Fields, Admin Site Enhancements and MCP Adapter,
  each tracking upstream on a daily schedule that runs from any request
  rather than only in wp-admin.
- Theme updates delivered from [Warden](https://github.com/momardia54/Warden), a self-hosted licensing
  and release service, verified
  with Ed25519 signatures and nonce replay protection, installed through
  WordPress's own upgrader.
- A Licence settings page with per-theme keys, a live status view and a
  manual check button.
- Visible last-run status for every scheduled check, because a check that
  silently stops otherwise looks identical to one with nothing to do.

**Child theme support**

- Filters covering admin-hardening defaults, hidden admin menu items, form
  registration and schema types, so a child theme adds what it needs and
  inherits everything else.

**Internationalisation**

- Full French translations: `fr_FR`, `fr_CA` and `fr_BE`.

**Tooling**

- `bin/build-zip.sh`, gated on both dependency-version drift and the test
  suite.
- `bin/bump-version.sh`, which refuses to move a version backwards.
- 142 PHPUnit tests running against a real WordPress install.

[Unreleased]: https://github.com/momardia54/devbase-parent-theme/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/momardia54/devbase-parent-theme/releases/tag/v1.0.0
