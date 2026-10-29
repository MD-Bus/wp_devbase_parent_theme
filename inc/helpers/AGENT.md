# inc/helpers/ — public, dev-facing helper functions

Where a function meant to be *called* — by a form, a template, a future
child theme, whoever builds on top of this base theme — lives, as opposed
to the internal machinery of whatever feature it fronts. The distinction
that decides what belongs here: would a dev building a site on this theme
ever need to know this function's name and call it directly? If yes, it
belongs here, documented as a real public API, not buried inside the
feature's own internal folder alongside CPT registration and hook
plumbing a caller never needs to think about.

```
inc/helpers/
  AGENT.md            — this file
  forms/                 — helpers for the centralized form-logging feature
    AGENT.md               — full breakdown, see there
    log-submission.php       — devbase_form_log_submission()
    build-content.php          — devbase_form_build_content()
    nonce-field.php               — devbase_form_nonce_field()
```

## The pattern: one subfolder per feature area

Same "one subsystem, one subfolder, one `AGENT.md`" rule as everywhere
else in this theme (`inc/theme-settings/smtp/` is the template most other
docs point to; this is the same idea applied to public functions instead
of admin settings pages). When a feature's internal implementation
(`inc/forms/`, say) exposes a function meant to be called from outside
that feature, the function itself moves here, into a subfolder named for
the feature it fronts — the feature's own directory keeps everything that
supports that call (CPTs, hooks, admin UI) but never needs to be read by
whoever's just calling the helper.

**Why split a helper's file from the feature's own folder, when they're
part of "the same feature"?** Because they have different audiences.
`inc/forms/AGENT.md` and its subfolders are for whoever maintains the
form-logging system itself. `inc/helpers/forms/AGENT.md` is for whoever
is just building a form and needs to know: what do I call, what does it
expect, what does it return. Keeping the public surface small and
separate is what makes "here's everything you as a form-builder need to
read" a one-file answer instead of "go read the whole subsystem." In
practice a helper here is usually only ever *reacted to* by its feature's
internals (e.g. `devbase_form_log_submission()` inserts a post that
`inc/forms/dispatch/orchestrator.php` picks up via a WordPress action
hook, not a direct function call) — so moving the helper out costs
nothing structurally; it was never tightly coupled to the internals at
the file level to begin with.

When adding a new helper:
1. Decide if it's genuinely public (a dev calling it directly) vs.
   internal (only ever called by other files inside the same feature). If
   internal, it stays inside that feature's own folder — not everything a
   feature exposes needs to land here.
2. Create `inc/helpers/<area>/` if one doesn't exist yet, with its own
   `AGENT.md`.
3. Require it explicitly from `functions.php`, same as everywhere else in
   this theme — no directory auto-loading.
4. Link the new subfolder from this file's tree/list above.

## Forms

See **`inc/helpers/forms/AGENT.md`**: `devbase_form_nonce_field()` +
`devbase_form_build_content()` + `devbase_form_log_submission()`, the
three calls any form uses to turn `$_POST` into CSRF protection, logging,
spam-gating, and notification dispatch — no per-form plumbing.
