<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Measurement\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input for PUT /v3/me/measurements/{default|category/{id}}.
 *
 * Body shape
 * ----------
 *   {
 *     "values": {
 *       "arm": 60.0,
 *       "bust": 92.0,
 *       "hip": 96.0
 *     },
 *     "notes": "I prefer relaxed fit"
 *   }
 *
 * Validation rules
 * ----------------
 *   - `values` is required, must be an associative array (object
 *     in JSON). Empty object IS allowed, same as "clear all values."
 *   - Each value in `values` must be a number, in (0, 500] cm.
 *     - Reject 0 (means "didn't measure", omit the key instead).
 *     - Reject negatives (no meaningful negative measurement).
 *     - Reject >500 (clearly bogus; legitimate max <300cm for any
 *       human body part).
 *     - Reject non-numeric (strings, booleans, nulls, nested objects).
 *   - Each KEY must be a non-empty string, max 50 chars, lowercase
 *     alphanumeric + underscore. Reject 'name with spaces' or
 *     'special!chars' so the key set stays clean for vendor template
 *     matching in M2.
 *   - `notes` optional, max 1000 chars.
 *
 * Vendor template validation
 * --------------------------
 * NOT in M1.7.3. Vendors define which keys their categories require
 * (M2 deliverable). Until then, any well-formed key set is accepted.
 * When M2 lands, a follow-up validator will check that the keys
 * provided are AT LEAST the required set for the category.
 */
final class UpsertMeasurementsInput
{
    /** Maximum allowed value in cm. See class docblock. */
    private const MAX_CM = 500.0;

    /** Allowed key character set. */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,49}$/';

    /**
     * The measurement field names mapped to numeric values.
     * Schema is open, any vendor-defined key is accepted as long
     * as it follows the key naming rules.
     *
     * @var array<string, mixed>
     */
    public readonly array $values;

    #[Assert\Length(
        max: 1000,
        maxMessage: 'notes must not exceed 1000 characters.',
    )]
    public readonly ?string $notes;

    /**
     * @param array<string, mixed>|null $values
     */
    public function __construct(
        ?array $values = null,
        ?string $notes = null,
    ) {
        // Required field. RequestValidator's hydrator will pass null
        // when the field is missing from the body; the Callback
        // below catches that.
        $this->values = $values ?? [];
        $this->notes = $notes !== null ? trim($notes) : null;
    }

    /**
     * Validate the values map. Cross-validation is unavoidable here
     * because the field structure is open, Symfony's standard
     * constraints can't validate "all values in an open map are
     * positive numbers under 500."
     */
    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        // Reject if the body lacked a "values" field. We can't
        // distinguish "values: null" from "values omitted" because
        // of constructor-based hydration, but treating both as the
        // same is fine, both should fail.
        // Empty object {} is accepted (means "clear all measurements").
        // We use the count() check to catch the case where the user
        // sent {"values": null} explicitly.

        if (!is_array($this->values)) {
            // Defensive, should not happen since the constructor
            // forces array, but keep the check for clarity.
            $context->buildViolation('values must be an object.')
                ->atPath('values')
                ->addViolation();
            return;
        }

        // Iterate keys + values, validating each.
        foreach ($this->values as $key => $value) {
            $this->validateKey((string) $key, $context);
            $this->validateValue((string) $key, $value, $context);
        }
    }

    private function validateKey(string $key, ExecutionContextInterface $context): void
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            $context->buildViolation(sprintf(
                'Invalid measurement key "%s". Keys must be lowercase alphanumeric with underscores, starting with a letter, max 50 chars.',
                $key,
            ))
                ->atPath('values')
                ->addViolation();
        }
    }

    private function validateValue(
        string $key,
        mixed $value,
        ExecutionContextInterface $context,
    ): void {
        // Reject non-numeric. is_numeric accepts numeric strings
        // ("60.0") which we tolerate, the controller will cast to
        // float on save. But booleans and nulls are not numeric.
        if (!is_int($value) && !is_float($value)) {
            $context->buildViolation(sprintf(
                'Value for "%s" must be a number, got %s.',
                $key,
                gettype($value),
            ))
                ->atPath('values.' . $key)
                ->addViolation();
            return;
        }

        $float = (float) $value;

        if ($float <= 0) {
            $context->buildViolation(sprintf(
                'Value for "%s" must be greater than 0.',
                $key,
            ))
                ->atPath('values.' . $key)
                ->addViolation();
        } elseif ($float > self::MAX_CM) {
            $context->buildViolation(sprintf(
                'Value for "%s" must not exceed %.0f.',
                $key,
                self::MAX_CM,
            ))
                ->atPath('values.' . $key)
                ->addViolation();
        }
    }

    /**
     * Coerce all values to float for storage. Called by the
     * controller after validation passes.
     *
     * @return array<string, float>
     */
    public function valuesAsFloats(): array
    {
        $out = [];
        foreach ($this->values as $key => $value) {
            $out[(string) $key] = (float) $value;
        }
        return $out;
    }
}
