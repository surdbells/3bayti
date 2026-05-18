<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\SendAbandonedCartRemindersCommand;
use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartAbandonmentFinder;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Infrastructure\Auth\JwtSettings;
use Bayti\Api\Notification\CartEmailTemplateRenderer;
use Bayti\Api\Notification\CartNotificationService;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\UnsubscribeTokenIssuer;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * X.11-H integration coverage: end-to-end verification that the
 * full cron command stack (Finder → Service → Mailer → log
 * persistence) wires up correctly with REAL service instances
 * and that observability events fire.
 *
 * Mirrors the X.14-E / X.17-E pattern. Bind REAL services with
 * mocked Connection + capturing logger + InMemoryMailer; drive
 * the command through CommandTester; verify:
 *   - emails landed in the mailer
 *   - notification_log rows captured with cart_id + correct
 *     status
 *   - all expected PSR-3 log messages fired through the stack
 *
 * Catches the failure mode where:
 *   - Unit tests pass (each service emits PSR-3 events in isolation)
 *   - Wiring + DI changes silently break log propagation
 *   - Production logs silently die
 */
final class CartRemindersObservabilityIntegrationTest extends TestCase
{
    /** @var list<NotificationLog> */
    private array $recordedLogs = [];

    #[Test]
    public function fullStackProcessesEligibleCartAndEmitsObservability(): void
    {
        $logger = new InMemoryLogger();
        $mailer = new InMemoryMailer();

        $user = $this->makeUser(email: 'alice@example.com', optedOut: false);
        $cart = $this->makeCart(id: 42, user: $user);

        // Connection returns one cart id (42) from the Finder's query
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function () use (&$callCount): Result {
                $r = $this->createMock(Result::class);
                $r->method('fetchAllAssociative')->willReturn([['id' => '42']]);
                return $r;
            },
        );

        // CartRepository returns the cart entity for id=42
        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('find')->willReturnCallback(
            fn (int $id) => $id === 42 ? $cart : null,
        );

