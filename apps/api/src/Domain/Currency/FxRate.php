<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Currency;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persisted FX rate row (M3.2.X.15-B).
 *
 * One row per (base_code, target_code) pair; in v1 base_code is
 * always AED (Q-RateShape = A locked). Updated by admins via the
 * X.15-F admin endpoint; the audit_log trail captures who set
 * each value when.
 *
 * Rate semantics: `rate` is the multiplier from base to target.
 * For (AED, USD, 0.27225000), 100 AED → 27.225 USD.
 *
 * Identity row (AED, AED, 1.00000000) is intentionally stored
 * even though the service short-circuits AED→AED in code. Having
 * it in the table makes admin UI listings consistent ('all 5
 * currencies appear in the management view') and confirms the
 * service's identity assumption against the data.
 */
#[ORM\Entity(repositoryClass: FxRateRepository::class)]
#[ORM\Table(name: 'fx_rates')]
#[ORM\UniqueConstraint(name: 'fx_rates_pair_uq', columns: ['base_code', 'target_code'])]
#[ORM\Index(name: 'idx_fx_rates_target', columns: ['target_code'])]
class FxRate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    // phpstan: Doctrine ORM hydrates this via reflection after
    // persist; the type union is correct from a runtime view.
    /** @phpstan-ignore-next-line property.unusedType */
    private ?int $id = null;

    #[ORM\Column(name: 'base_code', type: 'string', length: 3)]
    private string $baseCode;

    #[ORM\Column(name: 'target_code', type: 'string', length: 3)]
    private string $targetCode;

    /**
     * Decimal as string, Doctrine `decimal` type returns strings
     * which is what we want for bcmath in the conversion service.
     */
    #[ORM\Column(name: 'rate', type: 'decimal', precision: 18, scale: 8)]
    private string $rate;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    public function __construct(
        string $baseCode,
        string $targetCode,
        string $rate,
        ?User $updatedBy = null,
    ) {
        $this->baseCode = strtoupper($baseCode);
        $this->targetCode = strtoupper($targetCode);
        $this->setRate($rate);
        $this->updatedBy = $updatedBy;
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBaseCode(): string
    {
        return $this->baseCode;
    }

    public function getTargetCode(): string
    {
        return $this->targetCode;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    /**
     * Upsert API: bumps the rate + updated_at + actor. Used by
     * the X.15-F admin endpoint when an operator pushes a new rate.
     */
    public function setRate(string $rate): void
    {
        // Validate the decimal string. Bcmath silently coerces
        // bad input to 0, which would zero out a customer's
        // displayed price. Reject explicitly.
        if (!preg_match('/^\d+(\.\d+)?$/', $rate)) {
            throw new \InvalidArgumentException(
                "Rate must be a non-negative decimal string; got: {$rate}",
            );
        }
        // Defensive range check, 0 would zero all prices; > 1000
        // means someone typo'd a base/target inversion (e.g.
        // 367.0 instead of 0.272 for AED→USD).
        $cmp0 = bccomp($rate, '0', 8);
        $cmp1000 = bccomp($rate, '1000', 8);
        if ($cmp0 <= 0) {
            throw new \InvalidArgumentException("Rate must be positive; got: {$rate}");
        }
        if ($cmp1000 >= 0) {
            throw new \InvalidArgumentException("Rate must be less than 1000; got: {$rate}");
        }
        $this->rate = $rate;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $user): void
    {
        $this->updatedBy = $user;
    }
}
