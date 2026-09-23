<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Hotlink;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Writes the hotlink_clicks ledger with raw DBAL, modelled on AiEventLogger.
 * Click tracking must NEVER break the resolve/redirect, so every write is
 * wrapped and swallowed on failure. The ledger (not the denormalized
 * hotlinks.click_count) is the authoritative source for conversion
 * attribution — the admin analytics join maps a click's user to an order
 * placed within the attribution window.
 */
final class HotlinkClickLogger
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Connection $connection,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Record one hotlink click. user_id is set for a logged-in resolve (drives
     * conversion attribution); session_id is an opaque client tag for
     * logged-out reach. Both nullable.
     */
    public function recordClick(int $hotlinkId, ?int $userId, ?string $sessionId): void
    {
        try {
            $this->connection->insert('hotlink_clicks', [
                'hotlink_id' => $hotlinkId,
                'user_id' => $userId,
                'session_id' => $sessionId !== null && $sessionId !== '' ? mb_substr($sessionId, 0, 64) : null,
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:sP'),
            ], [
                'hotlink_id' => ParameterType::INTEGER,
                'user_id' => $userId === null ? ParameterType::NULL : ParameterType::INTEGER,
                'session_id' => ParameterType::STRING,
                'created_at' => ParameterType::STRING,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('hotlink.click.log_failed', [
                'hotlink_id' => $hotlinkId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
