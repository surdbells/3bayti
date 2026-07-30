<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\EmailTemplate;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\OrderEmailTemplateRenderer;
use Bayti\Api\Notification\OrderNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Coverage for the M3.2.X.4-B persistence wiring in
 * OrderNotificationService::safeSend + the skipped-path guards.
 *
 * Each public lifecycle method must produce the correct NotificationLog
 * rows regardless of outcome (sent/failed/skipped). Repository write
 * failures must NEVER propagate to the caller.
 *
 * Repository resolution is LAZY per call — the service holds an
 * EntityManagerInterface and resolves the repository inside
 * safePersist(). Tests construct a mock EM that returns the capturing
 * repo when getRepository(NotificationLog::class) is called.
 */
#[CoversClass(OrderNotificationService::class)]
#[CoversClass(NotificationLog::class)]
final class OrderNotificationServicePersistenceTest extends TestCase
{
    private InMemoryMailer $mailer;
    /** @var list<NotificationLog> Captured rows from the test repository */
    private array $captured = [];
    private EntityManagerInterface $em;
    private OrderNotificationService $service;

    protected function setUp(): void
    {
        $this->mailer = new InMemoryMailer();
        $this->captured = [];

        $this->em = $this->buildEmReturningCapturingRepo($this->captured);
        $this->service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: ['ops@3bayti.ae'],
            logger: new NullLogger(),
            em: $this->em,
        );
    }

    /**
     * Build an EntityManagerInterface mock that returns a capturing
     * NotificationLogRepository when asked for NotificationLog::class.
     *
     * @param list<NotificationLog> $sink (passed by reference)
     */
    private function buildEmReturningCapturingRepo(array &$sink): EntityManagerInterface
    {
        $repo = new class($sink) extends NotificationLogRepository {
            public function __construct(private array &$sink) {}
            public function save(NotificationLog $log): void
            {
                $this->sink[] = $log;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static function (string $class) use ($repo): ?object {
                if ($class === NotificationLog::class) {
                    return $repo;
                }
                return null;
            }
        );
        return $em;
    }

    /**
     * Build an EM whose NotificationLogRepository::save throws on
     * every call — verifies the repository-failure-doesn't-propagate
     * contract.
     */
    private function buildEmWithThrowingRepo(): EntityManagerInterface
    {
        $throwingRepo = new class extends NotificationLogRepository {
            public function __construct() {}
            public function save(NotificationLog $log): void
            {
                throw new \RuntimeException('database connection lost');
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static function (string $class) use ($throwingRepo): ?object {
                if ($class === NotificationLog::class) {
                    return $throwingRepo;
                }
                return null;
            }
        );
        return $em;
    }

    #[Test]
    public function sentEmailProducesSentRow(): void
    {
        $order = $this->makeOrder('V3-200');
        $this->service->orderPaid($order);

        self::assertCount(1, $this->captured);
        $log = $this->captured[0];
        self::assertSame(NotificationLog::STATUS_SENT, $log->getStatus());
        self::assertSame(EmailTemplate::ORDER_PAID_CUSTOMER->value, $log->getTemplate());
        self::assertSame('customer@example.com', $log->getRecipient());
        self::assertSame($order->getId(), $log->getOrderId());
        self::assertNull($log->getErrorKind());
        self::assertNull($log->getErrorMessage());
    }

    #[Test]
    public function mailerExceptionProducesFailedRowWithKind(): void
    {
        $throwingMailer = new class implements MailerInterface {
            public function send(
                string $to,
                string $subject,
                string $textBody,
                string $htmlBody,
                array $context = [],
            ): void {
                throw new MailerException(
                    kind: MailerException::KIND_TRANSPORT,
                    message: 'simulated upstream 503',
                );
            }
        };

        $service = new OrderNotificationService(
            mailer: $throwingMailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: [],
            logger: new NullLogger(),
            em: $this->em,
        );

        $order = $this->makeOrder('V3-201');
        $service->orderPaid($order); // must not throw

        self::assertCount(1, $this->captured);
        $log = $this->captured[0];
        self::assertSame(NotificationLog::STATUS_FAILED, $log->getStatus());
        self::assertSame(MailerException::KIND_TRANSPORT, $log->getErrorKind());
        self::assertSame('simulated upstream 503', $log->getErrorMessage());
    }

    #[Test]
    public function genericThrowableProducesFailedRowWithClassName(): void
    {
        // Generic non-MailerException (e.g. rendering bug, null deref).
        // The error_kind should be the exception's class name.
        $throwingMailer = new class implements MailerInterface {
            public function send(
                string $to,
                string $subject,
                string $textBody,
                string $htmlBody,
                array $context = [],
            ): void {
                throw new \RuntimeException('unexpected null deref');
            }
        };

        $service = new OrderNotificationService(
            mailer: $throwingMailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: [],
            logger: new NullLogger(),
            em: $this->em,
        );

        $order = $this->makeOrder('V3-202');
        $service->orderPaid($order); // must not throw

        self::assertCount(1, $this->captured);
        $log = $this->captured[0];
        self::assertSame(NotificationLog::STATUS_FAILED, $log->getStatus());
        self::assertSame('RuntimeException', $log->getErrorKind());
        self::assertSame('unexpected null deref', $log->getErrorMessage());
    }

    #[Test]
    public function customerWithEmptyEmailProducesSkippedRow(): void
    {
        $order = $this->makeOrder('V3-203', userEmail: '');
        $this->service->orderPaid($order);

        self::assertCount(1, $this->captured);
        $log = $this->captured[0];
        self::assertSame(NotificationLog::STATUS_SKIPPED, $log->getStatus());
        self::assertSame(EmailTemplate::ORDER_PAID_CUSTOMER->value, $log->getTemplate());
        self::assertSame('no_email', $log->getErrorMessage());
        self::assertNull(
            $log->getErrorKind(),
            'skipped rows distinguish from failed via NULL error_kind',
        );
        self::assertSame('', $log->getRecipient(), 'recipient is empty for skipped/no_email case');
    }

    #[Test]
    public function vendorWithEmptyEmailProducesSkippedRow(): void
    {
        $order = $this->makeOrder('V3-204');
        $vendorOk = $this->makeVendor(id: 5, email: 'good@shops.com');
        $vendorBad = $this->makeVendor(id: 6, email: ''); // empty
        $this->addItem($order, $vendorOk, 'Item A');
        $this->addItem($order, $vendorBad, 'Item B');

        $this->service->orderPlaced($order);

        // 1 customer (sent) + 1 vendorOk (sent) + 1 vendorBad (skipped) = 3 rows
        self::assertCount(3, $this->captured);

        // Find the skipped row
        $skipped = array_values(array_filter(
            $this->captured,
            static fn (NotificationLog $log): bool => $log->getStatus() === NotificationLog::STATUS_SKIPPED,
        ));
        self::assertCount(1, $skipped);
        self::assertSame('no_email', $skipped[0]->getErrorMessage());
        self::assertSame(EmailTemplate::ORDER_PLACED_VENDOR->value, $skipped[0]->getTemplate());
    }

    #[Test]
    public function vendorWithoutContactEmailFallsBackToOwnerAccountEmail(): void
    {
        // Vendor entity with an UNINITIALIZED contact_email (typed property
        // never set) but a linked owner account. The new-order email must fall
        // back to the owner's email instead of silently skipping — a vendor
        // without a dedicated contact address still gets notified.
        $order = $this->makeOrder('V3-205');
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($vendor, 'id', 7);
        $this->setEntityProp($vendor, 'name', 'Vendor 7');
        // contactEmail intentionally NOT set; owner account carries the email.
        $this->setEntityProp($vendor, 'ownerUser', $this->makeUser('owner@shops.com'));
        $this->addItem($order, $vendor, 'Item');

        $this->service->orderPlaced($order);

        // 1 customer (sent) + 1 vendor (sent to the owner email) = 2 rows.
        self::assertCount(2, $this->captured);
        $vendorRow = array_values(array_filter(
            $this->captured,
            static fn (NotificationLog $log): bool =>
                $log->getTemplate() === EmailTemplate::ORDER_PLACED_VENDOR->value,
        ));
        self::assertCount(1, $vendorRow);
        self::assertSame(NotificationLog::STATUS_SENT, $vendorRow[0]->getStatus());
        self::assertSame('owner@shops.com', $vendorRow[0]->getRecipient());
    }

    #[Test]
    public function disputeWithNoAdminRecipientsProducesSkippedRow(): void
    {
        $service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: [], // empty
            logger: new NullLogger(),
            em: $this->em,
        );

        $order = $this->makeOrder('V3-206');
        $service->disputeOpened($order);

        self::assertCount(1, $this->captured);
        $log = $this->captured[0];
        self::assertSame(NotificationLog::STATUS_SKIPPED, $log->getStatus());
        self::assertSame(EmailTemplate::DISPUTE_OPENED_ADMIN->value, $log->getTemplate());
        self::assertSame('no_admin_recipients', $log->getErrorMessage());
    }

    #[Test]
    public function repositoryFailureDoesNotPropagateToCaller(): void
    {
        // CRITICAL: the notification log MUST NEVER block the primary
        // action. If the save() call itself throws, the caller continues.
        $throwingEm = $this->buildEmWithThrowingRepo();

        $service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: [],
            logger: new NullLogger(),
            em: $throwingEm,
        );

        $order = $this->makeOrder('V3-207');

        // This MUST NOT throw — the contract is preserved.
        $service->orderPaid($order);

        // The mailer still sent (primary action unaffected)
        self::assertCount(1, $this->mailer->sent());
    }

    #[Test]
    public function fanOutProducesOneLogPerEmailSent(): void
    {
        // orderPlaced fans out to customer + each unique vendor.
        // Every email must produce exactly one log row.
        $order = $this->makeOrder('V3-208');
        $v1 = $this->makeVendor(id: 5, email: 'v1@shops.com');
        $v2 = $this->makeVendor(id: 6, email: 'v2@shops.com');
        $this->addItem($order, $v1, 'Item A');
        $this->addItem($order, $v1, 'Item B'); // same vendor; deduplicates
        $this->addItem($order, $v2, 'Item C');

        $this->service->orderPlaced($order);

        // 1 customer + 2 unique vendors = 3 emails → 3 logs
        self::assertCount(3, $this->captured);
        foreach ($this->captured as $log) {
            self::assertSame(NotificationLog::STATUS_SENT, $log->getStatus());
        }

        // Recipients captured correctly
        $recipients = array_map(
            static fn (NotificationLog $log): string => $log->getRecipient(),
            $this->captured,
        );
        sort($recipients);
        self::assertSame(
            ['customer@example.com', 'v1@shops.com', 'v2@shops.com'],
            $recipients,
        );
    }

    #[Test]
    public function nullEntityManagerIsAcceptedAndPersistenceIsNoOp(): void
    {
        // Tests + dev environments can construct the service without
        // an EM (matches the existing NullLogger pattern). When null,
        // persistence becomes a no-op; emails still send.
        //
        // Note: this is the primary defensive path for tests that
        // don't care about notification_logs persistence. Passing
        // null is cleaner than wiring a mock EM just to get a no-op.
        // The other branch — EM returning non-NotificationLogRepository —
        // is covered structurally by the `instanceof` check in
        // safePersist; can't easily be unit-tested because the EM
        // interface declares EntityRepository as the return type and
        // PHPUnit mocks reject incompatible types.
        $service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: [],
            logger: new NullLogger(),
            em: null,
        );

        $order = $this->makeOrder('V3-209');
        $service->orderPaid($order); // must not throw

        self::assertCount(1, $this->mailer->sent(), 'Email still sends');
        self::assertCount(0, $this->captured, 'Repository is no-op when EM is null');
    }

    // ===== Helpers (mirrored from OrderNotificationServiceTest) =====

    private function makeOrder(string $reference, string $userEmail = 'customer@example.com'): Order
    {
        $user = $userEmail === ''
            ? $this->makeUserWithEmptyEmail()
            : $this->makeUser($userEmail);
        $order = new Order(user: $user, orderReference: $reference, subtotal: '99.00');
        $this->setEntityId($order, random_int(100, 999));
        return $order;
    }

    private function makeUser(string $email): User
    {
        $u = new User($email, '+971501234567', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->setEntityId($u, 42);
        return $u;
    }

    private function makeUserWithEmptyEmail(): User
    {
        $u = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($u, 'id', 42);
        $this->setEntityProp($u, 'email', '');
        return $u;
    }

    private function makeVendor(int $id, string $email): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($v, 'id', $id);
        $this->setEntityProp($v, 'name', "Vendor {$id}");
        $this->setEntityProp($v, 'contactEmail', $email);
        return $v;
    }

    private function addItem(Order $order, Vendor $vendor, string $name): void
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', random_int(200, 999));
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product, vendor: $vendor,
            quantity: 1, unitPrice: '99.00',
            productNameSnapshot: $name,
            productImageSnapshot: 'cdn/x.jpg',
        );
        $this->setEntityId($item, random_int(500, 999));
        $order->addItem($item);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
