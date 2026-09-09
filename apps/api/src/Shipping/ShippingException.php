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
    public const KIND_NETWORK           = 'network';
    public const KIND_AUTH              = 'auth';
    public const KIND_TRANSPORT         = 'transport';
    public const KIND_MALFORMED         = 'malformed';

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
