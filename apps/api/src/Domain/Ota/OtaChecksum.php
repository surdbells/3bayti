<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

/**
 * Shape rules for the checksum a device verifies an OTA download against.
 *
 *  - PLAIN bundle: the SHA256 of the .zip, 64 hex chars.
 *  - SIGNED bundle: the checksum `@capgo/cli bundle encrypt` emits, the plain
 *    SHA256 RSA-encrypted with the private key. For updater >= 7.30 (we ship
 *    8.51.3) it's hex, and the plugin accepts ONLY an RSA-2048 ciphertext:
 *    exactly 256 bytes = 512 hex chars (CryptoCipher.decryptChecksum on both
 *    platforms throws on any other length; our key is RSA-2048). So a
 *    truncated paste, or a checksum from a different key size, is refused
 *    here instead of failing on every device. It is NEVER the plain 64-hex
 *    SHA256: a signed bundle published with the plain checksum fails
 *    on-device verification for every user.
 *
 * Shared by the upload controller and `ota:publish` so both registration
 * paths enforce the same rule (mirrored by the portal's ota-rules.ts and the
 * release script's ota-release-lib.mjs).
 */
final class OtaChecksum
{
    private const PLAIN = '/^[a-f0-9]{64}\z/i';
    private const SIGNED = '/^[a-f0-9]{512}\z/i';

    /** Null when valid, else a human-readable reason. */
    public static function validate(string $checksum, bool $signed): ?string
    {
        if ($signed) {
            if (preg_match(self::PLAIN, $checksum)) {
                return 'checksum looks like the PLAIN sha256 (64 hex). A signed bundle needs the '
                    . 'ENCRYPTED checksum printed by `@capgo/cli bundle encrypt` (512 hex for RSA-2048).';
            }
            if (!preg_match(self::SIGNED, $checksum)) {
                return sprintf(
                    'checksum must be the hex encrypted checksum from `@capgo/cli bundle encrypt`, exactly '
                    . '512 hex chars (RSA-2048); got %d chars. Check for a truncated or wrapped paste.',
                    strlen($checksum),
                );
            }

            return null;
        }

        return preg_match(self::PLAIN, $checksum)
            ? null
            : 'checksum must be the 64-char hex SHA256 of the .zip (unsigned bundle).';
    }
}
