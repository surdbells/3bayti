<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Common;

/**
 * Slug generation utility.
 *
 * Generates URL-safe slugs from human strings, with collision
 * handling via numeric suffix.
 *
 * Why a separate class
 * --------------------
 * Slug generation is called from many places (vendor, category,
 * brand, product, future entities). Centralising the rules means
 * one place to fix bugs ("oh, we should also strip Unicode marks").
 *
 * Why we don't use cocur/slugify or similar
 * ------------------------------------------
 * Our rules are simple ASCII kebab-case. A 30-line implementation beats a
 * 30KB dependency for this. Non-Latin names (Arabic, etc.) are transliterated
 * to Latin via intl when available; when they still can't be slugified,
 * generateUnique() falls back to a caller-supplied base instead of failing.
 */
final class SlugHelper
{
    /**
     * Convert a string to a URL-safe slug.
     *
     *   "My Cool Product!"   → "my-cool-product"
     *   "TWO   spaces"       → "two-spaces"
     *   "café"               → "cafe"  (NFKD strips accents)
     *   ""                   → ""      (caller must validate)
     */
    public static function slugify(string $input): string
    {
        $normalised = $input;

        // 0. Transliterate non-Latin scripts (Arabic, Cyrillic, …) to Latin when
        //    the intl extension is available, so a fully non-Latin name like
        //    "لمعة الدجى" yields a readable slug instead of an empty one. Falls
        //    through harmlessly (leaves $normalised unchanged) when intl is
        //    missing or produces nothing — generateUnique() then uses a fallback.
        if (class_exists(\Transliterator::class)) {
            $tr = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($tr !== null) {
                $t = $tr->transliterate($input);
                if (is_string($t) && $t !== '') {
                    $normalised = $t;
                }
            }
        }

        // 1. Normalise unicode and strip combining marks (café → cafe)
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalised);
            if ($converted !== false) {
                $normalised = $converted;
            }
        }

        // 2. Lowercase + replace non-alphanumeric with hyphen
        $slug = strtolower($normalised);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        // 3. Trim leading/trailing hyphens
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Generate a slug that doesn't collide with anything the
     * `$existsCheck` callable says is taken. Appends -2, -3, etc.
     *
     * Cap at 100 attempts so a buggy existsCheck doesn't infinite-loop.
     *
     * Never throws on an unslugifiable name. A fully non-Latin name with no
     * intl transliterator (e.g. an Arabic-only store name) slugifies to '' —
     * we then fall back to `$fallback` (e.g. "store-<id>"), then a generic
     * non-empty default, instead of throwing a 500 as before.
     *
     * @param callable(string): bool $existsCheck
     *   Returns true if the slug is already taken.
     * @param string $fallback
     *   A latin base to slugify when the primary input produces nothing.
     */
    public static function generateUnique(
        string $base,
        callable $existsCheck,
        string $fallback = '',
    ): string {
        $slug = self::slugify($base);
        if ($slug === '') {
            $slug = self::slugify($fallback);
        }
        if ($slug === '') {
            $slug = 'item';
        }

        if (!$existsCheck($slug)) {
            return $slug;
        }

        // Collision: try -2, -3, ...
        for ($i = 2; $i <= 100; $i++) {
            $candidate = "{$slug}-{$i}";
            if (!$existsCheck($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            "Could not generate unique slug for '{$base}' after 100 attempts",
        );
    }
}
