<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Support\Otp;

use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;

/**
 * MailerInterface double that always fails with the given MailerException
 * kind, recording each attempted recipient.
 */
final class FailingMailer implements MailerInterface
{
    /** @var list<string> */
    public array $attempts = [];

    public function __construct(private readonly string $kind = MailerException::KIND_TRANSPORT)
    {
    }

    public function send(
        string $to,
        string $subject,
        string $textBody,
        string $htmlBody,
        array $context = [],
    ): void {
        $this->attempts[] = $to;
        throw new MailerException($this->kind, "ZeptoMail failed ({$this->kind})");
    }
}
