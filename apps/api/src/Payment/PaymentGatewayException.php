<?php

declare(strict_types=1);

namespace Bayti\Api\Payment;

use RuntimeException;
use Throwable;

/**
 * Provider-agnostic exception raised by any PaymentGateway
 * implementation when an upstream call fails or returns an
 * unexpected response.
 *
 * Subkinds carry signal in $kind so the calling controller
 * can decide between retry / give-up / user-visible-error:
 *
 *   - 'network'        — couldn't even reach the gateway (timeout,
 *                        DNS, connection refused). Safe to retry.
 *   - 'timeout'        — gateway accepted the request but didn't
 *                        respond in time. Order state UNKNOWN —
 *                        must call retrieve-order to clarify.
 *   - 'auth'           — credentials rejected. Config bug, not
 *                        a runtime error; surface to ops.
 *   - 'malformed'      — gateway returned a body we couldn't
 *                        parse. Treat as 'unknown' for order
 *                        state; alert ops.
 *   - 'upstream'       — gateway returned a 4xx/5xx with a
 *                        recognisable error body. $providerCode
 *                        carries the gateway's own code (e.g.
 *                        Noon resultCode).
 *   - 'duplicate_ref'  — provider rejected because the merchant
 *                        reference is already in use. Caller
 *                        should call retrieve-order on the
 *                        existing ref to discover the real status.
 *   - 'rate_limited'   — 429 from gateway. Per recon, Noon will
 *                        outright ban an IP that abuses GET_ORDER
 *                        polling, so callers MUST back off.
 */
class PaymentGatewayException extends RuntimeException
{
    public const KIND_NETWORK = 'network';
    public const KIND_TIMEOUT = 'timeout';
    public const KIND_AUTH = 'auth';
    public const KIND_MALFORMED = 'malformed';
    public const KIND_UPSTREAM = 'upstream';
    public const KIND_DUPLICATE_REF = 'duplicate_ref';
    public const KIND_RATE_LIMITED = 'rate_limited';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $providerCode = null,
        public readonly ?string $providerMessage = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function network(string $message, ?Throwable $previous = null): self
    {
        return new self(self::KIND_NETWORK, $message, null, null, $previous);
    }

    public static function timeout(string $message, ?Throwable $previous = null): self
    {
        return new self(self::KIND_TIMEOUT, $message, null, null, $previous);
    }

    public static function auth(string $message): self
    {
        return new self(self::KIND_AUTH, $message);
    }

    public static function malformed(string $message, ?Throwable $previous = null): self
    {
        return new self(self::KIND_MALFORMED, $message, null, null, $previous);
    }

    public static function upstream(int $providerCode, string $providerMessage): self
    {
        return new self(
            self::KIND_UPSTREAM,
            "Gateway error {$providerCode}: {$providerMessage}",
            $providerCode,
            $providerMessage,
        );
    }

    public static function duplicateRef(int $providerCode, string $providerMessage): self
    {
        return new self(
            self::KIND_DUPLICATE_REF,
            "Duplicate merchant reference: {$providerMessage}",
            $providerCode,
            $providerMessage,
        );
    }

    public static function rateLimited(string $message): self
    {
        return new self(self::KIND_RATE_LIMITED, $message);
    }
}
