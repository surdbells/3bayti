<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Audit;

/**
 * Per-request static holder for the "who + from where" of the current action,
 * plus a dedup ledger shared between the explicit {@see AuditEmitter} calls and
 * the automatic {@see EntityAuditListener}.
 *
 * Why static (same rationale as RequestIdContext)
 * -----------------------------------------------
 * A Doctrine flush listener is a container-time singleton with no access to the
 * PSR-7 request, but it needs the authenticated actor + client IP to attribute
 * a change. Threading those through Doctrine isn't possible, so a middleware
 * stamps them here at request time and the listener reads them back. PHP-FPM is
 * one request per worker at a time, so there's no concurrency to race.
 *
 * The dedup ledger prevents double-logging: when an admin controller explicitly
 * audits a create/update/delete AND the flush listener also sees that entity
 * change, whichever runs first `claim()`s the (type:id:action) key and the
 * other skips. reset() clears everything at the start of each request.
 */
final class AuditContext
{
    private static ?int $actorUserId = null;
    private static ?string $ipAddress = null;
    private static ?string $userAgent = null;

    /** @var array<string, true> keys already recorded this request */
    private static array $recorded = [];

    public static function setActor(?int $userId): void
    {
        self::$actorUserId = ($userId !== null && $userId > 0) ? $userId : null;
    }

    public static function setRequestMeta(?string $ipAddress, ?string $userAgent): void
    {
        self::$ipAddress = ($ipAddress !== null && $ipAddress !== '') ? $ipAddress : null;
        self::$userAgent = ($userAgent !== null && $userAgent !== '') ? mb_substr($userAgent, 0, 1000) : null;
    }

    public static function getActorUserId(): ?int
    {
        return self::$actorUserId;
    }

    public static function getIpAddress(): ?string
    {
        return self::$ipAddress;
    }

    public static function getUserAgent(): ?string
    {
        return self::$userAgent;
    }

    /**
     * Build the dedup key for a (subjectType, subjectId, action) tuple, or null
     * when the id isn't usable (so null-id cases are never deduped by accident).
     */
    public static function key(string $subjectType, int $subjectId, string $action): ?string
    {
        if ($subjectType === '' || $subjectId <= 0) {
            return null;
        }
        return $subjectType . ':' . $subjectId . ':' . $action;
    }

    /**
     * Claim a key. Returns true if THIS caller claimed it (and should record),
     * false if it was already claimed (the caller should skip — someone else
     * has it). Null keys are never deduped: they always "win".
     */
    public static function claim(?string $key): bool
    {
        if ($key === null) {
            return true;
        }
        if (isset(self::$recorded[$key])) {
            return false;
        }
        self::$recorded[$key] = true;
        return true;
    }

    /** Reset all per-request state. Called at the start of every request. */
    public static function reset(): void
    {
        self::$actorUserId = null;
        self::$ipAddress = null;
        self::$userAgent = null;
        self::$recorded = [];
    }
}
