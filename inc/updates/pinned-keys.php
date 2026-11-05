<?php

// The Warden public keys this theme trusts, pinned in code (Warden's docs/checking.md: "the
// public key, which you put in the site's code").
//
// EMPTY = the old behaviour: the key provisioned by Brand Relay into Theme Settings > Licence is
// used. Once a key is listed here, that field is ignored, and neither a database edit nor a Brand
// Relay account can change which signature this site trusts.
//
// HOW TO FILL IT, safely:
//   1. Copy the key from Warden (Settings > Signing key, or GET {warden}/api/v1/signing-key):
//      the base64 `public_key`, 44 characters ending in "=". It must be EXACTLY the key the sites
//      already have in their Licence field, or every site would reject every answer from then on
//      (and could only be fixed by hand).
//   2. Paste it below, release the theme, check one site's Licence > Status says the check works.
//
// ROTATING: add the new key next to the old one, release, switch Warden to the new key, and drop
// the old one in a later release.
return array(
	// 'base64-public-key-from-warden=',
);
