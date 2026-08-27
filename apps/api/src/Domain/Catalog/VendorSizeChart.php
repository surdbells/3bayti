<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\Mapping as ORM;

/**
 * A vendor's published size-chart row, the garment dimensions for one
 * size in this store's size guide (e.g. store size "M" → bust 92, waist
 * 76, ...).
 *
 * Distinct from a customer's body measurements (Domain\User\Measurement):
 * this is what the STORE publishes, keyed by (vendor, size). Dimensions
 * are stored as a JSONB map so the set of named fields can vary without a
 * schema change; the legacy fields are bust/waist/hip/length/neck/arm/
 * armhole/shoulder.
 */
#[ORM\Entity(repositoryClass: VendorSizeChartRepository::class)]
#[ORM\Table(name: 'vendor_size_charts')]
#[ORM\UniqueConstraint(name: 'uq_vendor_size_charts_vendor_size', columns: ['vendor_id', 'size'])]
class VendorSizeChart
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Vendor $vendor;

    #[ORM\Column(name: 'size', type: 'string', length: 40)]
    private string $size;

    /** @var array<string, mixed> named numeric dimensions */
    #[ORM\Column(name: 'values', type: 'json')]
    private array $values = [];

    #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $values
     */
    public function __construct(Vendor $vendor, string $size, array $values)
    {
        $this->vendor = $vendor;
        $this->size = $size;
        $this->values = $values;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int { return $this->id; }
    public function getVendor(): Vendor { return $this->vendor; }
    public function getSize(): string { return $this->size; }

    /** @return array<string, mixed> */
    public function getValues(): array { return $this->values; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function setSize(string $size): void
    {
        $this->size = $size;
        $this->touch();
    }

    /** @param array<string, mixed> $values */
    public function setValues(array $values): void
    {
        $this->values = $values;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
