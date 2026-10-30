# Roadmap

What is planned, what is being considered, and what has been deliberately
ruled out. Not a schedule — this is a tool built alongside client work, so
items land when a project needs them.

## Next

Nothing is planned right now: the release gate, CI, Dependabot, MCP blocks
and the live suspend test are done. The next items come from "Considered"
below when a project needs them.

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
completeness.

**WooCommerce.** Store clients bring bugs of their own, and the worry is that
the fix has to be made once per client if the code is copied into each child.
Two ways to avoid that, in order of preference. One codebase, two builds: the
WooCommerce files live in this repository (for example `inc/woocommerce/`), the
build script gets a flavour, and each build is released on its own Warden
track (`wp_devbase_parent_theme` and a store parent). A shared fix is written
once and released on both tracks, while a store-only release reaches only
stores, which is the isolation a separate parent would give. Or an opt-in
module in this parent, loaded only when `class_exists( 'WooCommerce' )`, which
is one copy but means a bad release can reach every site. A second, separate
repository for the store parent is the weakest option: every shared fix has to
be merged across by hand. Whichever it is, it needs an exception in the editor's
block allow-list for WooCommerce's blocks, a check of what suspension does to
cart and checkout requests, and a test of an update on a staging store with a
real checkout before it ships. Build it into the first store's child first,
then lift what two stores share.

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
