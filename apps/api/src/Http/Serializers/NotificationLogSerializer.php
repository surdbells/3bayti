<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Notification\NotificationLog;
use DateTimeInterface;

/**
 * Serialize NotificationLog entities for admin endpoint responses.
 *
 * Only an adminShape exists, these rows contain recipient emails
 * + error messages that are NOT public data. No publicShape by
 * design.
 *
 * Wire contract is internal (admin tool only); no external client
 * type interface to match (unlike apps/web's CategoryDetail). The
 * shape is informational; any future admin UI consumer can adapt to
 * field changes without coordinated frontend deploys.
 */
final class NotificationLogSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function adminShape(NotificationLog $log): array
    {
        return [
            'id' => $log->getId(),
            'order_id' => $log->getOrderId(),
            'template' => $log->getTemplate(),
            'recipient' => $log->getRecipient(),
            'status' => $log->getStatus(),
            'sent_at' => $log->getSentAt()->format(DateTimeInterface::ATOM),
            'error_kind' => $log->getErrorKind(),
            'error_message' => $log->getErrorMessage(),
            // raw_event surfaces in admin shape for triage when a
            // future webhook handler has decorated the row with
            // bounce/complaint payload. Null in M3.2.X.4 until that
            // handler ships.
            'raw_event' => $log->getRawEvent(),
            'created_at' => $log->getCreatedAt()->format(DateTimeInterface::ATOM),
            'updated_at' => $log->getUpdatedAt()->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param iterable<NotificationLog> $logs
     * @return list<array<string, mixed>>
     */
    public function adminShapeMany(iterable $logs): array
    {
        $out = [];
        foreach ($logs as $log) {
            $out[] = $this->adminShape($log);
        }
        return $out;
    }
}
