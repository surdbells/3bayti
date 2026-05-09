<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

/**
 * User gender — small enum of values we accept for the optional
 * profile field.
 *
 * Why an enum class instead of just a string
 * -------------------------------------------
 * The DB stores VARCHAR with a CHECK constraint. The PHP layer could
 * just accept any string and rely on the DB to reject bad values, but
 * that means runtime errors come from Doctrine instead of validation.
 * An enum class gives us:
 *
 *   1. Type safety — methods that take Gender can't be called with a
 *      typo'd string. The IDE knows the four valid values.
 *   2. Centralised values — when we extend the list (e.g., add
 *      'non_binary' or anything else), we change one file.
 *   3. Easy iteration — Gender::cases() gives us the full list for
 *      validators that need to enumerate options.
 *
 * Why we accept 'prefer_not_to_say' AND nullable
 * -----------------------------------------------
 * NULL means "user has not been asked for their gender" — e.g., they
 * registered before we added this field, or skipped the profile form.
 * 'prefer_not_to_say' means "user was asked and explicitly declined."
 *
 * These are different semantically. The first lets us prompt the user
 * later ("complete your profile"); the second tells us not to.
 *
 * Why backed by string, not int
 * ------------------------------
 * We store these as text in the DB so a human looking at the table
 * can read them. Integer-backed enums are smaller on disk but
 * meaningless in raw queries. The size win is negligible
 * (4 bytes vs ~6-15 bytes per row).
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
    case PreferNotToSay = 'prefer_not_to_say';

    /**
     * The set of acceptable values for input validation.
     *
     * Symfony Validator's Choice constraint takes an array of strings,
     * so we expose values rather than cases.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $g): string => $g->value, self::cases());
    }

    /**
     * Try to construct a Gender from any user-supplied string.
     * Returns null if the string isn't a recognised value, so callers
     * can distinguish "user didn't provide a gender" from "user
     * provided an invalid string."
     */
    public static function fromStringOrNull(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}
