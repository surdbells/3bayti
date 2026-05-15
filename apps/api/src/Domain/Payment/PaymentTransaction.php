<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Payment;

use Bayti\Api\Domain\Common\Timestamps;
use Bayti\Api\Domain\Order\Order;
use Doctrine\ORM\Mapping as ORM;

/**
 * Record of a single API call to (or from) the payment gateway.
 *
 * One Order has many PaymentTransactions
 * =======================================
 * Each operation (INITIATE, SALE, CAPTURE, REFUND, etc.) creates
 * a separate row. The complete payment lifecycle of an order can
 * be reconstructed by reading the rows for an order_id ordered by
 * created_at.
 *
 * Example for a successful card payment:
 *   t1: operation=INITIATE,  status=Initiated   (we called Noon)
 *   t2: operation=GET_ORDER, status=Authorized  (we polled Noon after redirect)
 *   t3: operation=SALE,      status=Captured    (Noon SALE webhook)
 *
 * If something goes wrong:
 *   t1: operation=INITIATE, status=Failed       (Noon rejected; resultCode 19012 e.g.)
 *
 * provider field
 * ===============
 * Defaults to 'noon' but the column exists so the pluggable gateway
 * architecture (C11) doesn't require a schema change to add a new
 * gateway. M3.1.6 only ships the Noon adapter.
 *
 * operation enum
 * ===============
 * All 9 Noon operations. Validated by both PHP-side validation here
 * AND the DB-side CHECK constraint chk_payment_tx_operation.
 *
 * idempotency_key
 * ================
 * Application-generated per attempt. Format convention:
 *   <order_reference>:<operation>:<attempt-suffix>
 * E.g. '3B-K2T9X1-AB42:INITIATE:1', '3B-K2T9X1-AB42:GET_ORDER:1'.
 * UNIQUE at the DB layer — duplicate operation submission produces
 * a clear constraint-violation error rather than a silent double-call.
 *
 * Payload columns
 * ================
 * JSONB at the DB layer; stored as raw array in PHP. Captures the
 * full request + response for audit. Card numbers and CVVs do NOT
 * touch our server in the Hosted Checkout flow — Noon's hosted page
 * collects them — but we mask any potentially-sensitive field in the
 * Noon adapter before storing.
 */
#[ORM\Entity(repositoryClass: PaymentTransactionRepository::class)]
#[ORM\Table(name: 'payment_transactions')]
#[ORM\HasLifecycleCallbacks]
class PaymentTransaction
{
    use Timestamps;

    public const PROVIDER_NOON = 'noon';

    public const OPERATION_INITIATE = 'INITIATE';
    public const OPERATION_SALE = 'SALE';
    public const OPERATION_AUTHORIZE = 'AUTHORIZE';
    public const OPERATION_CAPTURE = 'CAPTURE';
    public const OPERATION_REVERSE = 'REVERSE';
    public const OPERATION_REFUND = 'REFUND';
    public const OPERATION_CANCEL = 'CANCEL';
    public const OPERATION_GET_ORDER = 'GET_ORDER';
    public const OPERATION_GET_ORDER_BY_REFERENCE = 'GET_ORDER_BY_REFERENCE';

    private const ALL_OPERATIONS = [
        self::OPERATION_INITIATE,
        self::OPERATION_SALE,
        self::OPERATION_AUTHORIZE,
        self::OPERATION_CAPTURE,
        self::OPERATION_REVERSE,
        self::OPERATION_REFUND,
        self::OPERATION_CANCEL,
        self::OPERATION_GET_ORDER,
        self::OPERATION_GET_ORDER_BY_REFERENCE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Order $order;

    #[ORM\Column(type: 'string', length: 32)]
    private string $provider = self::PROVIDER_NOON;

    #[ORM\Column(type: 'string', length: 32)]
    private string $operation;

    #[ORM\Column(name: 'provider_order_ref', type: 'string', length: 64, nullable: true)]
    private ?string $providerOrderRef = null;

    /**
     * Free-form gateway-specific status. Noon: 'Initiated', 'Authorized',
     * 'Captured', 'Failed', 'Refunded', etc. We don't normalise to a v3
     * enum because new gateways could have different vocabularies.
     */
    #[ORM\Column(type: 'string', length: 32)]
    private string $status;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(type: 'string', length: 3)]
    private string $currency = 'AED';

