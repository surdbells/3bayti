<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Bayti\Api\Domain\Common\Timestamps;
use Doctrine\ORM\Mapping as ORM;

/**
 * Billing or shipping address attached to an Order.
 *
 * Type enum + UNIQUE constraint
 * ==============================
 * Each order has at most one billing and one shipping address;
 * uq_order_addresses_order_type enforces this at the DB level.
 *
 * Even when billing and shipping match, both rows are stored. M3.1.7
 * vendor flows print shipping labels from the shipping row; finance
 * reconciliation uses the billing row. Keeping them separate avoids
 * "is_same_as_billing" boolean special cases.
 *
 * Country code default = 'AE'
 * ============================
 * 3bayti launches in the UAE and the legacy customer base is
 * predominantly local. Multi-country support is M4+.
 *
 * No FK back to a "saved address" table
 * ======================================
 * OrderAddress is a SNAPSHOT — the customer's saved billing address
 * (M3.1.6g controllers manage that) is COPIED here at checkout. If
 * the customer later edits their saved address, this row stays as
 * it was at order time. Same snapshotting principle as order_items.
 */
#[ORM\Entity]
#[ORM\Table(name: 'order_addresses')]
#[ORM\HasLifecycleCallbacks]
class OrderAddress
{
    use Timestamps;

    public const TYPE_BILLING = 'billing';
    public const TYPE_SHIPPING = 'shipping';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Order $order;

    #[ORM\Column(type: 'string', length: 16)]
    private string $type;

    #[ORM\Column(name: 'first_name', type: 'string', length: 100)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', type: 'string', length: 100, nullable: true)]
    private ?string $lastName = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $phone;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string', length: 255)]
    private string $street;

    #[ORM\Column(type: 'string', length: 100)]
    private string $city;

    #[ORM\Column(name: 'state_province', type: 'string', length: 100, nullable: true)]
    private ?string $stateProvince = null;

    #[ORM\Column(name: 'country_code', type: 'string', length: 2)]
    private string $countryCode = 'AE';

    #[ORM\Column(name: 'postal_code', type: 'string', length: 20, nullable: true)]
    private ?string $postalCode = null;

    public function __construct(
        string $type,
        string $firstName,
        string $phone,
        string $email,
        string $street,
        string $city,
        ?string $lastName = null,
        ?string $stateProvince = null,
        string $countryCode = 'AE',
        ?string $postalCode = null,
    ) {
        if (!in_array($type, [self::TYPE_BILLING, self::TYPE_SHIPPING], true)) {
            throw new \InvalidArgumentException("OrderAddress type must be 'billing' or 'shipping', got '{$type}'");
        }
        if ($firstName === '') {
            throw new \InvalidArgumentException('OrderAddress first_name cannot be empty.');
        }
        if ($phone === '') {
            throw new \InvalidArgumentException('OrderAddress phone cannot be empty.');
        }
        if ($email === '') {
            throw new \InvalidArgumentException('OrderAddress email cannot be empty.');
        }
        if ($street === '' || $city === '') {
            throw new \InvalidArgumentException('OrderAddress street and city are required.');
        }

        $this->type = $type;
        $this->firstName = $firstName;
        $this->phone = $phone;
        $this->email = $email;
        $this->street = $street;
        $this->city = $city;
        $this->lastName = $lastName;
        $this->stateProvince = $stateProvince;
        $this->countryCode = strtoupper($countryCode);
        $this->postalCode = $postalCode;
        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function setOrder(Order $order): void { $this->order = $order; }
    public function getType(): string { return $this->type; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): ?string { return $this->lastName; }
    public function getPhone(): string { return $this->phone; }
    public function getEmail(): string { return $this->email; }
    public function getStreet(): string { return $this->street; }
    public function getCity(): string { return $this->city; }
    public function getStateProvince(): ?string { return $this->stateProvince; }
    public function getCountryCode(): string { return $this->countryCode; }
    public function getPostalCode(): ?string { return $this->postalCode; }
}
