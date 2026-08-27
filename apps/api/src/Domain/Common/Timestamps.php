<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Common;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Shared timestamp columns for entities that need them.
 *
 * `created_at` is set in the entity's constructor; `updated_at` is
 * automatically refreshed on every UPDATE via the @PreUpdate hook.
 * Both are stored as TIMESTAMPTZ (timezone-aware), the only
 * defensible choice for a multi-region marketplace where customers
 * and vendors aren't necessarily in the same timezone.
 *
 * Entities using this trait MUST also declare HasLifecycleCallbacks
 * at class level for the @PreUpdate hook to fire.
 */
trait Timestamps
{
    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Initialise both timestamps. Call from the entity's __construct().
     */
    private function initTimestamps(): void
    {
        $now = new DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Refresh the updated_at column. Wire up with #[ORM\PreUpdate] in
     * the consuming entity:
     *
     *   #[ORM\PreUpdate]
     *   public function refreshUpdatedAt(): void { $this->touchUpdatedAt(); }
     */
    private function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
}
