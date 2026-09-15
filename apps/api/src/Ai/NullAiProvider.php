<?php

declare(strict_types=1);

namespace Bayti\Api\Ai;

/**
 * No-op AI provider used when AI is disabled (AI_ENABLED unset / no key). Lets
 * the container resolve and the app boot; the concierge pipeline guards on
 * isEnabled() and falls back to the deterministic keyword/filter path, so
 * discovery keeps working with no external calls.
 *
 * completeJson returns an empty object (a "no structured intent" signal the
 * IntentParser reads as "use keywords only"); embed returns an empty vector per
 * input so callers that ignore isEnabled() still get a shaped, order-preserving
 * result rather than an error.
 */
final class NullAiProvider implements AiProviderInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function embedModel(): ?string
    {
        return null;
    }

    public function completeJson(string $system, string $user, array $schema, string $schemaName = 'result'): array
    {
        return [];
    }

    public function embed(array $texts): array
    {
        return array_map(static fn (): array => [], $texts);
    }
}
