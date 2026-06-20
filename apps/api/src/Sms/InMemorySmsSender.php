<?php

declare(strict_types=1);

namespace Bayti\Api\Sms;

/**
 * Test-only SmsSenderInterface implementation. Collects every send()
 * call so tests can assert "we sent X to Y". Optionally configured to
 * throw on send to model transport failures.
 *
 * Lives in src/Sms (not tests/) mirroring InMemoryMailer: it carries no
 * test-framework dependency and is shared across suites.
 */
final class InMemorySmsSender implements SmsSenderInterface
{
    /** @var list<array{to: string, message: string}> */
    private array $sent = [];

    public function __construct(private readonly bool $throwOnSend = false)
    {
    }

    public function send(string $toPhone, string $message): void
    {
        if ($this->throwOnSend) {
            throw new SmsException(SmsException::KIND_TRANSPORT, 'forced failure (test)');
        }
        $this->sent[] = ['to' => $toPhone, 'message' => $message];
    }

    /** @return list<array{to: string, message: string}> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function countFor(string $to): int
    {
        return count(array_filter($this->sent, static fn (array $s) => $s['to'] === $to));
    }
}
