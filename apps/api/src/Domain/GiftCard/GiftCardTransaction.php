<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftCard;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only ledger of every balance movement on a gift card.
 *
 * TYPE_DEBIT  — balance reduced (checkout spend)
 * TYPE_CREDIT — balance restored (order cancellation refund back to card)
 *
 * Immutable once created — never updated by the application.
 */
#[ORM\Entity]
#[ORM\Table(name: 'gift_card_transactions')]
final class GiftCardTransaction
{
    public const TYPE_DEBIT  = 'debit';
    public const TYPE_CREDIT = 'credit';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GiftCard::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(name: 'gift_card_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private GiftCard $giftCard;

    #[ORM\Column(name: 'type', type: 'string', length: 10)]
    private string $type;

    #[ORM\Column(name: 'amount', type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(name: 'balance_after', type: 'decimal', precision: 10, scale: 2)]
    private string $balanceAfter;

    /** Order reference the transaction belongs to. Snapshots the reference at time of debit. */
    #[ORM\Column(name: 'order_reference', type: 'string', length: 32, nullable: true)]
    private ?string $orderReference;

    /** Order DB id — nullable because the order may not exist yet when the card is applied. */
    #[ORM\Column(name: 'order_id', type: 'bigint', nullable: true)]
    private ?int $orderId;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        GiftCard $giftCard,
        string $type,
        string $amount,
        string $balanceAfter,
        ?string $orderReference = null,
        ?int $orderId = null,
    ) {
        $this->giftCard       = $giftCard;
        $this->type           = $type;
        $this->amount         = $amount;
        $this->balanceAfter   = $balanceAfter;
        $this->orderReference = $orderReference;
        $this->orderId        = $orderId;
        $this->createdAt      = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function getId(): ?int { return $this->id; }
    public function getGiftCard(): GiftCard { return $this->giftCard; }
    public function getType(): string { return $this->type; }
    public function getAmount(): string { return $this->amount; }
    public function getBalanceAfter(): string { return $this->balanceAfter; }
    public function getOrderReference(): ?string { return $this->orderReference; }
    public function getOrderId(): ?int { return $this->orderId; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
