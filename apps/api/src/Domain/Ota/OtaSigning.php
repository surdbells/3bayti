<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

/**
 * Signing rules every OTA registration path enforces (upload controller and
 * `ota:publish`; mirrored by the portal's ota-rules.ts and the release
 * script's ota-release-lib.mjs).
 *
 * Every OTA-capable store build embeds a public key (capacitor.config.ts
 * `CapacitorUpdater.publicKey`, since 2e13bc2d). With a public key set, the
 * updater plugin (8.51.3) behaves differently per platform:
 *  - iOS ALWAYS RSA-decrypts the checksum (CapacitorUpdaterPlugin.swift, both
 *    the manual and the background download path) and throws unless it is a
 *    256-byte RSA ciphertext. An unsigned bundle (plain 64-hex SHA256) can
 *    therefore never install on iOS: every iOS device downloads it and fails.
 *  - Android only decrypts when a session key is present, so an unsigned
 *    bundle installs with no signature check at all and the signing key
 *    protects nothing.
 * Hence: unsigned bundles are refused for iOS (they cannot work) and for the
 * production channel (OTA-SIGNING.md: never ship unsigned JS to customers).
 * The only unsigned case left is Android on a non-production channel, for
 * development builds.
 */
final class OtaSigning
{
    public const PRODUCTION_CHANNEL = 'production';

    /**
     * `@capgo/cli bundle encrypt` ivSessionKey: base64(16-byte AES IV) + ":" +
     * base64(AES key RSA-encrypted with the RSA-2048 private key, 256 bytes).
     * The plugin needs exactly that (it AES-decrypts with the IV and
     * RSA-decrypts the 256-byte key), so anything else fails on every device.
     */
    private const SESSION_KEY = '/^[A-Za-z0-9+\/]{22}==:[A-Za-z0-9+\/]{342}==\z/';

    /**
     * Null when an UNSIGNED bundle may be published to these platforms on this
     * channel, else the reason it may not.
     *
     * @param list<string> $platforms
     */
    public static function unsignedError(array $platforms, string $channel): ?string
    {
        if (in_array(OtaBundle::PLATFORM_IOS, $platforms, true)) {
            return 'iOS only installs SIGNED bundles: the app embeds a public key, so the updater rejects any '
                . 'bundle without an RSA-encrypted checksum. Upload the *_encrypted.zip written by '
                . '`@capgo/cli bundle encrypt` with its session_key and encrypted checksum (see OTA-SIGNING.md).';
        }
        if ($channel === self::PRODUCTION_CHANNEL) {
            return 'Production bundles must be SIGNED: upload the *_encrypted.zip written by `@capgo/cli bundle '
                . 'encrypt` with its session_key and encrypted checksum (see OTA-SIGNING.md). Unsigned bundles '
                . 'are only accepted for android on a non-production channel.';
        }

        return null;
    }

    /** Null when the session key has the ivSessionKey shape, else why not. */
    public static function sessionKeyError(string $sessionKey): ?string
    {
        return preg_match(self::SESSION_KEY, $sessionKey)
            ? null
            : 'session_key must be the ivSessionKey printed by `@capgo/cli bundle encrypt` '
                . '(<24-char base64 IV>:<344-char base64 key>, RSA-2048). Check for a truncated or wrapped paste.';
    }

    /**
     * Whether bytes start like a plain .zip (local file header, or the end of
     * central directory of an empty archive). AES-CBC ciphertext starts with
     * these 4 bytes about once in 2^32, so a "signed" upload that does is
     * almost certainly the plain zip uploaded by mistake (`bundle encrypt`
     * writes the ciphertext to a separate <zip>_encrypted.zip).
     */
    public static function looksLikePlainZip(string $bytes): bool
    {
        return str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06");
    }
}
