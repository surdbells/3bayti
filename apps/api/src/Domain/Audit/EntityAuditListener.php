<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Audit;

use Bayti\Api\Http\Middleware\RequestIdContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Automatic audit trail for entity mutations.
 *
 * Registered on Doctrine's onFlush + postFlush (see config/di.php), this records
 * a create/update/delete AuditLog row for every audited entity change, with a
 * field-level diff, regardless of which controller triggered it. That's how
 * customer and vendor self-service actions (which have no explicit AuditEmitter
 * calls) get logged the same way admin actions do.
 *
 * Safety
 * ------
 * Every hook is fully exception-isolated: an audit failure logs to PSR-3 and is
 * swallowed, so it can NEVER break the user's actual write. The `$writing` guard
 * stops the audit-row flush from recursively re-triggering the listener, and
 * AuditLog itself is on the denylist.
 *
 * Dedup
 * -----
 * The actor/IP come from {@see AuditContext} (stamped by middleware). The same
 * context carries a per-request ledger: if an admin controller already audited a
 * change explicitly (rich snapshot), it `claim()`ed the key and this listener
 * skips it, so admin actions aren't logged twice.
 */
final class EntityAuditListener
{
    /**
     * Entities NOT audited: the audit tables themselves (recursion), auth
     * secrets, and high-churn/transient rows that would only add noise.
     */
    private const DENYLIST = [
        'AuditLog', 'NotificationLog', 'RefreshToken', 'SocialIdentity',
        'DeviceToken', 'Cart', 'CartItem',
    ];

    /** Fields dropped from every diff, they change on every write by definition. */
    private const SKIP_FIELDS = ['createdAt', 'updatedAt'];

    /** @var list<array{entity: object, id: ?int, type: string, action: string, changes: array<string, mixed>}> */
    private array $pending = [];

    private bool $writing = false;

    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /** Stage audit records for the current flush (before ids/state are lost). */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $this->pending = [];
        if ($this->writing) {
            return;
        }

        try {
            $em = $args->getObjectManager();
            if (!$em instanceof EntityManagerInterface) {
                return;
            }
            $uow = $em->getUnitOfWork();

            foreach ($uow->getScheduledEntityInsertions() as $entity) {
                if (!$this->audited($entity)) {
                    continue;
                }
                $changes = $this->buildInsertChanges($uow->getEntityChangeSet($entity));
                $this->pending[] = [
                    'entity' => $entity, 'id' => null,
                    'type' => $this->basename($entity), 'action' => 'created', 'changes' => $changes,
                ];
            }

            foreach ($uow->getScheduledEntityUpdates() as $entity) {
                if (!$this->audited($entity)) {
                    continue;
                }
                $changes = $this->buildUpdateChanges($uow->getEntityChangeSet($entity));
                if ($changes === []) {
                    continue;
                }
                $this->pending[] = [
                    'entity' => $entity, 'id' => $this->safeId($entity),
                    'type' => $this->basename($entity), 'action' => 'updated', 'changes' => $changes,
                ];
            }

            foreach ($uow->getScheduledEntityDeletions() as $entity) {
                if (!$this->audited($entity)) {
                    continue;
                }
                $this->pending[] = [
                    'entity' => $entity, 'id' => $this->safeId($entity),
                    'type' => $this->basename($entity), 'action' => 'deleted',
                    'changes' => $this->buildDeleteChanges($uow->getOriginalEntityData($entity)),
                ];
            }
        } catch (\Throwable $e) {
            $this->pending = [];
            $this->logger->error('audit.listener.onflush_failed', ['error' => $e->getMessage(), 'class' => $e::class]);
        }
    }

    /** Persist the staged audit rows (ids are now assigned for inserts). */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->writing || $this->pending === []) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];

        $em = $args->getObjectManager();
        if (!$em instanceof EntityManagerInterface) {
            return;
        }

        $this->writing = true;
        try {
            $wrote = 0;
            foreach ($pending as $p) {
                $id = $p['id'] ?? $this->safeId($p['entity']);
                if (!is_int($id) || $id <= 0) {
                    continue;
                }
                // Dedup: skip if an explicit AuditEmitter call already claimed it.
                if (!AuditContext::claim(AuditContext::key($p['type'], $id, $p['action']))) {
                    continue;
                }
                $em->persist(new AuditLog(
                    userId: AuditContext::getActorUserId(),
                    subjectType: $p['type'],
                    subjectId: $id,
                    action: $p['action'],
                    changes: $p['changes'],
                    ipAddress: AuditContext::getIpAddress(),
                    userAgent: AuditContext::getUserAgent(),
                    requestId: RequestIdContext::get(),
                ));
                $wrote++;
            }
            if ($wrote > 0) {
                $em->flush();
            }
        } catch (\Throwable $e) {
            $this->logger->error('audit.listener.postflush_failed', ['error' => $e->getMessage(), 'class' => $e::class]);
        } finally {
            $this->writing = false;
        }
    }

    // ── Change-set → changes payload ───────────────────────────────────

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @return array<string, mixed>
     */
    private function buildUpdateChanges(array $changeSet): array
    {
        $before = [];
        $after = [];
        foreach ($changeSet as $field => $pair) {
            if ($this->skipField($field)) {
                continue;
            }
            if ($this->redactField($field)) {
                $before[$field] = '[redacted]';
                $after[$field] = '[redacted]';
                continue;
            }
            $before[$field] = $this->normalize($pair[0] ?? null);
            $after[$field] = $this->normalize($pair[1] ?? null);
        }
        return ($before === [] && $after === []) ? [] : ['before' => $before, 'after' => $after];
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @return array<string, mixed>
     */
    private function buildInsertChanges(array $changeSet): array
    {
        $after = [];
        foreach ($changeSet as $field => $pair) {
            if ($this->skipField($field)) {
                continue;
            }
            $after[$field] = $this->redactField($field) ? '[redacted]' : $this->normalize($pair[1] ?? null);
        }
        return $after === [] ? [] : ['after' => $after];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function buildDeleteChanges(array $data): array
    {
        $before = [];
        foreach ($data as $field => $value) {
            if ($this->skipField($field)) {
                continue;
            }
            $before[$field] = $this->redactField($field) ? '[redacted]' : $this->normalize($value);
        }
        return $before === [] ? [] : ['before' => $before];
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function audited(object $entity): bool
    {
        return !in_array($this->basename($entity), self::DENYLIST, true);
    }

    private function basename(object $entity): string
    {
        $class = $entity::class;
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    private function safeId(object $entity): ?int
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }
        try {
            $id = $entity->getId();
            return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
        } catch (\Throwable) {
            return null;
        }
    }

    private function skipField(string $field): bool
    {
        return in_array($field, self::SKIP_FIELDS, true);
    }

    private function redactField(string $field): bool
    {
        $f = strtolower($field);
        foreach (['password', 'token', 'secret', 'otp'] as $needle) {
            if (str_contains($f, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reduce an arbitrary field value to something JSON-safe + readable.
     */
    private function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if (is_array($value)) {
            if ($depth >= 3) {
                return '[…]';
            }
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->normalize($v, $depth + 1);
            }
            return $out;
        }
        if (is_object($value)) {
            $name = $this->basename($value);
            $id = $this->safeId($value);
            return $id !== null ? $name . '#' . $id : $name;
        }
        return null;
    }
}
