# OTA bundle signing (end-to-end encryption)

Self-hosted OTA updates are delivered by `@capgo/capacitor-updater` from our own
server. By default a bundle is only **SHA256-verified** — that protects against
corruption, not against a malicious bundle. Because OTA ships JavaScript to a
**checkout** app, enable **code signing** before opening OTA to production:
anyone able to spoof the update endpoint or write to `var/uploads/ota` could
otherwise push arbitrary code to every user.

Signing uses a keypair: the **private** key signs bundles on your release
machine; the **public** key is embedded in the app and verifies every bundle
on-device. A bundle that doesn't verify is rejected.

## One-time setup

Run in `apps/mobile`:

```bash
npx @capgo/cli key create
```

This:
- generates `capgo_key` (**private — keep secret**) and `capgo_key.pub` (public),
- writes the **public** key into `capacitor.config.ts` (`CapacitorUpdater.publicKey`).

Then:
- **Commit** `capacitor.config.ts` (the public key is meant to be embedded) and,
  if you like, `capgo_key.pub`.
- **Never commit `capgo_key`** — it's already in `.gitignore`. Store it in your
  password manager / CI secrets. If it leaks, run `key create` again and ship a
  new native build.
- Ship a **new store build** so devices carry the public key. Only builds with
  the public key can receive **signed** bundles.

## Per-release (signed)

```bash
# 1. build the web bundle
npm run build                                   # -> apps/mobile/www

# 2. zip it + get the plain SHA256 (checksum of the *unencrypted* zip)
npx @capgo/cli bundle zip com.threebayti.app --path www --json
#    -> { "bundle": "<zip>", "checksum": "<sha256>" }

# 3. encrypt the zip in place — feed it the plain <sha256> from step 2; it
#    prints the ivSessionKey AND the encrypted checksum to publish
npx @capgo/cli bundle encrypt <zip> <sha256>
#    -> ivSessionKey: <...>   (this is the Session key)
#    -> checksum:     <...>   (the ENCRYPTED value to publish, NOT the plain sha256)
```

Then in the portal → **OTA updates**, upload the **encrypted** file and fill:
- **Session key** = the `ivSessionKey` from `bundle encrypt`
- **Checksum** = the ENCRYPTED checksum `bundle encrypt` prints (512 hex for
  RSA-2048) — NOT the plain `bundle zip` sha256. The device verifies the bundle
  against this signed checksum.
- platform / version / min-native as usual.

The server stores the encrypted blob and records that checksum + session key; the
endpoint returns them, and the device decrypts with its embedded public key. The
bundle table shows **signed** for these.

> Command names have shifted across CLI releases: v8 (8.33.0 here) nests it as
> `bundle encrypt`; some older versions used a bare `encrypt`. Check
> `npx @capgo/cli bundle encrypt --help` for yours. What matters regardless is
> what goes in the portal fields: the ivSessionKey as **Session key** and the
> **encrypted** checksum it prints as **Checksum** (never the plain `bundle zip`
> sha256).

## Per-release (unsigned — dev/staging only)

Upload the plain `.zip`; leave **Session key** and **Checksum** empty. The server
computes the SHA256 itself. Do **not** use unsigned bundles for production.

## Rollback

In the portal, **Deactivate** the bad bundle (or delete it). The endpoint then
serves the previous **active** bundle for that platform/channel. A native store
release always supersedes OTA (`resetWhenUpdate`).
