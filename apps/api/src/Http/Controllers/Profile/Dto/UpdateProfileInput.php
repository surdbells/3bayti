<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile\Dto;

use Bayti\Api\Domain\User\Gender;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for PATCH /v3/me/profile.
 *
 * RFC 7396 JSON Merge Patch semantics
 * -----------------------------------
 *   - All fields optional. Missing fields stay unchanged.
 *   - Empty body is valid → 200 no-op (returns current profile).
 *
 * What's NOT here
 * ---------------
 *   - email, change requires re-verification flow (separate endpoint, M1.7.4+)
 *   - phone, same as email
 *   - password, change requires old-password verification (M1.7.4+)
 *   - country_code, set at registration, not editable (per business decision)
 *   - role flags, admin-only operation
 *
 * Tristate semantics (limitation)
 * --------------------------------
 * RequestValidator hydrates this DTO via constructor parameters. Each
 * parameter defaults to null. So:
 *
 *   - Field absent in JSON body → constructor default → null
 *   - Field present with null   → null is passed → null
 *
 * These are indistinguishable. We therefore treat them the same:
 * "no value provided." That means PATCH cannot CLEAR an existing
 * value via this endpoint, once you set a gender, you can't unset
 * it via PATCH (only change it to a different value).
 *
 * If we ever need true tristate (set/unset/leave-alone) we'd need to
 * either:
 *   a) Use a Maybe<T> wrapper type, or
 *   b) Pass the raw decoded body alongside the DTO so the controller
 *      can check array_key_exists.
 *
 * For M1.7.1 the limitation is acceptable: gender/dob being unsettable
 * after first set is a small UX gap that can be addressed later if
 * users need it (DELETE /v3/me/profile/gender, etc).
 *
 * Validation philosophy
 * ---------------------
 * Each field validates ONLY when present. Symfony's Choice/Length/Date
 * constraints all skip null values, so unset fields don't trigger
 * violations.
 */
final class UpdateProfileInput
{
    /**
     * Allowed locales for our markets. Strict list; expanding requires
     * also expanding our SMS / email templates so we can actually
     * render in the new locale.
     */
    public const ALLOWED_LOCALES = ['en', 'ar', 'en-AE', 'ar-AE'];

    /**
     * Curated style-aesthetic tags the onboarding picker offers (P4). The
     * client renders localized labels; these canonical slugs are what's
     * stored + validated. Max 10 selected.
     */
    public const ALLOWED_STYLE_TAGS = [
        'classic', 'minimalist', 'elegant', 'casual', 'streetwear', 'bohemian',
        'modest', 'glam', 'chic', 'traditional', 'sporty', 'edgy', 'vintage', 'romantic',
    ];

    private const MAX_STYLE_TAGS = 10;

    /**
     * Reasonable upper bound on age. Anyone older than 130 is either
     * lying or breaking world records, both warrant rejecting the
     * value rather than letting bogus data into the DB.
     */
    private const MAX_AGE_YEARS = 130;

    #[Assert\Length(max: 100, maxMessage: 'first_name must not exceed 100 characters.')]
    public readonly ?string $first_name;

    #[Assert\Length(max: 100, maxMessage: 'last_name must not exceed 100 characters.')]
    public readonly ?string $last_name;

