<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * An append-only record of a mutating action.
 *
 * One row per entity creation / update / deletion / role-flag change
 * affecting a tracked entity (User profile, Address, Measurement,
 * future Order). See migration Version20260510000001 for schema
 * rationale.
 *
 * Immutability
 * ------------
 * Once created, an audit row is never modified. The constructor sets
 * everything; there are no setters. Doctrine WILL still try to
 * UPDATE on flush if change-tracking detects mutation, so we don't
 * mutate after persist.
 *
 * No FK on user_id / subject_id
 * -----------------------------
 * The audit log must outlive its subjects. If user 42 deletes their
 * account (M3+), we keep the audit rows showing what user 42 did.
 * FK with CASCADE would erase the audit; FK with RESTRICT would
 * block the delete. Neither is right — we just preserve the id as
 * data.
 *
 * Reading audit rows for forensics is the job of the AuditLogRepository
 * + future admin UI; this entity is only for the write path.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(columns: ['subject_type', 'subject_id'], name: 'audit_log_subject_idx')]
#[ORM\Index(columns: ['user_id', 'created_at'], name: 'audit_log_user_created_idx')]
#[ORM\Index(columns: ['created_at'], name: 'audit_log_created_idx')]
final class AuditLog
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';
    public const ACTION_DELETED = 'deleted';
    public const ACTION_DEFAULT = 'default';

    public const ALL_ACTIONS = [
        self::ACTION_CREATED,
        self::ACTION_UPDATED,
        self::ACTION_DELETED,
        self::ACTION_DEFAULT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * The actor — the authenticated user who initiated the change.
     * Nullable for system-driven events (cron, webhooks).
     *
     * NOT a FK by design — see class docblock.
     */
    #[ORM\Column(name: 'user_id', type: 'bigint', nullable: true)]
    private ?int $userId;

    /** Class basename of the changed entity: 'User', 'Address', etc. */
    #[ORM\Column(name: 'subject_type', type: 'string', length: 50)]
    private string $subjectType;

    /** Primary key of the changed entity. */
    #[ORM\Column(name: 'subject_id', type: 'bigint')]
    private int $subjectId;

    /** One of ACTION_* constants. */
    #[ORM\Column(name: 'action', type: 'string', length: 20)]
    private string $action;

    /**
     * Diff payload. See migration docblock for shape.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'changes', type: 'json')]
    private array $changes;

    #[ORM\Column(name: 'ip_address', type: 'string', length: 45, nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(name: 'user_agent', type: 'text', nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(name: 'request_id', type: 'string', length: 40, nullable: true)]
    private ?string $requestId;

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $changes
     */
    public function __construct(
        ?int $userId,
        string $subjectType,
        int $subjectId,
        string $action,
        array $changes,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $requestId = null,
    ) {
        if (!in_array($action, self::ALL_ACTIONS, true)) {
            throw new \InvalidArgumentException(
                "Invalid audit action '{$action}'. Must be one of: "
                . implode(', ', self::ALL_ACTIONS),
            );
        }

        if ($subjectType === '' || strlen($subjectType) > 50) {
            throw new \InvalidArgumentException(
                'subjectType must be 1-50 chars, got: ' . strlen($subjectType),
            );
        }

        $this->userId = $userId;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->action = $action;
        $this->changes = $changes;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
        $this->requestId = $requestId;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getUserId(): ?int { return $this->userId; }
    public function getSubjectType(): string { return $this->subjectType; }
    public function getSubjectId(): int { return $this->subjectId; }
    public function getAction(): string { return $this->action; }
    /** @return array<string, mixed> */
    public function getChanges(): array { return $this->changes; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function getUserAgent(): ?string { return $this->userAgent; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
