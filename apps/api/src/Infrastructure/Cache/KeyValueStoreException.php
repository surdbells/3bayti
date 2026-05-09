<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Cache;

use RuntimeException;
use Throwable;

/**
 * Thrown by KeyValueStore implementations on backend failure
 * (network error, auth failure, command syntax error, etc.).
 *
 * Callers should catch this exception and decide whether to
 * fail open (degrade gracefully) or fail closed (refuse the
 * request) based on the use case.
 */
final class KeyValueStoreException extends RuntimeException
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
