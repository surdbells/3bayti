<?php

declare(strict_types=1);

namespace Bayti\Api\Notification\Push;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * PushSenderInterface implementation that logs the would-be push to
 * structured logs instead of calling FCM.
 *
 * Bound in dev/staging when no FCM service account is configured, so
 * developers see "what WOULD have been pushed" without provisioning
 * real credentials. Also the safe default whenever credentials are
 * absent.
 */
final class NullPushSender implements PushSenderInterface
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function sendToToken(
        string $token,
        PushMessage $message,
        array $context = [],
    ): void {
        $this->logger->info('push.would_send', array_merge($context, [
            'token_suffix' => $this->tokenSuffix($token),
            'title' => $message->title,
            'body_length' => strlen($message->body),
            'data_keys' => array_keys($message->data),
        ]));
    }

    /** Log only the last 6 chars of a token — never the full secret. */
    private function tokenSuffix(string $token): string
    {
        return strlen($token) <= 6 ? $token : '…' . substr($token, -6);
    }
}
