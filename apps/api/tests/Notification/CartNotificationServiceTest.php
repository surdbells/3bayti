<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Infrastructure\Auth\JwtSettings;
use Bayti\Api\Notification\CartEmailTemplateRenderer;
use Bayti\Api\Notification\CartNotificationService;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\UnsubscribeTokenIssuer;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CartNotificationService (M3.2.X.11-E).
 *
 * Covers all 4 dispatch outcomes:
 *   1. SENT    , happy path; mailer called, notification_log row created
 *   2. SKIPPED , eligibility check fails; no mailer call; SKIPPED row
 *                  written with the reason so X.11-C finder excludes
 *                  the cart on future runs
 *   3. FAILED  , mailer throws MailerException; FAILED row captures
 *                  kind + message
 *   4. FAILED  , mailer throws generic Throwable; FAILED row captures
 *                  the class name as kind
 *
 * Verifies opt-out gating, unsubscribe URL embedding, locale dispatch
 * via User::getLocale, and cart_id persistence on every notification
 * log row.
 */
#[CoversClass(CartNotificationService::class)]
final class CartNotificationServiceTest extends TestCase
{
    /** @var list<NotificationLog> */
    private array $recordedLogs = [];
    private InMemoryMailer $mailer;
    private CartEmailTemplateRenderer $renderer;
    private UnsubscribeTokenIssuer $tokenIssuer;

    protected function setUp(): void
    {
        $this->recordedLogs = [];
        $this->mailer = new InMemoryMailer();
        $this->renderer = new CartEmailTemplateRenderer();
        $this->tokenIssuer = new UnsubscribeTokenIssuer(JwtSettings::forTesting());
    }

    // =================================================================
    // Happy path
    // =================================================================

    #[Test]
    public function sendsEmailToOptedInCustomer(): void
    {
        $user = $this->makeUser(email: 'alice@example.com', optedOut: false);
        $cart = $this->makeCart($user);

        $svc = $this->makeService();
        $svc->cartAbandoned($cart);

        // Mailer called
        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('alice@example.com', $sent[0]['to']);
        self::assertStringContainsString('left 1 item', $sent[0]['subject']);
        self::assertSame('cart.abandoned.customer', $sent[0]['context']['template']);
        self::assertSame(42, $sent[0]['context']['cart_id']);

        // Unsubscribe URL embedded in body
        self::assertStringContainsString('/v3/notifications/unsubscribe?token=', $sent[0]['text_body']);
        // Resume URL embedded
        self::assertStringContainsString('/cart?cart_id=42', $sent[0]['text_body']);

        // notification_log row written: SENT + cart_id populated
        self::assertCount(1, $this->recordedLogs);
        $log = $this->recordedLogs[0];
        self::assertSame('sent', $log->getStatus());
        self::assertSame(42, $log->getCartId());
        self::assertNull($log->getOrderId());
        self::assertSame('cart.abandoned.customer', $log->getTemplate());
        self::assertSame('alice@example.com', $log->getRecipient());
    }

    #[Test]
    public function arabicLocaleDispatchesArabicTemplate(): void
    {
        $user = $this->makeUser(email: 'ali@example.com', optedOut: false, locale: 'ar');
        $cart = $this->makeCart($user);

        $svc = $this->makeService();
        $svc->cartAbandoned($cart);

        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        // Arabic subject
        self::assertStringContainsString('تركت', $sent[0]['subject']);
        // HTML body has rtl direction
        self::assertStringContainsString('dir="rtl"', $sent[0]['html_body']);
        // Locale captured in context
        self::assertSame('ar', $sent[0]['context']['locale']);
    }

    #[Test]
    public function bcp47LocaleNormalizedToShortForm(): void
    {
        // Long-form BCP-47 like 'ar-AE' should still dispatch Arabic
        $user = $this->makeUser(email: 'ali@example.com', optedOut: false, locale: 'ar-AE');
        $cart = $this->makeCart($user);

        $svc = $this->makeService();
        $svc->cartAbandoned($cart);

        $sent = $this->mailer->sent();
        self::assertSame('ar', $sent[0]['context']['locale']);
    }

