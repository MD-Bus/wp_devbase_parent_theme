# Security Policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.**

Report it privately through GitHub's
[security advisory](../../security/advisories/new) form on this
repository. That keeps the report private until a fix is available and
gives you credit in the advisory if you want it.

Please include what you can:

- what the issue is and roughly how severe you think it is
- the steps to reproduce it
- the theme version (the `Version:` header in `style.css`) and the
  WordPress and PHP versions
- whether it needs an authenticated user, and at what role

You should get an acknowledgement within a few days. A fix ships as a
patch release, and the advisory is published once sites have had a
reasonable window to update.

## Supported versions

The latest release receives security fixes. This project is distributed to
client sites that update themselves to the latest release, so fixes are
expected to reach sites rather than being backported to older lines. That
includes the bundled dependencies: they change only in a release, so a fix in
one of them reaches sites as a theme release.

## What this theme does with trust

Worth knowing when assessing a report. These are deliberate positions, not
oversights.

### Update delivery is signed and verified

Theme updates come from [Warden](https://github.com/momardia54/Warden), a self-hosted licensing and
release service, rather than from WordPress.org. Every response is verified with an **Ed25519 signature**
over the raw body before any part of it is trusted, and each request
carries a nonce that the response must echo. A response that fails either
check is treated as a transport error, never as a verdict.

That matters most in one direction: the response carries a download URL
that WordPress will install as the active theme. An unverified response
must never be able to supply one.

If libsodium is unavailable, verification **fails** rather than being
skipped — "we could not check" behaves exactly like "the check failed".

Failure is soft but bounded: on an outage the last verified response
stands, so a service problem cannot lock a site out or make a valid
licence look invalid. It stops authorising installs after seven days, so
a response that can no longer be re-verified cannot keep approving updates
indefinitely.

### MCP access is opt-in and nothing is exposed by default

The theme can expose content operations (list, read, create, update) to an
MCP client. Nothing is reachable until an administrator explicitly ticks
it under Theme Settings → Miscellaneous → MCP Access. A newly registered
post type starts fully locked down.

Two post types — the form registry and submission log — are excluded
unconditionally and cannot be opted in, because submissions can contain
personal data.

The allow-list fields themselves are excluded from the abilities that read
and write options, so an MCP client cannot widen its own access.

Content operations dispatch through WordPress's own REST API as the
authenticated user, so core's capability checks apply on top of the
theme's own.

### Form submissions

Submission logging verifies a per-form nonce before anything else, and the
nonce action is namespaced per form so a token for one form cannot be
replayed against another. Submissions are then gated by whichever CAPTCHA
provider is configured.

The submission log is not exposed through the REST API and its post type
requires `manage_options` throughout, so editors and authors cannot read
submitted data. Submissions cannot be created by hand in wp-admin — only a
real front-end submission creates one.

An optional retention policy permanently deletes submissions after a
configured period. It is off by default; submissions are kept until
someone decides otherwise.

### Deliberately unescaped output

The Tracking settings' header and footer script fields are output as raw
HTML. That is the entire purpose of a "paste a tracking snippet here"
field — escaping it would break every real snippet. It is exactly as safe
as the trust placed in whoever can reach Theme Settings, which already
requires `manage_options`. Reports that these fields allow script
injection by an administrator will be closed as working as intended.

### Credentials

SMTP passwords and CAPTCHA secrets are stored in the options table as
WordPress stores any setting. They are not encrypted at rest; anyone with
database access or `manage_options` can read them. Treat them as
site-level secrets, not user secrets.

## Out of scope

- Vulnerabilities in bundled third-party code should be reported to that
  project. If a bundled version is outdated here, that is a valid report
  against this repository.
- Issues requiring `manage_options`, which already implies full control of
  the site.
- Missing hardening that WordPress itself does not do by default, unless
  there is a concrete exploit path.
