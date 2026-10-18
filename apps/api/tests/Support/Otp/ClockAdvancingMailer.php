<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Support\Otp;

use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;

/**
 * MailerInterface double for a SLOW mail service: every send moves the test
 * clock forward by $seconds, then succeeds or fails with a (non-network)
 * transport error — e.g. ZeptoMail hanging until its timeout, then a 5xx.
 */
final class ClockAdvancingMailer implements MailerInterface
{
    /** @var list<string> */
    public array $attempts = [];

    /**
     * @param \Closure(int): mixed $advance moves the test clock by N seconds
     */
    public function __construct(
        private readonly \Closure $advance,
        private readonly int $seconds,
        private readonly bool $fail,
    ) {
    }

    public function send(
        string $to,
        string $subject,
        string $textBody,
        string $htmlBody,
        array $context = [],
    ): void {
        $this->attempts[] = $to;
        ($this->advance)($this->seconds);
        if ($this->fail) {
            throw new MailerException(MailerException::KIND_TRANSPORT, 'ZeptoMail HTTP 503 after a long wait');
        }
    }
}
