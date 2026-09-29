<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

/**
 * Generator for admin-issued temporary passwords.
 *
 * Used wherever an operator hands a credential to someone else (seller
 * approval welcome, resend credentials, admin vendor password reset). The
 * value is meant to be typed straight from an email, so the alphabet leaves
 * out visually ambiguous characters (0/O, 1/l/I). The holder is always forced
 * to replace it on next sign-in (User::requirePasswordChange()).
 *
 * random_int() is a CSPRNG; 12 characters over a 57-symbol alphabet is ~70
 * bits of entropy, well beyond online-guessing reach for a short-lived value.
 */
final class TemporaryPassword
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    public static function generate(int $length = 12): string
    {
        if ($length < 8) {
            throw new \InvalidArgumentException('Temporary passwords must be at least 8 characters.');
        }

        $max = strlen(self::ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }
        return $password;
    }
}
