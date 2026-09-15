<?php

declare(strict_types=1);

namespace Bayti\Api\Ai;

/**
 * The AI capability the concierge depends on, abstracted away from any single
 * vendor. The application (Ain concierge, product enrichment) depends on this
 * interface, never on OpenAI directly, so the provider can be swapped with one
 * new class + one DI branch.
 *
 * A {@see NullAiProvider} stands in when AI is disabled (AI_ENABLED unset), so
 * the container always boots and every caller can guard on isEnabled() and
 * degrade gracefully — mirroring the SmsSenderInterface / ShippingProviderInterface
 * null-object pattern.
 */
interface AiProviderInterface
{
    /** True only for a real, configured provider. */
    public function isEnabled(): bool;

    /**
     * Ask the model to return a single JSON object matching $schema (a JSON
     * Schema for the expected object). Used for intent parsing and re-ranking,
     * where we need machine-readable structure, never free prose.
     *
     * @param array<string, mixed> $schema JSON Schema describing the object
     * @param string $schemaName short a-z/underscore name the provider may require
     * @return array<string, mixed> the decoded object
     * @throws AiException on any provider/network/auth/malformed failure
     */
    public function completeJson(string $system, string $user, array $schema, string $schemaName = 'result'): array;

    /**
     * Embed each input text into a dense vector, in input order. Empty input
     * returns an empty list. Powers semantic retrieval + product enrichment.
     *
     * @param list<string> $texts
     * @return list<list<float>> one vector per input, in the same order
     * @throws AiException
     */
    public function embed(array $texts): array;
}
