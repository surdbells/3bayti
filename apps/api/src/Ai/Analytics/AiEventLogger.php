<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes the Ain analytics ledger (ai_interactions + ai_events) with raw DBAL,
 * modelled on PushNotificationLogger. Analytics must NEVER break the shopper's
 * request, so every write is wrapped and swallowed on failure.
 */
final class AiEventLogger
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Connection $connection,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Record a concierge query; returns the interaction id to thread through the
     * events (for attribution), or null if the write failed.
     *
     * @param array<string, mixed>|null $intent
     * @param list<int> $productIds
     */
    public function recordInteraction(
        string $feature,
        string $query,
        ?array $intent,
        array $productIds,
        ?int $userId,
        ?string $sessionId,
        ?string $channel,
        ?string $locale,
    ): ?int {
        try {
            $id = $this->connection->executeQuery(
                'INSERT INTO ai_interactions
                    (user_id, session_id, channel, feature, query_text, intent, result_product_ids, locale, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 RETURNING id',
                [
                    $userId,
                    $sessionId,
                    $channel,
                    $feature,
                    $query,
                    $intent !== null ? json_encode($intent) : null,
                    json_encode($productIds),
                    $locale,
                    $this->now(),
                ],
                [
                    $userId === null ? ParameterType::NULL : ParameterType::INTEGER,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                    ParameterType::STRING,
                ],
            )->fetchOne();

            return is_numeric($id) ? (int) $id : null;
        } catch (\Throwable $e) {
            $this->logger->error('ai.interaction_log_failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Append one analytics event.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function recordEvent(
        string $event,
        ?int $interactionId,
        ?int $userId,
        ?string $sessionId,
        ?int $productId = null,
        ?int $vendorId = null,
        ?array $metadata = null,
    ): void {
        try {
            $this->connection->insert('ai_events', [
                'interaction_id' => $interactionId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'event' => $event,
                'product_id' => $productId,
                'vendor_id' => $vendorId,
                'metadata' => $metadata !== null ? json_encode($metadata) : null,
                'created_at' => $this->now(),
            ], [
                'interaction_id' => $interactionId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'user_id' => $userId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'session_id' => ParameterType::STRING,
                'event' => ParameterType::STRING,
                'product_id' => $productId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'vendor_id' => $vendorId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'metadata' => ParameterType::STRING,
                'created_at' => ParameterType::STRING,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('ai.event_log_failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
    }
}
