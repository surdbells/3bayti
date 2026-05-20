<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification\Push;

use Bayti\Api\Notification\Push\InMemoryPushSender;
use Bayti\Api\Notification\Push\NullPushSender;
use Bayti\Api\Notification\Push\PushException;
use Bayti\Api\Notification\Push\PushMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(NullPushSender::class)]
#[CoversClass(InMemoryPushSender::class)]
#[CoversClass(PushMessage::class)]
#[CoversClass(PushException::class)]
final class PushSenderAdaptersTest extends TestCase
{
    #[Test]
    public function nullSenderLogsButDoesNotThrow(): void
    {
        $records = [];
        $logger = new class ($records) extends AbstractLogger {
            /** @param array<int,array{level:mixed,message:string|\Stringable}> $records */
            public function __construct(private array &$records)
            {
            }
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $sender = new NullPushSender($logger);
        $sender->sendToToken('a-very-long-device-token-1234567890', new PushMessage('t', 'body', ['k' => 'v']));

        self::assertCount(1, $records);
        self::assertSame('push.would_send', $records[0]['message']);
        // Never logs the full token — only a suffix.
        self::assertSame('…567890', $records[0]['context']['token_suffix']);
        self::assertSame(['k'], $records[0]['context']['data_keys']);
    }

    #[Test]
    public function inMemorySenderCollectsSends(): void
    {
        $sender = new InMemoryPushSender();
        $sender->sendToToken('tok-1', new PushMessage('Title', 'Body', ['type' => 'x']));
        $sender->sendToToken('tok-1', new PushMessage('Title2', 'Body2'));
        $sender->sendToToken('tok-2', new PushMessage('Title3', 'Body3'));

        self::assertCount(3, $sender->sent());
        self::assertSame(2, $sender->countFor('tok-1'));
        self::assertSame(1, $sender->countFor('tok-2'));
        self::assertSame(['tok-1', 'tok-1', 'tok-2'], $sender->tokensSent());
        self::assertSame('Title', $sender->sent()[0]['message']->title);
        self::assertSame('x', $sender->sent()[0]['message']->data['type']);
    }

    #[Test]
    public function inMemorySenderResetClears(): void
    {
        $sender = new InMemoryPushSender();
        $sender->sendToToken('tok-1', new PushMessage('t', 'b'));
        $sender->reset();
        self::assertCount(0, $sender->sent());
    }

    #[Test]
    public function inMemorySenderCanSimulateFailure(): void
    {
        $sender = new InMemoryPushSender();
        $sender->failToken('dead', PushException::KIND_UNREGISTERED);

        try {
            $sender->sendToToken('dead', new PushMessage('t', 'b'));
            $this->fail('Expected PushException');
        } catch (PushException $e) {
            self::assertSame(PushException::KIND_UNREGISTERED, $e->kind);
            self::assertTrue($e->isTokenDead());
        }
        // The failed send is NOT recorded as sent.
        self::assertCount(0, $sender->sent());
    }

    #[Test]
    public function pushExceptionIsTokenDeadOnlyForUnregistered(): void
    {
        self::assertTrue((new PushException(PushException::KIND_UNREGISTERED, 'x'))->isTokenDead());
        self::assertFalse((new PushException(PushException::KIND_NETWORK, 'x'))->isTokenDead());
        self::assertFalse((new PushException(PushException::KIND_AUTH, 'x'))->isTokenDead());
    }
}