    #[ORM\Column(name: 'noon_result_code', type: 'integer', nullable: true)]
    private ?int $noonResultCode = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'request_payload', type: 'json', nullable: true)]
    private ?array $requestPayload = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'response_payload', type: 'json', nullable: true)]
    private ?array $responsePayload = null;

    #[ORM\Column(name: 'idempotency_key', type: 'string', length: 128, unique: true)]
    private string $idempotencyKey;

    /**
     * @param array<string, mixed>|null $requestPayload  Raw Noon API request body (serialised to JSONB)
     * @param array<string, mixed>|null $responsePayload Raw Noon API response body (serialised to JSONB)
     */
    /**
     * @param array<string, mixed>|null $requestPayload
     * @param array<string, mixed>|null $responsePayload
     */
    public function __construct(
        Order $order,
        string $operation,
        string $status,
        string $amount,
        string $idempotencyKey,
        string $provider = self::PROVIDER_NOON,
        ?string $providerOrderRef = null,
        string $currency = 'AED',
        ?int $noonResultCode = null,
        ?array $requestPayload = null,
        ?array $responsePayload = null,
    ) {
        if (!in_array($operation, self::ALL_OPERATIONS, true)) {
            throw new \InvalidArgumentException("Unknown PaymentTransaction operation: '{$operation}'");
        }
        if ($status === '') {
            throw new \InvalidArgumentException('PaymentTransaction status cannot be empty.');
        }
        if (bccomp($amount, '0.00', 2) < 0) {
            throw new \InvalidArgumentException("PaymentTransaction amount must be >= 0, got '{$amount}'");
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new \InvalidArgumentException(
                "PaymentTransaction idempotency_key must be 1-128 chars, got " . strlen($idempotencyKey)
            );
        }

        $this->order = $order;
        $this->operation = $operation;
        $this->status = $status;
        $this->amount = $amount;
        $this->idempotencyKey = $idempotencyKey;
        $this->provider = $provider;
        $this->providerOrderRef = $providerOrderRef;
        $this->currency = $currency;
        $this->noonResultCode = $noonResultCode;
        $this->requestPayload = $requestPayload;
        $this->responsePayload = $responsePayload;
        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrder(): Order { return $this->order; }
    public function getProvider(): string { return $this->provider; }
    public function getOperation(): string { return $this->operation; }
    public function getProviderOrderRef(): ?string { return $this->providerOrderRef; }
    public function getStatus(): string { return $this->status; }
    public function getAmount(): string { return $this->amount; }
    public function getCurrency(): string { return $this->currency; }
    public function getNoonResultCode(): ?int { return $this->noonResultCode; }
    /** @return array<string, mixed>|null */
    public function getRequestPayload(): ?array { return $this->requestPayload; }
    /** @return array<string, mixed>|null */
    public function getResponsePayload(): ?array { return $this->responsePayload; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }

    /**
     * Update the gateway-reported status. Used when polling
     * GET_ORDER after a redirect lands but before the webhook
     * arrives.
     */
    public function setStatus(string $status): void
    {
        if ($status === '') {
            throw new \InvalidArgumentException('PaymentTransaction status cannot be empty.');
        }
        $this->status = $status;
        $this->touchUpdatedAt();
    }

    public function setProviderOrderRef(?string $providerOrderRef): void
    {
        $this->providerOrderRef = $providerOrderRef;
        $this->touchUpdatedAt();
    }
}