    /**
     * Gender, accepts null OR one of the canonical enum values.
     */
    #[Assert\Choice(
        callback: [Gender::class, 'values'],
        message: 'gender must be one of: male, female, other, prefer_not_to_say.',
    )]
    public readonly ?string $gender;

    /**
     * DOB as ISO 8601 date string ('YYYY-MM-DD').
     *
     * Format is enforced by Assert\Date. Sanity bounds (no future, no
     * impossibly-old) are in the Callback.
     */
    #[Assert\Date(message: 'dob must be a valid date in YYYY-MM-DD format.')]
    public readonly ?string $dob;

    #[Assert\Choice(
        choices: self::ALLOWED_LOCALES,
        message: 'locale must be one of: en, ar, en-AE, ar-AE.',
    )]
    public readonly ?string $locale;

    /**
     * Timezone, IANA identifier. Validated against PHP's timezone
     * database via the Callback (the list is too large for inline
     * Choice).
     */
    public readonly ?string $timezone;

    /**
     * Declared style aesthetics (onboarding). A list of ALLOWED_STYLE_TAGS
     * slugs; validated in the Callback. Null = not provided (unchanged).
     *
     * @var list<string>|null
     */
    public readonly ?array $style_preferences;

    /**
     * Data-collection consent. true records consent (with the current copy
     * version) if not already granted. Absent/false = no change.
     */
    public readonly ?bool $data_consent;

    /**
     * @param mixed[]|null $style_preferences untrusted request input; normalized to list<string>
     */
    public function __construct(
        ?string $first_name = null,
        ?string $last_name = null,
        ?string $gender = null,
        ?string $dob = null,
        ?string $locale = null,
        ?string $timezone = null,
        ?array $style_preferences = null,
        ?bool $data_consent = null,
    ) {
        // Trim text-ish fields. The User entity setters also trim
        // defensively, but doing it here means validation runs against
        // the trimmed value (e.g. '   ' becomes empty string and
        // hasAnyField returns false correctly).
        //
        // After trim we collapse empty strings to null for consistent
        // "no value" semantics throughout the controller.
        $this->first_name = self::nullifyEmpty($first_name);
        $this->last_name = self::nullifyEmpty($last_name);
        $this->gender = self::nullifyEmpty($gender);
        $this->dob = self::nullifyEmpty($dob);
        $this->locale = self::nullifyEmpty($locale);
        $this->timezone = self::nullifyEmpty($timezone);
        // Normalize style tags: keep strings only, trim, drop blanks, dedupe.
        // An empty/blank list collapses to null ("not provided") rather than
        // silently clearing existing prefs on an accidental empty array.
        if ($style_preferences === null) {
            $this->style_preferences = null;
        } else {
            $clean = array_values(array_unique(array_filter(
                array_map(static fn ($t): string => is_string($t) ? trim($t) : '', $style_preferences),
                static fn (string $t): bool => $t !== '',
            )));
            $this->style_preferences = $clean === [] ? null : $clean;
        }
        $this->data_consent = $data_consent;
    }

    /**
     * Trim and treat empty string as null. ' ' -> null, ' x ' -> 'x'.
     */
    private static function nullifyEmpty(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Cross-field validation:
     *   - dob can't be in the future
     *   - dob can't be impossibly old (>130 years)
     *   - timezone must be in PHP's IANA list
     */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ($this->dob !== null) {
            $dob = DateTimeImmutable::createFromFormat('Y-m-d', $this->dob);
            if ($dob !== false) {
                $now = new DateTimeImmutable();
                if ($dob > $now) {
                    $context->buildViolation('dob cannot be in the future.')
                        ->atPath('dob')
                        ->addViolation();
                }
                $maxAge = $now->modify('-' . self::MAX_AGE_YEARS . ' years');
                if ($dob < $maxAge) {
                    $context->buildViolation(
                        sprintf('dob cannot be more than %d years ago.', self::MAX_AGE_YEARS),
                    )
                        ->atPath('dob')
                        ->addViolation();
                }
            }
        }

        if ($this->timezone !== null) {
            // DateTimeZone::listIdentifiers() returns the canonical
            // IANA timezone list. Comparison is case-sensitive
            // ('Asia/Dubai' valid, 'asia/dubai' not).
            if (!in_array($this->timezone, DateTimeZone::listIdentifiers(), true)) {
                $context->buildViolation('timezone must be a valid IANA timezone identifier (e.g. Asia/Dubai).')
                    ->atPath('timezone')
                    ->addViolation();
            }
        }

        if ($this->style_preferences !== null) {
            if (count($this->style_preferences) > self::MAX_STYLE_TAGS) {
                $context->buildViolation(sprintf('style_preferences must not exceed %d tags.', self::MAX_STYLE_TAGS))
                    ->atPath('style_preferences')
                    ->addViolation();
            }
            foreach ($this->style_preferences as $tag) {
                if (!in_array($tag, self::ALLOWED_STYLE_TAGS, true)) {
                    $context->buildViolation(sprintf('style_preferences contains an unknown tag: %s.', $tag))
                        ->atPath('style_preferences')
                        ->addViolation();
                }
            }
        }
    }

    /**
     * True if at least one field was provided.
     *
     * Used by UpdateProfileController to short-circuit empty-body
     * PATCH into a 200 no-op (return current profile, no DB write).
     */
    public function hasAnyField(): bool
    {
        return $this->first_name !== null
            || $this->last_name !== null
            || $this->gender !== null
            || $this->dob !== null
            || $this->locale !== null
            || $this->timezone !== null
            || $this->style_preferences !== null
            || $this->data_consent === true;
    }
}
