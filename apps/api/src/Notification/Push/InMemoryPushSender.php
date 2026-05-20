<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

/**
 * Test-only PushSenderInterface implementation. Collects every
 * sendToToken() call so tests can assert "we pushed X to token Y".
 *
 * Lives in src (not tests/) for the same reasons as InMemoryMailer:
 * shared across suites, discoverable beside the implementation, and
 * free of test-framework dependencies.
 *
 * Optionally simulates failures: configure a token to throw a given
 * PushException kind on send, so PushNotificationService's
 * pruning/log-and-continue behaviour can be exercised.
 */
final class InMemoryPushSender implements PushSenderInterface
{
    /**
     * @var list<array{token: string, message: PushMessage, context: array<string, mixed>}>
     */
    private array $sent = [];

    /** @var array<string, string> token → PushException kind to throw */
    private array $failingTokens = [];

    public function sendToToken(
        string $token,
        PushMessage $message,
        array $context = [],
    ): void {
        if (isset($this->failingTokens[$token])) {
            throw new PushException(
                kind: $this->failingTokens[$token],
                message: "Simulated push failure for token (kind={$this->failingTokens[$token]}).",
            );
        }
        $this->sent[] = [
            'token' => $token,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * Configure a token to throw on the next send(s).
     * @param string $kind One of PushException::KIND_*.
     */
    public function failToken(string $token, string $kind): void
    {
        $this->failingTokens[$token] = $kind;
    }

    /**
     * @return list<array{token: string, message: PushMessage, context: array<string, mixed>}>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->failingTokens = [];
    }

    /** How many pushes went to a specific token. */
    public function countFor(string $token): int
    {
        return count(array_filter($this->sent, fn (array $s) => $s['token'] === $token));
    }

    /** All tokens that received at least one push, in send order.
     *  @return list<string> */
    public function tokensSent(): array
    {
        return array_map(fn (array $s) => $s['token'], $this->sent);
    }
}
