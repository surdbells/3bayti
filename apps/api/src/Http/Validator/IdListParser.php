<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Validator;

use Bayti\Api\Http\Errors\HttpException;

/**
 * Parses + validates lists of positive integer ids sent to admin endpoints
 * (JSON arrays in a body, or a comma-separated query value). Every failure is
 * a 422 keyed on the caller-supplied field name so the portal's mapApiErrors
 * can pin it to the right control.
 *
 * Accepted id tokens are positive whole numbers given as an int or a digit
 * string ("12"). Anything else (0, negatives, floats, "12abc", nested arrays,
 * booleans, empty tokens) is rejected rather than silently dropped.
 */
final class IdListParser
{
    /** More than 18 digits cannot be a real bigint id (and would overflow int). */
    private const MAX_DIGITS = 18;

    /**
     * A JSON array of ids from a request body. Order is preserved.
     *
     * @param bool $rejectDuplicates true: a repeated id is a 422 (ordering
     *                               payloads, where a repeat is ambiguous);
     *                               false: repeats collapse to the first occurrence.
     *
     * @return list<int>
     *
     * @throws HttpException 422 on a non-array or any invalid / duplicate id
     */
    public static function fromArray(mixed $raw, string $field, bool $rejectDuplicates = false): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw HttpException::validation([$field => ['Must be an array of ids.']]);
        }

        $ids = [];
        $seen = [];
        foreach ($raw as $value) {
            $id = self::toId($value);
            if ($id === null) {
                throw HttpException::validation([$field => ['Each id must be a positive integer.']]);
            }
            if (isset($seen[$id])) {
                if ($rejectDuplicates) {
                    throw HttpException::validation([$field => ['Duplicate id: ' . $id . '.']]);
                }
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * A comma-separated query value ("1,2,3"). Whitespace around tokens is
     * ignored; repeats collapse. Requires 1..$max DISTINCT ids.
     *
     * @return list<int>
     *
     * @throws HttpException 422 when missing/empty, any token is invalid, or
     *                       more than $max distinct ids are requested
     */
    public static function fromCsv(mixed $raw, string $field, int $max): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw HttpException::validation([$field => ['Provide at least one id (comma-separated).']]);
        }

        $ids = [];
        $seen = [];
        foreach (explode(',', $raw) as $token) {
            $id = self::toId(trim($token));
            if ($id === null) {
                throw HttpException::validation([$field => ['Each id must be a positive integer.']]);
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        if (count($ids) > $max) {
            throw HttpException::validation([$field => ['At most ' . $max . ' ids per request.']]);
        }

        return $ids;
    }

    /** A positive int id, or null when the value is not one. */
    private static function toId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && $value !== '' && strlen($value) <= self::MAX_DIGITS && ctype_digit($value)) {
            $id = (int) $value;
            return $id > 0 ? $id : null;
        }
        return null;
    }
}
