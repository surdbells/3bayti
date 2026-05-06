<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Bayti\Api\Domain\Common\Timestamps;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's body measurements.
 *
 * Legacy schema vs new schema
 * ---------------------------
 * The legacy backend stores measurements as fixed columns (`arm`,
 * `bust`, `hip`, `length`, `shoulder`, `armhole`) — one row per user,
 * a single canonical set of measurements. Adding a new field
 * required a schema change.
 *
 * The new schema keeps the `users → measurement` 1:1 relationship
 * but stores the values as JSONB. This lets vendors define their
 * own per-category measurement schemas (the `measurements_template`
 * table, M2 deliverable) and the user fills in whichever fields the
 * vendor's schema requires for each category they shop in. A user
 * who buys both abayas (needs arm/bust/hip/length) and shoes (needs
 * foot length) gets one row per category.
 *
 * Migration from legacy
 * ---------------------
 * For users migrated from MySQL (`legacy_user_id IS NOT NULL`), we
 * synthesise one Measurement row with category=null (the catch-all
 * "default" measurement) and JSON values from the legacy columns:
 *
 *   {
 *     "arm": 60.0, "bust": 92.0, "hip": 96.0,
 *     "length": 145.0, "shoulder": 38.0, "armhole": 21.0
 *   }
 *
 * Units are centimeters by convention (legacy didn't track unit;
 * we normalise on assumption).
 */
#[ORM\Entity(repositoryClass: MeasurementRepository::class)]
#[ORM\Table(name: 'measurements')]
#[ORM\UniqueConstraint(name: 'uq_measurements_user_category', columns: ['user_id', 'category_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_measurements_user')]
#[ORM\HasLifecycleCallbacks]
class Measurement
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'measurements')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * The category this measurement set applies to. Null = default
     * (the "canonical" measurements that apply to any category
     * without specifics). Most users will only ever have a default
     * row; some will have multiple (abaya measurements differ from
     * shoe sizing).
     *
     * Stored as a foreign key id rather than slug because category
     * slugs change but ids don't. The actual Category entity lands
     * in M2 (catalog); for now this is just an int reference and
     * we'll add the FK constraint then.
     */
    #[ORM\Column(name: 'category_id', type: 'bigint', nullable: true)]
    private ?int $categoryId = null;

    /**
     * The measurement values, e.g.
     *   {"arm": 60.0, "bust": 92.0, "hip": 96.0, "length": 145.0,
     *    "shoulder": 38.0, "armhole": 21.0}
     *
     * Vendor-defined keys; values are floats (centimeters by
     * convention). The schema for each category is held by the
     * `measurement_templates` table (M2 deliverable).
     *
     * We use JSONB rather than a separate measurement_values table
     * because (a) the access pattern is always "load all measurements
     * for this user/category at once", which JSONB handles cheaply,
     * and (b) it sidesteps the N+1 query problem for what's an
     * inherently bounded dataset (no vendor will define 200
     * measurement fields).
     */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $values;

    /** Optional free-text notes the user adds (e.g. "I prefer relaxed fit"). */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct(User $user, array $values = [], ?int $categoryId = null, ?string $notes = null)
    {
        $this->user = $user;
        $this->values = $values;
        $this->categoryId = $categoryId;
        $this->notes = $notes !== null ? trim($notes) : null;
        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    public function getId(): ?int           { return $this->id; }
    public function getUser(): User         { return $this->user; }
    public function getCategoryId(): ?int   { return $this->categoryId; }
    public function getValues(): array      { return $this->values; }
    public function getNotes(): ?string     { return $this->notes; }

    public function update(?array $values = null, ?string $notes = null): void
    {
        if ($values !== null) { $this->values = $values; }
        if ($notes  !== null) { $this->notes = trim($notes); }
    }
}
