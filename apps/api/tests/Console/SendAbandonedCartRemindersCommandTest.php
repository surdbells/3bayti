<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\SendAbandonedCartRemindersCommand;
use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartAbandonmentFinder;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\CartNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for SendAbandonedCartRemindersCommand (M3.2.X.11-F).
 *
 * Strategy: mock the EM, finder, and notification service via
 * PHPUnit; drive the command through Symfony's CommandTester.
 *
 * Test matrix:
 *   - No eligible carts → SUCCESS, no flush
 *   - Eligible carts → cartAbandoned called per cart + flush per cart
 *   - --dry-run → lists IDs without calling the service or flushing
 *   - Cart disappears between Finder and find() → continues batch
 *   - Service throws on one cart → batch continues + error counted
 *   - --threshold-hours clamped to range [1, 168]
 */
final class SendAbandonedCartRemindersCommandTest extends TestCase
{
    private EntityManagerInterface $em;
    private CartRepository $cartRepo;
    private CartAbandonmentFinder $finder;
    private CartNotificationService $notifications;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->cartRepo = $this->createMock(CartRepository::class);
        // Class doubles work here because neither CartAbandonmentFinder
        // nor CartNotificationService is final (the finder was
        // intentionally non-final per the X.10-C pattern; the
        // notification service is just final-by-default removable).
        $this->finder = $this->createMock(CartAbandonmentFinder::class);
        $this->notifications = $this->createMock(CartNotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->em->method('getRepository')->willReturnCallback(
            fn (string $class): object => match ($class) {
                Cart::class => $this->cartRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );
    }

    // =================================================================
    // Empty batch
    // =================================================================

    #[Test]
    public function noEligibleCartsExitsSuccessWithoutDispatch(): void
    {
        $this->finder->expects(self::once())
            ->method('findEligibleCartIds')
            ->willReturn([]);
        // No flush, no dispatch
        $this->em->expects(self::never())->method('flush');
        $this->notifications->expects(self::never())->method('cartAbandoned');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Nothing to send', $tester->getDisplay());
    }

    // =================================================================
    // Happy path
    // =================================================================

    #[Test]
    public function dispatchesReminderPerEligibleCart(): void
    {
        $cart101 = $this->makeCart(101);
        $cart202 = $this->makeCart(202);

        $this->finder->method('findEligibleCartIds')->willReturn([101, 202]);
        $this->cartRepo->method('find')->willReturnCallback(
            fn (int $id) => match ($id) {
                101 => $cart101,
                202 => $cart202,
                default => null,
            },
        );

        $invokedWith = [];
        $this->notifications->expects(self::exactly(2))
            ->method('cartAbandoned')
            ->willReturnCallback(function (Cart $c) use (&$invokedWith): void {
                $invokedWith[] = $c->getId();
            });

        // Per-cart flush
        $this->em->expects(self::exactly(2))->method('flush');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame([101, 202], $invokedWith);
        self::assertStringContainsString('Found 2 eligible cart(s)', $tester->getDisplay());
    }

    // =================================================================
    // Dry run
    // =================================================================

    #[Test]
    public function dryRunListsIdsWithoutDispatch(): void
    {
        $this->finder->method('findEligibleCartIds')->willReturn([101, 202, 303]);
        // No cart hydration, no dispatch, no flush
        $this->cartRepo->expects(self::never())->method('find');
        $this->notifications->expects(self::never())->method('cartAbandoned');
        $this->em->expects(self::never())->method('flush');

        $tester = $this->tester();
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('DRY RUN', $display);
        self::assertStringContainsString('101, 202, 303', $display);
    }

    // =================================================================
    // Resilience
    // =================================================================

    #[Test]
    public function cartDisappearingMidRunDoesNotAbortBatch(): void
    {
        $cart101 = $this->makeCart(101);
        $cart303 = $this->makeCart(303);

        $this->finder->method('findEligibleCartIds')->willReturn([101, 202, 303]);
        // Cart 202 was deleted between Finder query and now → null
        $this->cartRepo->method('find')->willReturnCallback(
            fn (int $id) => match ($id) {
                101 => $cart101,
                202 => null,
                303 => $cart303,
                default => null,
            },
        );

        $invokedWith = [];
        $this->notifications->method('cartAbandoned')
            ->willReturnCallback(function (Cart $c) use (&$invokedWith): void {
                $invokedWith[] = $c->getId();
            });

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        // 101 + 303 still processed; 202 quietly skipped
        self::assertSame([101, 303], $invokedWith);
    }

    #[Test]
    public function perCartFailureDoesNotAbortBatch(): void
    {
        $cart101 = $this->makeCart(101);
        $cart202 = $this->makeCart(202);
        $cart303 = $this->makeCart(303);

        $this->finder->method('findEligibleCartIds')->willReturn([101, 202, 303]);
        $this->cartRepo->method('find')->willReturnCallback(
            fn (int $id) => match ($id) {
                101 => $cart101,
                202 => $cart202,
                303 => $cart303,
                default => null,
            },
        );

        // Cart 202 throws; 101 + 303 succeed.
        $invokedWith = [];
        $this->notifications->method('cartAbandoned')
            ->willReturnCallback(function (Cart $c) use (&$invokedWith): void {
                $invokedWith[] = $c->getId();
                if ($c->getId() === 202) {
                    throw new \RuntimeException('transient failure');
                }
            });

        $tester = $this->tester();
        $exit = $tester->execute([]);

        // One error → FAILURE exit code but batch still ran
        self::assertSame(Command::FAILURE, $exit);
        self::assertSame([101, 202, 303], $invokedWith);
        self::assertStringContainsString('Cart #202 failed', $tester->getDisplay());
    }

    // =================================================================
    // Threshold clamping
    // =================================================================

    #[Test]
    public function thresholdHoursForwardedToFinder(): void
    {
        $captured = null;
        $this->finder->expects(self::once())
            ->method('findEligibleCartIds')
            ->willReturnCallback(function (\DateTimeImmutable $_now, \DateInterval $threshold, int $_limit) use (&$captured): array {
                $captured = $threshold->h + ($threshold->d * 24);
                return [];
            });

        $tester = $this->tester();
        $tester->execute(['--threshold-hours' => '48']);

        self::assertSame(48, $captured);
    }

    #[Test]
    public function thresholdHoursClampedToMinimum(): void
    {
        $captured = null;
        $this->finder->method('findEligibleCartIds')
            ->willReturnCallback(function (\DateTimeImmutable $_now, \DateInterval $threshold, int $_limit) use (&$captured): array {
                $captured = $threshold->h + ($threshold->d * 24);
                return [];
            });

        $tester = $this->tester();
        $tester->execute(['--threshold-hours' => '0']);

        self::assertSame(1, $captured);  // MIN_THRESHOLD_HOURS
    }

    #[Test]
    public function thresholdHoursClampedToMaximum(): void
    {
        $captured = null;
        $this->finder->method('findEligibleCartIds')
            ->willReturnCallback(function (\DateTimeImmutable $_now, \DateInterval $threshold, int $_limit) use (&$captured): array {
                $captured = $threshold->h + ($threshold->d * 24);
                return [];
            });

        $tester = $this->tester();
        $tester->execute(['--threshold-hours' => '9999']);

        self::assertSame(168, $captured);  // MAX_THRESHOLD_HOURS
    }

    #[Test]
    public function batchSizeForwardedAndClamped(): void
    {
        $capturedLimit = null;
        $this->finder->method('findEligibleCartIds')
            ->willReturnCallback(function (\DateTimeImmutable $_now, \DateInterval $_t, int $limit) use (&$capturedLimit): array {
                $capturedLimit = $limit;
                return [];
            });

        $tester = $this->tester();
        $tester->execute(['--batch-size' => '9999']);

        // Clamped to CartAbandonmentFinder::MAX_BATCH_SIZE (500)
        self::assertSame(500, $capturedLimit);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function tester(): CommandTester
    {
        $command = new SendAbandonedCartRemindersCommand(
            em: $this->em,
            finder: $this->finder,
            notifications: $this->notifications,
            logger: $this->logger,
        );
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('carts:send-abandonment-reminders'));
    }

    private function makeCart(int $id): Cart
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(User::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($user, 100);

        $cart = new Cart(user: $user);
        $cIdRef = new \ReflectionProperty(Cart::class, 'id');
        $cIdRef->setAccessible(true);
        $cIdRef->setValue($cart, $id);
        return $cart;
    }
}
