# OTA bundle signing (end-to-end encryption)

Self-hosted OTA updates are delivered by `@capgo/capacitor-updater` from our own
server. A bundle that is only **SHA256-verified** is protected against
corruption, not against a malicious bundle. Because OTA ships JavaScript to a
**checkout** app, bundles are **signed**: anyone able to spoof the update
endpoint, write to `var/uploads/ota`, or use a leaked admin/OTA token could
otherwise push arbitrary code to every user.

Signing uses a keypair: the **private** key signs bundles on your release
machine; the **public** key is embedded in the app and verifies every bundle
on-device. A bundle that doesn't verify is rejected.

**Signing is enforced.** Every OTA-capable store build carries the public key
(`capacitor.config.ts`, since `2e13bc2d`). With a key set, the iOS plugin
rejects every bundle without an RSA-encrypted checksum, and Android would
install an unsigned bundle with no signature check at all. So the API, the
portal, `ota:publish` and the release script refuse unsigned bundles for iOS
(and `both`) and for the `production` channel.

Always use the pinned CLI, **`@capgo/cli@8.68.1`** (never `@latest`): it reads
the private key, and its output format is what these docs and
`scripts/ota-release.mjs` expect.

## One-time setup (already done for the shipped shells)

Run in `apps/mobile`:

```bash
npx @capgo/cli@8.68.1 key create
```

This:
- generates `.capgo_key_v2` (**private — keep secret**) and `.capgo_key_v2.pub`
  (public),
- writes the **public** key into `capacitor.config.ts` (`CapacitorUpdater.publicKey`).

Then:
- **Commit** `capacitor.config.ts` (the public key is meant to be embedded) and,
  if you like, `.capgo_key_v2.pub`.
- **Never commit `.capgo_key_v2`** — it's already in `.gitignore`. Store it in
  your password manager / CI secrets. If it leaks, run `key create` again and
  ship a new native build.
- Ship a **new store build** so devices carry the public key. Only builds with
  the public key can receive **signed** bundles.

## Per-release

```bash
# 1. build the web bundle
npm run build                                   # -> apps/mobile/www

# 2. zip it + get the plain SHA256 (checksum of the *unencrypted* zip)
npx @capgo/cli@8.68.1 bundle zip com.threebayti.app --path www --name com.threebayti.app_<version>.zip --json
#    -> { "bundle": "<package.json version>", "filename": "<zip>", "checksum": "<sha256>" }
#    The zip is `filename`; `bundle` is only a version label, not a path.

# 3. encrypt it — feed it the plain <sha256> from step 2. It writes a NEW
#    file, <zip>_encrypted.zip (the original stays plain), and prints the
#    ivSessionKey AND the encrypted checksum to publish
npx @capgo/cli@8.68.1 bundle encrypt <filename> <sha256>
#    -> Encoded Checksum: <...>   (the ENCRYPTED value to publish, NOT the plain sha256)
#    -> ivSessionKey: <...>       (this is the Session key)
#    -> Encrypted zip saved at <zip>_encrypted.zip
```

Then in the portal → **OTA updates**, upload the **`*_encrypted.zip`** and fill:
- **Session key** = the `ivSessionKey` from `bundle encrypt`
  (`<24-char base64 IV>:<344-char base64 key>`)
- **Checksum** = the ENCRYPTED checksum `bundle encrypt` prints (exactly 512
  hex for our RSA-2048 key) — NOT the plain `bundle zip` sha256. The device
  verifies the bundle against this signed checksum.
- platform / version / min-native as usual.

The portal, the API and `ota:publish` reject: a 64-hex (plain) checksum on a
signed bundle, a checksum that isn't exactly 512 hex (a truncated or wrapped
paste), a malformed session key, and the plain `.zip` uploaded as a signed
bundle (the file starts with the zip header instead of ciphertext).

Or do all of it in one command (signs by default; put `--` before the flags
when going through npm):

```bash
OTA_ADMIN_TOKEN=<token> npm run ota:release -- --platform both --version <version> --min-native <x.y>
```

It fails instead of ever falling back to the plain checksum or the plain zip.

The server stores the encrypted blob and records that checksum + session key; the
endpoint returns them, and the device decrypts with its embedded public key. The
bundle table shows **signed** for these.

## Unsigned (android dev channel only)

For development builds on **android** and a **non-production** channel only:
upload the plain `.zip` and leave **Session key** and **Checksum** empty (the
server computes the SHA256), or `npm run ota:release -- … --channel dev --unsigned`.
iOS and `production` refuse unsigned bundles.

## Rollback

In the portal, click **Roll back to this** on the last good bundle (per
platform). The server republishes that bundle's existing file, with its
original checksum and session key (so it stays signed, nothing to re-encrypt),
under the next unused version, and deactivates the other bundles for that
platform/channel. Devices already on the bad bundle see a newer version and
move back. See [OTA.md → Compatibility & rollback](../../OTA.md#compatibility--rollback).

**Deactivate** (or delete) alone is *not* a rollback: the endpoint only serves a
version newer than the device's, so it only stops devices that haven't
downloaded the bad bundle yet. A native store release always supersedes OTA
(`resetWhenUpdate`).
