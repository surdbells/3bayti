<?php

declare(strict_types=1);

namespace Bayti\Api\Shipping;

/**
 * A failure while creating a shipment with the courier provider.
 *
 * `kind` lets callers/tests distinguish causes without string-matching the
 * message: a genuine provider/network error vs. a caller precondition
 * (provider not configured, or the vendor's pickup address is incomplete).
 */
final class ShippingException extends \RuntimeException
{
    public const KIND_NOT_CONFIGURED   = 'not_configured';
    public const KIND_INCOMPLETE_PICKUP = 'incomplete_pickup';
    public const KIND_NO_ADDRESS        = 'no_address';
    public const KIND_NO_ITEMS          = 'no_shippable_items';
    public const KIND_ALREADY_BOOKED    = 'already_booked';
    public const KIND_NETWORK           = 'network';
    public const KIND_AUTH              = 'auth';
    public const KIND_TRANSPORT         = 'transport';
    public const KIND_MALFORMED         = 'malformed';

    /**
     * HTTP status a controller should surface for this failure kind: caller
     * preconditions are 4xx; a genuine provider/network failure is 502
     * (we reached out and the courier API failed).
     */
    public static function httpStatusFor(string $kind): int
    {
        return match ($kind) {
            self::KIND_ALREADY_BOOKED => 409,
            self::KIND_NOT_CONFIGURED,
            self::KIND_INCOMPLETE_PICKUP,
            self::KIND_NO_ADDRESS,
            self::KIND_NO_ITEMS => 422,
            default => 502,
        };
    }

    public function __construct(
        public readonly string $kind,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self(self::KIND_NOT_CONFIGURED, 'Courier shipping is not configured.');
    }

    public static function incompletePickup(): self
    {
        return new self(
            self::KIND_INCOMPLETE_PICKUP,
            'This store has no complete pickup address; add one before booking delivery.',
        );
    }

    public static function noAddress(): self
    {
        return new self(self::KIND_NO_ADDRESS, 'The order has no delivery address.');
    }
}
