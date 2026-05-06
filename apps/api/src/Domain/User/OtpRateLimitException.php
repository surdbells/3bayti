<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

/**
 * Raised by OtpService::send when the per-phone rate limit is
 * exceeded.
 *
 * Caller (auth endpoint) translates this to 429 Too Many Requests
 * with a generic "please try again later" message. We deliberately
 * don't tell the user how many sends they've made or when the
 * window resets — that would help attackers profile the limit.
 */
final class OtpRateLimitException extends \RuntimeException
{
}