        // Notification log capture
        $logRepo = new class($this->recordedLogs) extends NotificationLogRepository {
            /** @param list<NotificationLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(NotificationLog $log): void
            {
                $this->sink[] = $log;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                Cart::class => $cartRepo,
                NotificationLog::class => $logRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );

        // REAL services
        $finder = new CartAbandonmentFinder($em, $logger);
        $renderer = new CartEmailTemplateRenderer();
        $issuer = new UnsubscribeTokenIssuer(JwtSettings::forTesting());
        $service = new CartNotificationService(
            mailer: $mailer,
            renderer: $renderer,
            tokenIssuer: $issuer,
            logger: $logger,
            appBaseUrl: 'https://3bayti.ae',
            em: $em,
        );

        $command = new SendAbandonedCartRemindersCommand(
            em: $em,
            finder: $finder,
            notifications: $service,
            logger: $logger,
        );

        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('carts:send-abandonment-reminders'));

        $exit = $tester->execute([]);

        // Command exited cleanly
        self::assertSame(Command::SUCCESS, $exit);

        // Mailer was called once with the cart owner's email
        self::assertCount(1, $mailer->sent());
        self::assertSame('alice@example.com', $mailer->sent()[0]['to']);
        self::assertSame('cart.abandoned.customer', $mailer->sent()[0]['context']['template']);
        self::assertSame(42, $mailer->sent()[0]['context']['cart_id']);

        // Unsubscribe URL is in the body — full integration through
        // the token issuer
        self::assertStringContainsString(
            'https://3bayti.ae/v3/notifications/unsubscribe?token=',
            $mailer->sent()[0]['text_body'],
        );

        // Notification log row captured with cart_id
        self::assertCount(1, $this->recordedLogs);
        $log = $this->recordedLogs[0];
        self::assertSame('sent', $log->getStatus());
        self::assertSame(42, $log->getCartId());
        self::assertNull($log->getOrderId());

        // Observability fired through the full stack:
        //   - Finder emits cart_abandonment_finder.computed
        //   - Service emits cart_notification.sent
        //   - Command emits cart_reminders.batch_complete
        self::assertCount(1, $logger->findByMessage('cart_abandonment_finder.computed'));
        self::assertCount(1, $logger->findByMessage('cart_notification.sent'));

        $batchLogs = $logger->findByMessage('cart_reminders.batch_complete');
        self::assertCount(1, $batchLogs);
        self::assertSame(1, $batchLogs[0]['context']['found']);
        self::assertSame(1, $batchLogs[0]['context']['processed']);
        self::assertSame(0, $batchLogs[0]['context']['errors']);
        self::assertSame(24, $batchLogs[0]['context']['threshold_hours']);
    }

    #[Test]
    public function optedOutUserShortCircuitsAtServiceWithSkippedLog(): void
    {
        $logger = new InMemoryLogger();
        $mailer = new InMemoryMailer();

        $user = $this->makeUser(email: 'optedout@example.com', optedOut: true);
        $cart = $this->makeCart(id: 42, user: $user);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function (): Result {
                $r = $this->createMock(Result::class);
                $r->method('fetchAllAssociative')->willReturn([['id' => '42']]);
                return $r;
            },
        );

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('find')->willReturn($cart);

        $logRepo = new class($this->recordedLogs) extends NotificationLogRepository {
            /** @param list<NotificationLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(NotificationLog $log): void
            {
                $this->sink[] = $log;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                Cart::class => $cartRepo,
                NotificationLog::class => $logRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );

        $service = new CartNotificationService(
            mailer: $mailer,
            renderer: new CartEmailTemplateRenderer(),
            tokenIssuer: new UnsubscribeTokenIssuer(JwtSettings::forTesting()),
            logger: $logger,
            appBaseUrl: 'https://3bayti.ae',
            em: $em,
        );

        $command = new SendAbandonedCartRemindersCommand(
            em: $em,
            finder: new CartAbandonmentFinder($em, $logger),
            notifications: $service,
            logger: $logger,
        );

        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('carts:send-abandonment-reminders'));

        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);

        // NO email sent — user opted out at service layer
        self::assertCount(0, $mailer->sent());

        // SKIPPED row written with cart_id → guarantees next run
        // excludes this cart
        self::assertCount(1, $this->recordedLogs);
        $log = $this->recordedLogs[0];
        self::assertSame('skipped', $log->getStatus());
        self::assertSame('marketing_opted_out', $log->getErrorMessage());
        self::assertSame(42, $log->getCartId());

        // Skip was logged at the service layer
        self::assertCount(1, $logger->findByMessage('cart_notification.skipped'));
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeUser(string $email, bool $optedOut): User
    {
        $u = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(User::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($u, 100);
        $emailRef = new \ReflectionProperty(User::class, 'email');
        $emailRef->setAccessible(true);
        $emailRef->setValue($u, $email);
        $localeRef = new \ReflectionProperty(User::class, 'locale');
        $localeRef->setAccessible(true);
        $localeRef->setValue($u, 'en');
        $optRef = new \ReflectionProperty(User::class, 'marketingEmailsOptOut');
        $optRef->setAccessible(true);
        $optRef->setValue($u, $optedOut);
        return $u;
    }

    private function makeCart(int $id, User $user): Cart
    {
        $cart = new Cart(user: $user);
        $idRef = new \ReflectionProperty(Cart::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($cart, $id);

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $pIdRef = new \ReflectionProperty(Product::class, 'id');
        $pIdRef->setAccessible(true);
        $pIdRef->setValue($product, 200);
        $nameRef = new \ReflectionProperty(Product::class, 'name');
        $nameRef->setAccessible(true);
        $nameRef->setValue($product, 'Vintage Lamp');

        $item = new CartItem(
            product: $product,
            quantity: 1,
            unitPriceSnapshot: '50.00',
        );
        $cart->addItem($item);
        return $cart;
    }
}
