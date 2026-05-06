<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

/**
 * Outcomes of OtpService::verify().
 *
 * Caller should treat all non-Success values as a generic auth
 * failure to the user — distinguishing 'wrong code' from 'expired'
 * publicly leaks timing/state info to attackers. The values are
 * still useful for internal logging and analytics.
 */
enum VerifyResult: string
{
    /** CPaaS confirmed the code matches. Caller should proceed. */
    case Success = 'success';

    /** CPaaS rejected the code. */
    case WrongCode = 'wrong_code';

    /** Local row's TTL has elapsed. */
    case Expired = 'expired';

    /** Local row is already marked consumed (single-use). */
    case Consumed = 'consumed';

    /** No row for this verificationId in our database. */
    case NotFound = 'not_found';
}
