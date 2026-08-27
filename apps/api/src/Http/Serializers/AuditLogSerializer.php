<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Audit\AuditLog;
use DateTimeInterface;

/**
 * Serialize AuditLog rows for the admin audit-log surface.
 *
 * The actor (user_id) is denormalised to a name/email by the controller, which
 * batch-loads the users for the page and passes them in as $actors, keeping
 * this serializer free of DB access and the list free of N+1 queries.
 */
final class AuditLogSerializer
{
    /**
     * @param array<int, array{name: string|null, email: string|null}> $actors userId => actor info
     * @return array<string, mixed>
     */
    public function shape(AuditLog $log, array $actors = []): array
    {
        $uid = $log->getUserId();
        $actor = $uid !== null ? ($actors[$uid] ?? null) : null;

        return [
            'id' => $log->getId(),
            'actor' => $uid === null ? null : [
                'id' => $uid,
                'name' => $actor['name'] ?? null,
                'email' => $actor['email'] ?? null,
            ],
            'action' => $log->getAction(),
            'subject_type' => $log->getSubjectType(),
            'subject_id' => $log->getSubjectId(),
            'changes' => $log->getChanges(),
            'ip_address' => $log->getIpAddress(),
            'user_agent' => $log->getUserAgent(),
            'request_id' => $log->getRequestId(),
            'created_at' => $log->getCreatedAt()->format(DateTimeInterface::ATOM),
        ];
    }
}
