<?php

// Composer autoloader, needed by Timber and any other vendored library below.
require_once __DIR__ . '/vendor/autoload.php';

// Loads translation files for this theme's text domain.
require_once __DIR__ . '/languages/i18n.php';

// Boots Timber so Twig templates can be rendered.
require_once __DIR__ . '/inc/timber.php';

// Loads SCF (Secure Custom Fields) from vendor/ and fixes its asset URLs.
require_once __DIR__ . '/inc/dependencies/scf/scf.php';

// Loads Admin Site Enhancements from vendor/ and fixes its asset URLs.
require_once __DIR__ . '/inc/dependencies/ase/ase.php';

// Applies our standard Admin Site Enhancements module defaults.
require_once __DIR__ . '/inc/dependencies/ase/ase-settings.php';

// Creates the DB table ASE's Limit Login Attempts module needs, since its
// own activation hook never fires under our vendor/-loading setup.
require_once __DIR__ . '/inc/dependencies/ase/ase-limit-login-table.php';

// Loads the official WordPress MCP Adapter from vendor/, exposing SCF's
// registered CPT/field abilities (and any others) as MCP tools.
require_once __DIR__ . '/inc/dependencies/mcp-adapter/mcp-adapter.php';

// Registers this theme's own content abilities (list/get/create/update
// posts and options-page values, generic across every post type) so the
// MCP Adapter above has something beyond SCF's schema-only abilities to
// expose.
require_once __DIR__ . '/inc/abilities/categories.php';
require_once __DIR__ . '/inc/abilities/helpers.php';
require_once __DIR__ . '/inc/abilities/post-types.php';
require_once __DIR__ . '/inc/abilities/posts.php';
require_once __DIR__ . '/inc/abilities/options-pages.php';
require_once __DIR__ . '/inc/abilities/blocks.php';
require_once __DIR__ . '/inc/abilities/media.php';

// Hides Posts and Comments from the wp-admin menu while this theme is active.
require_once __DIR__ . '/inc/hide-admin-menu-items.php';

// The vendored dependencies only change in a theme release (Dependabot and a
// release workflow notify, a release ships). This reports the bundled
// versions and clears the retired daily update checks from older sites.
require_once __DIR__ . '/inc/dependencies/bundled-versions.php';

// Delivers updates for this theme AND the active child theme from Warden,
// through WordPress's own theme-update pipeline. warden.php is the signed
// API client; theme-updates.php is the wiring into core. Both depend on the
// accessors in inc/theme-settings/licence/settings.php below, but only at
// hook time (never at require time), so this order is fine — see
// inc/updates/AGENT.md.
require_once __DIR__ . '/inc/updates/warden.php';
require_once __DIR__ . '/inc/updates/theme-updates.php';

// Provisions the Warden address, signing key and licence keys from Brand Relay,
// only when pinged (signed) or when an administrator saves the Licence page.
// Inert until a Brand Relay URL and site key are entered on Theme Settings >
// Licence > Brand Relay.
require_once __DIR__ . '/inc/updates/relay-sync.php';


// Registers the (currently empty) Theme Settings options page.
require_once __DIR__ . '/inc/theme-settings/theme-settings.php';

// Registers the Licence sub-page (Warden endpoint, signing key, one
// licence key per theme track) and the accessors inc/updates/ reads.
// status.php only renders the read-only Status tab, and must load before
// settings.php, which calls devbase_licence_status_html() while building
// its field group.
require_once __DIR__ . '/inc/theme-settings/licence/status.php';
require_once __DIR__ . '/inc/theme-settings/licence/settings.php';

// AJAX handler for that page's "Check for Updates Now" button — runs every
// update check immediately instead of waiting for its daily schedule.
require_once __DIR__ . '/inc/theme-settings/licence/check-ajax.php';

// Registers the SMTP Settings sub-page.
require_once __DIR__ . '/inc/theme-settings/smtp/settings.php';

// Routes outgoing mail through the configured SMTP server, once enabled.
require_once __DIR__ . '/inc/theme-settings/smtp/mailer.php';

// AJAX handler for the "Send Test Email" button on the SMTP Settings page.
require_once __DIR__ . '/inc/theme-settings/smtp/test.php';

// Registers the Security Settings sub-page (Turnstile / reCAPTCHA tabs).
require_once __DIR__ . '/inc/theme-settings/security/settings.php';

// Reusable functions forms call to render/verify whichever provider is active.
require_once __DIR__ . '/inc/theme-settings/security/verify.php';

// Registers the Tracking sub-page (Analytics / Custom Scripts tabs).
require_once __DIR__ . '/inc/theme-settings/tracking/settings.php';

// Injects the configured GTM/GA4/custom scripts into the front end.
require_once __DIR__ . '/inc/theme-settings/tracking/inject.php';

