# Roadmap

What is planned, what is being considered, and what has been deliberately
ruled out. Not a schedule — this is a tool built alongside client work, so
items land when a project needs them.

## Next

**Release checks before a tag.** A release is now just a pushed tag:
Warden publishes GitHub's archive of it, so nothing runs `bin/build-zip.sh`
and its gates (the test suite, the vendored-version drift check) before a
site receives it. Nothing checks either that `style.css` and `CHANGELOG.md`
carry the version being tagged, and a tag that is wrong cannot be fully
recalled once sites have installed it. First a `bin/release.sh` that refuses
unless the tree is clean, the suite passes, the version in `style.css` and
the changelog matches the tag, and then creates the annotated tag and pushes
it. After that, continuous integration: a workflow running the suite on every
push and tag (it needs a MySQL service and a generated `wp-tests-config.php`).
A workflow cannot stop Warden publishing, since Warden acts on the push
itself, so it warns and a release is withdrawn in Warden; the script is the
real gate.

**MCP: blocks.** MCP can write a page's content but cannot see the blocks
that content is made of: no ability lists the registered blocks or describes
their fields, and `get-post-type-schema` does not report them because block
fields belong to the block, not to the post type. A tool connected only
through MCP therefore cannot build a page from the site's blocks, and its
reads show one opaque string. The parent should discover whatever the active
child registers, not know any site's blocks:

- `devbase/list-blocks`: the registered blocks the editor allows (name,
  title, category).
- `devbase/get-block-schema`: one block's fields (name, type, choices,
  default), read from its field group, including which are images.
- Create or update a page from a list of blocks, such as
  `[{ block: "acf/home-hero", fields: { ... } }]`, with the parent writing the
  markup. This is the hard part: ACF stores block values flat, with a hidden
  reference beside each, and repeater rows flattened into keys like
  `items_0_image`. Unknown blocks and fields must be rejected, and it needs
  its own tests.
- Post responses say whether a page is built from blocks, and which.

Block image fields hold attachment IDs and MCP has no upload ability, so this
goes with an upload ability (or documents the media REST route). It must follow
the editor's block allow-list and the same admin-only check as the other
abilities. A new minor version.

**Automated dependency notifications.** Timber, Twig and their
dependencies have no auto-update path: they move only when someone runs
`composer update` and cuts a release, and nothing reports that a new
version exists. Dependabot would turn that into a pull request.

**A full suspend-and-restore round trip.** The update client has been
verified against a locally generated keypair (signature verification, nonce
replay rejection, every failure mode), and releases published through Warden
tags have since been installed on a live site. What has not run end to end on
a live site is a child theme's payment kill switch: setting a licence to
Suspended in Warden, watching the site lock at its next check, and restoring it
by setting it back to Active.

## Considered

**A header and footer template pair.** The theme ships one placeholder
template on the basis that templates are what every project replaces. If
most child themes end up writing the same layout scaffolding, that
judgement was wrong and a base layout should move here.

**More schema types.** The current three are the ones nearly every site
needs. Product, FAQ and Review come up often enough that a shared
implementation may eventually earn its place — but each additional type is
a maintenance commitment against a spec that changes.

**A richer MCP surface.** Deleting posts, managing taxonomy terms and
media operations (an upload is needed by the blocks work above) are all
plausible. Each widens what an AI client can do
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
