<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Support\Otp;

use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\MailerInterface;

/**
 * MailerInterface double that records into an InMemoryMailer and can run a
 * ONE-SHOT hook right after sending an email whose subject contains a given
 * text — to interleave a concurrent request while another one is still in the
 * middle of sending (e.g. a failure during a recovery email).
 */
final class HookingMailer implements MailerInterface
{
    private ?string $subjectContains = null;

    /** @var (\Closure(): void)|null */
    private ?\Closure $hook = null;

    public function __construct(private readonly InMemoryMailer $inner)
    {
    }

    /**
     * @param \Closure(): void $hook
     */
    public function onceAfter(string $subjectContains, \Closure $hook): void
    {
        $this->subjectContains = $subjectContains;
        $this->hook = $hook;
    }

    public function send(
        string $to,
        string $subject,
        string $textBody,
        string $htmlBody,
        array $context = [],
    ): void {
        $this->inner->send($to, $subject, $textBody, $htmlBody, $context);
        if ($this->hook !== null && $this->subjectContains !== null && str_contains($subject, $this->subjectContains)) {
            $hook = $this->hook;
            $this->hook = null;
            $hook();
        }
    }
}
