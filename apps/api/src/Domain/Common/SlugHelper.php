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
 * Our rules are simple ASCII-only kebab-case. A 30-line implementation
 * beats a 30KB dependency for this. If we add Arabic / Unicode-rich
 * slugs later (M2.x i18n), reconsider.
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
        // 1. Normalise unicode and strip combining marks (café → cafe)
        $normalised = $input;
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $input);
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
     * @param callable(string): bool $existsCheck
     *   Returns true if the slug is already taken.
     */
    public static function generateUnique(
        string $base,
        callable $existsCheck,
    ): string {
        $slug = self::slugify($base);

        if ($slug === '') {
            throw new \InvalidArgumentException(
                "Cannot generate slug: input '{$base}' produces empty slug after normalisation",
            );
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