    // =================================================================
    // Opt-out gating
    // =================================================================

    #[Test]
    public function optedOutUserGetsNoMailerCallButSkippedLog(): void
    {
        $user = $this->makeUser(email: 'opted-out@example.com', optedOut: true);
        $cart = $this->makeCart($user);

        $svc = $this->makeService();
        $svc->cartAbandoned($cart);

        // No mailer call
        self::assertCount(0, $this->mailer->sent());

        // SKIPPED log row written with the reason, cart_id populated
        self::assertCount(1, $this->recordedLogs);
        $log = $this->recordedLogs[0];
        self::assertSame('skipped', $log->getStatus());
        self::assertSame(42, $log->getCartId());
        self::assertSame('marketing_opted_out', $log->getErrorMessage());
    }

    #[Test]
    public function cartWithoutUserGetsSkippedLog(): void
    {
        $cart = $this->makeCartWithoutUser();

        $svc = $this->makeService();
        $svc->cartAbandoned($cart);

        self::assertCount(0, $this->mailer->sent());
        self::assertCount(1, $this->recordedLogs);
        self::assertSame('skipped', $this->recordedLogs[0]->getStatus());
        self::assertSame('no_user', $this->recordedLogs[0]->getErrorMessage());
    }

    #[Test]
    public function userWithEmptyEmailGetsSkippedLog(): void
    {
        $user = $this->makeUser(email: '', optedOut: false);
        $cart = $this->makeCart($user);

        $svc = $this->makeService();
        $svc->cartAbandoned($cart);

        self::assertCount(0, $this->mailer->sent());
        self::assertCount(1, $this->recordedLogs);
        self::assertSame('skipped', $this->recordedLogs[0]->getStatus());
        self::assertSame('no_email', $this->recordedLogs[0]->getErrorMessage());
    }

    // =================================================================
    // Mailer failure paths
    // =================================================================

    #[Test]
    public function mailerExceptionCapturedAsFailedLog(): void
    {
        $user = $this->makeUser(email: 'alice@example.com', optedOut: false);
        $cart = $this->makeCart($user);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(
            new MailerException(kind: 'transport', message: 'SMTP timeout'),
        );

        $svc = new CartNotificationService(
            mailer: $mailer,
            renderer: $this->renderer,
            tokenIssuer: $this->tokenIssuer,
            logger: new NullLogger(),
            appBaseUrl: 'https://3bayti.ae',
            em: $this->captureLogsEm(),
        );

        $svc->cartAbandoned($cart);

        // FAILED log with kind + message
        self::assertCount(1, $this->recordedLogs);
        $log = $this->recordedLogs[0];
        self::assertSame('failed', $log->getStatus());
        self::assertSame('transport', $log->getErrorKind());
        self::assertSame('SMTP timeout', $log->getErrorMessage());
        self::assertSame(42, $log->getCartId());
    }

    #[Test]
    public function genericThrowableCapturedAsFailedLog(): void
    {
        $user = $this->makeUser(email: 'alice@example.com', optedOut: false);
        $cart = $this->makeCart($user);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willThrowException(
            new \RuntimeException('Boom'),
        );

        $svc = new CartNotificationService(
            mailer: $mailer,
            renderer: $this->renderer,
            tokenIssuer: $this->tokenIssuer,
            logger: new NullLogger(),
            appBaseUrl: 'https://3bayti.ae',
            em: $this->captureLogsEm(),
        );

        $svc->cartAbandoned($cart);

        self::assertCount(1, $this->recordedLogs);
        $log = $this->recordedLogs[0];
        self::assertSame('failed', $log->getStatus());
        // Generic throwable: error_kind = class name
        self::assertSame(\RuntimeException::class, $log->getErrorKind());
        self::assertSame('Boom', $log->getErrorMessage());
    }

