<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Bayti\Api\Domain\Common\Timestamps;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shipping or billing address attached to a user.
 *
 * The legacy backend stores addresses in a separate `addresses` table
 * keyed by user_id, so we keep the same structure. Each user can have
 * multiple addresses (home, office, mother-in-law's place); one is
 * marked default for checkout.
 *
 * UAE-specific fields
 * -------------------
 * Fields like `emirate` and `area` are UAE-relevant. We don't generalise
 * to "state/province" because the platform is UAE-only and the Topex
 * shipping API expects emirate + area names. If we ever expand to other
 * GCC markets, we'll add a `country` column and conditional fields.
 *
 * Free-form vs validated
 * ----------------------
 * The Topex API exposes /v3/shipping/cities and /v3/shipping/areas
 * (M3 deliverables) which return validated dropdowns. Frontend uses
 * these for the picker; we still store the freely-typed values
 * because customers sometimes need to add notes ("villa 12, third
 * gate from the roundabout"). The picker fills in canonical city/area;
 * the customer adds the rest.
 */
#[ORM\Entity(repositoryClass: AddressRepository::class)]
#[ORM\Table(name: 'addresses')]
#[ORM\Index(columns: ['user_id'], name: 'idx_addresses_user')]
#[ORM\Index(columns: ['legacy_address_id'], name: 'idx_addresses_legacy_id')]
#[ORM\HasLifecycleCallbacks]
class Address
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /** Legacy MySQL `addresses.id`, populated for migrated records. */
    #[ORM\Column(name: 'legacy_address_id', type: 'integer', nullable: true, unique: true)]
    private ?int $legacyAddressId = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Friendly label like "Home", "Office", "Mum's place". User-typed,
     * not validated against a fixed list. Optional.
     */
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $label = null;

    /**
     * Recipient name for this delivery — usually the same as the user's
     * own name but can differ (gift, multi-occupant household, etc.).
     */
    #[ORM\Column(name: 'recipient_name', type: 'string', length: 200)]
    private string $recipientName;

    /**
     * Recipient phone for delivery — courier needs this; defaults to
     * the user's phone but can be overridden per address.
     */
    #[ORM\Column(name: 'recipient_phone', type: 'string', length: 25)]
    private string $recipientPhone;

    // -------------------------------------------------------------------
    // Address fields (UAE-specific)
    // -------------------------------------------------------------------

    /** UAE emirate name (e.g. 'Dubai', 'Abu Dhabi', 'Sharjah'). */
    #[ORM\Column(type: 'string', length: 50)]
    private string $emirate;

    /** Area / district within the emirate (e.g. 'Jumeirah', 'Al Barsha'). */
    #[ORM\Column(type: 'string', length: 100)]
    private string $area;

    /** Street name. */
    #[ORM\Column(name: 'street_address', type: 'string', length: 255, nullable: true)]
    private ?string $streetAddress = null;

    /**
     * Building/villa number, apartment number, floor, etc. — anything
     * the courier needs after finding the right street.
     */
    #[ORM\Column(name: 'building_details', type: 'text', nullable: true)]
    private ?string $buildingDetails = null;

    /** Optional postal code. UAE doesn't widely use postal codes but Topex accepts them when present. */
    #[ORM\Column(name: 'postal_code', type: 'string', length: 20, nullable: true)]
    private ?string $postalCode = null;

    /** ISO country code. UAE for now; structure ready for GCC expansion. */
    #[ORM\Column(type: 'string', length: 2, options: ['default' => 'AE'])]
    private string $country = 'AE';

    // -------------------------------------------------------------------
    // Defaults & flags
    // -------------------------------------------------------------------

    /**
     * Is this the user's default shipping address? At most one address
     * per user has this set to true; setting on a new address unsets
     * it on the previously-default one (handled by the repository).
     */
    #[ORM\Column(name: 'is_default_shipping', type: 'boolean', options: ['default' => false])]
    private bool $isDefaultShipping = false;

    /**
     * Is this the user's default billing address? Independent of
     * shipping. Most users will have shipping=billing for both, but
     * vendors/business customers sometimes split.
     */
    #[ORM\Column(name: 'is_default_billing', type: 'boolean', options: ['default' => false])]
    private bool $isDefaultBilling = false;

    // -------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------

    public function __construct(
        User $user,
        string $recipientName,
        string $recipientPhone,
        string $emirate,
        string $area,
        ?string $streetAddress = null,
        ?string $buildingDetails = null,
        ?string $postalCode = null,
        ?string $label = null,
    ) {
        $this->user = $user;
        $this->recipientName = trim($recipientName);
        $this->recipientPhone = trim($recipientPhone);
        $this->emirate = trim($emirate);
        $this->area = trim($area);
        $this->streetAddress = $streetAddress !== null ? trim($streetAddress) : null;
        $this->buildingDetails = $buildingDetails !== null ? trim($buildingDetails) : null;
        $this->postalCode = $postalCode !== null ? trim($postalCode) : null;
        $this->label = $label !== null ? trim($label) : null;

        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    // -------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------

    public function getId(): ?int                 { return $this->id; }
    public function getLegacyAddressId(): ?int    { return $this->legacyAddressId; }
    public function getUser(): User               { return $this->user; }
    public function getLabel(): ?string           { return $this->label; }
    public function getRecipientName(): string    { return $this->recipientName; }
    public function getRecipientPhone(): string   { return $this->recipientPhone; }
    public function getEmirate(): string          { return $this->emirate; }
    public function getArea(): string             { return $this->area; }
    public function getStreetAddress(): ?string   { return $this->streetAddress; }
    public function getBuildingDetails(): ?string { return $this->buildingDetails; }
    public function getPostalCode(): ?string      { return $this->postalCode; }
    public function getCountry(): string          { return $this->country; }
    public function isDefaultShipping(): bool     { return $this->isDefaultShipping; }
    public function isDefaultBilling(): bool      { return $this->isDefaultBilling; }

    // -------------------------------------------------------------------
    // Mutators
    // -------------------------------------------------------------------

    public function update(
        ?string $label = null,
        ?string $recipientName = null,
        ?string $recipientPhone = null,
        ?string $emirate = null,
        ?string $area = null,
        ?string $streetAddress = null,
        ?string $buildingDetails = null,
        ?string $postalCode = null,
    ): void {
        if ($label          !== null) { $this->label = trim($label); }
        if ($recipientName  !== null) { $this->recipientName = trim($recipientName); }
        if ($recipientPhone !== null) { $this->recipientPhone = trim($recipientPhone); }
        if ($emirate        !== null) { $this->emirate = trim($emirate); }
        if ($area           !== null) { $this->area = trim($area); }
        if ($streetAddress  !== null) { $this->streetAddress = trim($streetAddress); }
        if ($buildingDetails!== null) { $this->buildingDetails = trim($buildingDetails); }
        if ($postalCode     !== null) { $this->postalCode = trim($postalCode); }
    }

    public function setDefaultShipping(bool $value): void { $this->isDefaultShipping = $value; }
    public function setDefaultBilling(bool $value): void  { $this->isDefaultBilling = $value; }

    public function bindLegacyId(int $legacyAddressId): void
    {
        if ($this->legacyAddressId !== null) {
            throw new \LogicException('legacyAddressId is already set; cannot rebind');
        }
        $this->legacyAddressId = $legacyAddressId;
    }
}
