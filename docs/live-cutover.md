# Live cutover: one Warden licence key per site, provisioned by Brand Relay

Moves every live site from the two old per-theme licence keys to the single `warden_licence_key` that Brand Relay
provisions (parent theme 1.34.0 and later). Run it once per site, or once per brand when the brand holds the
parameter. Nothing here changes code; it is all done in the Brand Relay dashboard and on the site.

## Before you start

- **Which Brand Relay is live, and its version.** Everything below needs Brand Relay 0.18 or later. The live worker has
  no version endpoint: match its newest deployment (`npx wrangler deployments list --name <worker>` in the account
  that holds it) to `git log --format='%h %ci %s' develop` in the Brand Relay repo. Where it runs is in the theme's
  private notes (`AGENT.md`), not here.
- **The site runs parent 1.34.0 or later** (Theme Settings > Licence & Updates > Status shows the version). Older
  themes fill the Warden fields only when they are empty and know nothing of `warden_licence_key`.
- **The site has its Brand Relay URL and site key.** Those are entered by hand on the site and never synced.
- **Know the site's licence key in Warden.** One licence per site, holding both apps (the parent and the child tag).

## Steps

1. **Add the new parameter.** In Brand Relay, on the site (or its brand), add a parameter `warden_licence_key`,
   type Text, value = the site's Warden licence key. A site parameter replaces the brand's parameter of the same key.
   Check that `warden_base_url` (https only) and `warden_public_key` are also there and current.
2. **Remove the two old parameters:** `warden_parent_licence` and `warden_child_licence`. The theme no longer reads
   them; leaving them only confuses whoever reads the site's parameters later.
3. **Trigger a sync.** Press **Sync now** on the site's page in Brand Relay. The site pulls `/v1/site` and answers with
   what it changed and what it refused. Expect `warden_licence_key` under changed (or nothing, if it already held it).
   A value set in the site's code wins and is reported as refused: that is expected only on a site that pins it.
4. **Check the site.** On Theme Settings > Licence & Updates:
   - the Licence Key field shows a key is saved (the value itself is never shown);
   - press **Check licence and updates now** (Dashboard > Updates "Check again" does not refresh Warden's cached
     answer, which lasts up to 12 hours);
   - the Status tab shows **both themes**, the parent and the child, each under its own tag, each `active`, with the
     installed and latest versions.

   The old per-theme keys are deleted from the site on its own: when the new key arrives through the sync, and on the
   next wp-admin page load if any are left.
5. **Check Warden.** The licence's last check time moves for both apps. If one app shows no check, the licence is
   missing that app's tag (Warden answers "this licence has no app tagged ..." and the site's log says the same).

## If something goes wrong

- **Sync now answers 401/403:** the site key was revoked, or the site has no key that can sign pings (keys made before
  Brand Relay 0.11). Make a new key and enter it on the site.
- **The Status tab shows "response was for a different licence key" or a signature failure:** `warden_public_key` or
  `warden_base_url` is wrong for this Warden. Fix the parameter and sync again.
- **One theme is missing from the Status tab:** add the missing app to the site's licence in Warden, then press
  **Check licence and updates now** again.
- **Rolling back:** put the old key back as the `warden_licence_key` value and sync. An empty parameter means "no
  change", never "clear": to cut a site off, revoke its licence in Warden instead.