// Registers the Schema Markup sub-page, and assembles one field group
// with a tab per schema type from each type's 'devbase/schema/tabs'
// contribution below (Organization / Website / Breadcrumb tabs, not three
// separate stacked boxes). Require order among these five files doesn't
// matter — contributions register via add_filter() immediately, and the
// assembly itself runs on a later acf/init priority.
require_once __DIR__ . '/inc/theme-settings/schema/settings.php';

// Contributes the Organization tab.
require_once __DIR__ . '/inc/theme-settings/schema/organization/settings.php';

// Prints the Organization JSON-LD block sitewide in wp_head.
require_once __DIR__ . '/inc/theme-settings/schema/organization/inject.php';

// Contributes the Website tab.
require_once __DIR__ . '/inc/theme-settings/schema/website/settings.php';

// Prints the WebSite JSON-LD block sitewide in wp_head.
require_once __DIR__ . '/inc/theme-settings/schema/website/inject.php';

// Contributes the Breadcrumb tab.
require_once __DIR__ . '/inc/theme-settings/schema/breadcrumb/settings.php';

// Prints the BreadcrumbList JSON-LD block, computed per-request.
require_once __DIR__ . '/inc/theme-settings/schema/breadcrumb/inject.php';

// Registers the theme's first two CPTs: registered_form (one per form
// name) and form_data (one per submission). Foundational — everything
// else in inc/forms/ depends on these existing.
require_once __DIR__ . '/inc/forms/post-types.php';

// Syncs inc/forms/registry/registered-forms.json into registered_form posts.
require_once __DIR__ . '/inc/forms/registry/sync.php';

// Shared "which registered forms exist" lookup — used by both Form
// Destinations' and Miscellaneous's "Applies To" dynamic choices below.
require_once __DIR__ . '/inc/forms/registry/choices.php';

// Registers the Form Destinations sub-page: one repeater where each row
// is a self-contained destination (SMTP or webhook) plus which
// registered form(s) it applies to.
require_once __DIR__ . '/inc/theme-settings/destinations/settings.php';

// Schedules and runs the daily retention purge (off unless enabled on
// the Miscellaneous > Retention Policy tab below).
require_once __DIR__ . '/inc/forms/retention.php';

// Aggregates recent dispatch successes/failures across submissions, for
// the Miscellaneous > System Status tab below.
require_once __DIR__ . '/inc/forms/dispatch/status.php';

// Registers the Miscellaneous sub-page: Retention Policy, and System
// Status (SMTP/dispatch/retention health in one place).
require_once __DIR__ . '/inc/theme-settings/miscellaneous/settings.php';
require_once __DIR__ . '/inc/theme-settings/miscellaneous/purge-ajax.php';

// The brand credit: downloads a brand's public details from Brand Relay, keeps a
// local copy and builds the credit from it (Miscellaneous > Credit). Must load
// before settings.php runs on acf/init, which calls its status function.
require_once __DIR__ . '/inc/theme-settings/miscellaneous/credit.php';

// devbase_form_log_submission() — the one function form authors call.
// devbase_form_build_content() — turns $_POST into the array it expects.
// devbase_form_nonce_field() — the CSRF field log_submission() requires.
// All three live in inc/helpers/, not inc/forms/, since they're the
// public surface of the feature, not internal machinery — see
// inc/helpers/AGENT.md.
require_once __DIR__ . '/inc/helpers/forms/log-submission.php';
require_once __DIR__ . '/inc/helpers/forms/build-content.php';
require_once __DIR__ . '/inc/helpers/forms/nonce-field.php';

// Per-channel submission senders, required before dispatch/orchestrator.php
// which calls both.
require_once __DIR__ . '/inc/forms/dispatch/smtp.php';
require_once __DIR__ . '/inc/forms/dispatch/webhook.php';

// Fires automatically on every new form_data post: finds every
// destination whose "Applies To" includes this form and sends to each.
require_once __DIR__ . '/inc/forms/dispatch/orchestrator.php';

// Read-only meta boxes for the two CPTs above, since both only support a
// title and store everything else in postmeta/an options page: submitted
// fields + dispatch log on form_data, and (since destinations no longer
// live on this CPT) a reverse lookup of which destinations currently
// apply to it on registered_form.
require_once __DIR__ . '/inc/forms/form-data-admin-display.php';
require_once __DIR__ . '/inc/forms/registered-form-admin-display.php';

// Proof-of-concept test form exercising the whole flow above end to end.
require_once __DIR__ . '/inc/forms/test-form.php';

// Registers the Archive options page + a sub-page per CPT archive, so
// fields can be attached to a post type archive despite SCF/ACF having
// no location rule for one.
require_once __DIR__ . '/inc/archive/settings.php';

// SEO title and description for each archive (an "SEO" tab on its Archive options page), printed on the
// archive's own URL, since no SEO plugin can attach to an archive.
require_once __DIR__ . '/inc/archive/seo.php';

// Makes CPT archive links show up in the WP internal link picker (used by
// ACF/SCF link fields and the editor's link dialog), which otherwise only
// searches actual posts and has no notion of an archive URL.
require_once __DIR__ . '/inc/archive/link-query.php';