    // =================================================================
    // Persistence resilience
    // =================================================================

    #[Test]
    public function persistenceFailureDoesNotBlockSend(): void
    {
        $user = $this->makeUser(email: 'alice@example.com', optedOut: false);
        $cart = $this->makeCart($user);

        // EM throws on getRepository call, simulates DB outage
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willThrowException(
            new \RuntimeException('connection refused'),
        );

        $logger = new InMemoryLogger();
        $svc = new CartNotificationService(
            mailer: $this->mailer,
            renderer: $this->renderer,
            tokenIssuer: $this->tokenIssuer,
            logger: $logger,
            appBaseUrl: 'https://3bayti.ae',
            em: $em,
        );

        // Should NOT throw
        $svc->cartAbandoned($cart);

        // Mailer still called (send succeeded; persistence failure is secondary)
        self::assertCount(1, $this->mailer->sent());
        // Failure logged
        $failures = $logger->findByMessage('cart_notification.log_persist_failed');
        self::assertCount(1, $failures);
    }

    #[Test]
    public function nullEmConstructorMakesPersistenceNoOp(): void
    {
        // Tests / dev environments without DB wiring
        $user = $this->makeUser(email: 'alice@example.com', optedOut: false);
        $cart = $this->makeCart($user);

        $svc = new CartNotificationService(
            mailer: $this->mailer,
            renderer: $this->renderer,
            tokenIssuer: $this->tokenIssuer,
            logger: new NullLogger(),
            appBaseUrl: 'https://3bayti.ae',
            em: null,
        );

        $svc->cartAbandoned($cart);

        // Email sent
        self::assertCount(1, $this->mailer->sent());
        // No logs persisted (no EM)
        self::assertCount(0, $this->recordedLogs);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeUser(string $email, bool $optedOut, string $locale = 'en'): User
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
        $localeRef->setValue($u, $locale);
        $optRef = new \ReflectionProperty(User::class, 'marketingEmailsOptOut');
        $optRef->setAccessible(true);
        $optRef->setValue($u, $optedOut);
        return $u;
    }

    private function makeCart(User $user): Cart
    {
        $cart = new Cart(user: $user);
        $idRef = new \ReflectionProperty(Cart::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($cart, 42);

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

    private function makeCartWithoutUser(): Cart
    {
        $cart = new Cart(legacyCartCode: 'LEG-001');
        $idRef = new \ReflectionProperty(Cart::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($cart, 42);

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $pIdRef = new \ReflectionProperty(Product::class, 'id');
        $pIdRef->setAccessible(true);
        $pIdRef->setValue($product, 200);
        $nameRef = new \ReflectionProperty(Product::class, 'name');
        $nameRef->setAccessible(true);
        $nameRef->setValue($product, 'Vintage Lamp');

        $cart->addItem(new CartItem(
            product: $product,
            quantity: 1,
            unitPriceSnapshot: '50.00',
        ));

        return $cart;
    }

    private function captureLogsEm(): EntityManagerInterface
    {
        $repo = new class($this->recordedLogs) extends NotificationLogRepository {
            /** @param list<NotificationLog> $sink */
            public function __construct(private array &$sink)
            {
                // Skip the parent EntityRepository ctor, we never
                // touch ORM internals in this capture class.
            }
            public function save(NotificationLog $log): void
            {
                $this->sink[] = $log;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(NotificationLog::class)
            ->willReturn($repo);
        return $em;
    }

    private function makeService(): CartNotificationService
    {
        return new CartNotificationService(
            mailer: $this->mailer,
            renderer: $this->renderer,
            tokenIssuer: $this->tokenIssuer,
            logger: new NullLogger(),
            appBaseUrl: 'https://3bayti.ae',
            em: $this->captureLogsEm(),
        );
    }
}
