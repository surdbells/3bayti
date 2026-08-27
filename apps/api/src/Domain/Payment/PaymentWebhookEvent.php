<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Payment;

use Bayti\Api\Domain\Order\Order;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit log of webhook deliveries from the payment gateway.
 *
 * Why a separate table from payment_transactions
 * ===============================================
 * payment_transactions records OUR actions (server-initiated calls).
 * payment_webhook_events records THE GATEWAY'S actions (incoming
 * notifications). They have different lifecycles, different write
 * patterns (append-only here; mutable status there), different
 * authentication models (we sign requests; gateway signs webhooks),
 * and different replay semantics.
 *
 * Append-only
 * ============
 * Never UPDATE or DELETE rows in normal operation. `processed_at`
 * is the one field that gets set after a row is first inserted (so
 * the dead-letter retry cron can re-process unprocessed events).
 *
 * Idempotency
 * ============
 * Noon's webhook delivery is at-least-once with redelivery on
 * non-2xx response. We dedup by `idempotency_key` (UNIQUE), same
 * webhook arriving twice produces a constraint-violation on the
 * second INSERT, which the handler catches and treats as "already
 * processed, return 200 idempotently".
 *
 * Signature verification
 * =======================
 * Noon webhook docs are gated behind the merchant portal, we don't
 * yet know the signature algorithm. M3.1.6 ships with the
 * `LoggingOnlyVerifier` that always returns true and records
 * `signature_verified = false` so M3.1.7's empirical-capture work
 * can be done against real data.
 *
 * The retrieve-order-before-acting safety net (Noon-recommended per
 * their test/evaluating-api docs page) is the LOAD-BEARING security
 * mechanism: even an unverified webhook can only trigger an action
 * if Noon's own GET_ORDER confirms the payment.
 *
 * provider_order_ref + event_type
 * ================================
 * Extracted from the payload at receive time and indexed for queries
 * like "all events for Noon order X" and reconciliation. The raw
 * payload stays in `payload` JSONB for full audit.
 *
 * order_id FK
 * ============
 * ON DELETE SET NULL, if an order is later deleted (admin tooling
 * in M3.1.7+), the webhook history stays intact for compliance.
 */
#[ORM\Entity(repositoryClass: PaymentWebhookEventRepository::class)]
#[ORM\Table(name: 'payment_webhook_events')]
class PaymentWebhookEvent
{
    public const PROVIDER_NOON = 'noon';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 32)]
    private string $provider = self::PROVIDER_NOON;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 128, unique: true)]
    private string $idempotencyKey;

    #[ORM\Column(name: 'provider_order_ref', type: 'string', length: 64, nullable: true)]
    private ?string $providerOrderRef = null;

    #[ORM\Column(name: 'event_type', type: 'string', length: 64, nullable: true)]
    private ?string $eventType = null;

    #[ORM\Column(name: 'signature_header', type: 'text', nullable: true)]
    private ?string $signatureHeader = null;

    #[ORM\Column(name: 'signature_verified', type: 'boolean')]
    private bool $signatureVerified = false;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $order = null;

    #[ORM\Column(name: 'received_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $receivedAt;

    #[ORM\Column(name: 'processed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    /**
     * @param array<string, mixed> $payload The full parsed JSON body
     */
    public function __construct(
        string $idempotencyKey,
        array $payload,
        ?string $providerOrderRef = null,
        ?string $eventType = null,
        ?string $signatureHeader = null,
        bool $signatureVerified = false,
        ?Order $order = null,
        string $provider = self::PROVIDER_NOON,
    ) {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException(
                "PaymentWebhookEvent idempotency_key must be 1-128 chars, got " . strlen($idempotencyKey)
            );
        }

        $this->idempotencyKey = $idempotencyKey;
        $this->payload = $payload;
        $this->providerOrderRef = $providerOrderRef;
        $this->eventType = $eventType;
        $this->signatureHeader = $signatureHeader;
        $this->signatureVerified = $signatureVerified;
        $this->order = $order;
        $this->provider = $provider;
        $this->receivedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getProvider(): string { return $this->provider; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getProviderOrderRef(): ?string { return $this->providerOrderRef; }
    public function getEventType(): ?string { return $this->eventType; }
    public function getSignatureHeader(): ?string { return $this->signatureHeader; }
    public function isSignatureVerified(): bool { return $this->signatureVerified; }
    /** @return array<string, mixed> */
    public function getPayload(): array { return $this->payload; }
    public function getOrder(): ?Order { return $this->order; }
    public function getReceivedAt(): DateTimeImmutable { return $this->receivedAt; }
    public function getProcessedAt(): ?DateTimeImmutable { return $this->processedAt; }

    public function isProcessed(): bool
    {
        return $this->processedAt !== null;
    }

    /**
     * Mark the event as processed. Called AFTER the webhook handler
     * has finished applying the state transition (and the
     * retrieve-order safety check passed).
     */
    public function markProcessed(?DateTimeImmutable $when = null): void
    {
        $this->processedAt = $when ?? new DateTimeImmutable();
    }

    public function attachOrder(Order $order): void
    {
        $this->order = $order;
    }
}
