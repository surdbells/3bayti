<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\EmailTemplate;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Notification\OrderEmailTemplateRenderer;
use Bayti\Api\Notification\OrderNotificationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OrderNotificationService::class)]
#[CoversClass(InMemoryMailer::class)]
final class OrderNotificationServiceTest extends TestCase
{
    private InMemoryMailer $mailer;
    private OrderNotificationService $service;

    protected function setUp(): void
    {
        $this->mailer = new InMemoryMailer();
        $this->service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: ['ops@3bayti.ae'],
            logger: new NullLogger(),
        );
    }

    #[Test]
    public function orderPlacedFanOutsToCustomerAndEachUniqueVendor(): void
    {
        $order = $this->makeOrder('V3-100');
        $vendor1 = $this->makeVendor(id: 5, email: 'v1@shops.com');
        $vendor2 = $this->makeVendor(id: 6, email: 'v2@shops.com');

        // Three items: two from vendor1, one from vendor2.
        // Vendor1 should still get ONE email (not two).
        $this->addItem($order, $vendor1, 'Item A');
        $this->addItem($order, $vendor1, 'Item B');
        $this->addItem($order, $vendor2, 'Item C');

        $this->service->orderPlaced($order);

        $sent = $this->mailer->sent();
        // 1 customer + 2 vendors = 3 emails
        self::assertCount(3, $sent);

        // Customer
        self::assertSame(1, $this->mailer->countFor('customer@example.com'));
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::ORDER_PLACED_CUSTOMER->value));

        // Vendors
        self::assertSame(1, $this->mailer->countFor('v1@shops.com'));
        self::assertSame(1, $this->mailer->countFor('v2@shops.com'));
        self::assertSame(2, $this->mailer->countByTemplate(EmailTemplate::ORDER_PLACED_VENDOR->value));
    }

    #[Test]
    public function vendorEmailIncludesOnlyTheirOwnItems(): void
    {
        $order = $this->makeOrder('V3-101');
        $vendor1 = $this->makeVendor(id: 5, email: 'v1@shops.com');
        $vendor2 = $this->makeVendor(id: 6, email: 'v2@shops.com');

        $this->addItem($order, $vendor1, 'Vendor1 Special');
        $this->addItem($order, $vendor2, 'Vendor2 Private');

        $this->service->orderPlaced($order);

        $sent = $this->mailer->sent();
        $vendor1Email = array_values(array_filter($sent, fn ($s) => $s['to'] === 'v1@shops.com'))[0];
        $vendor2Email = array_values(array_filter($sent, fn ($s) => $s['to'] === 'v2@shops.com'))[0];

        self::assertStringContainsString('Vendor1 Special', $vendor1Email['text_body']);
        self::assertStringNotContainsString('Vendor2 Private', $vendor1Email['text_body']);

        self::assertStringContainsString('Vendor2 Private', $vendor2Email['text_body']);
        self::assertStringNotContainsString('Vendor1 Special', $vendor2Email['text_body']);
    }

    #[Test]
    public function orderPaidSendsOnlyToCustomer(): void
    {
        $order = $this->makeOrder('V3-102');
        $vendor = $this->makeVendor(id: 5, email: 'v@shops.com');
        $this->addItem($order, $vendor, 'Item');

        $this->service->orderPaid($order);

        $sent = $this->mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('customer@example.com', $sent[0]['to']);
    }

    #[Test]
    public function itemShippedAndItemDeliveredSendToCustomerOnly(): void
    {
        $order = $this->makeOrder('V3-103');
        $vendor = $this->makeVendor(id: 5, email: 'v@shops.com');
        $this->addItem($order, $vendor, 'Shipped Item');
        $item = $order->getItems()->first();
        self::assertNotFalse($item);

        $this->service->itemShipped($order, $item);
        $this->service->itemDelivered($order, $item);

        $sent = $this->mailer->sent();
        self::assertCount(2, $sent);
        foreach ($sent as $s) {
            self::assertSame('customer@example.com', $s['to']);
        }
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::ORDER_SHIPPED_CUSTOMER->value));
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::ORDER_DELIVERED_CUSTOMER->value));
    }

    #[Test]
    public function orderCancelledFanOutsCustomerAndVendors(): void
    {
        $order = $this->makeOrder('V3-104');
        $vendor = $this->makeVendor(id: 5, email: 'v@shops.com');
        $this->addItem($order, $vendor, 'Item');

        $this->service->orderCancelled($order, [
            'refund_issued' => true,
            'refund_amount' => '99.00',
            'reason' => 'fraud',
        ]);

        $sent = $this->mailer->sent();
        self::assertCount(2, $sent);
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::ORDER_CANCELLED_CUSTOMER->value));
        self::assertSame(1, $this->mailer->countByTemplate(EmailTemplate::ORDER_CANCELLED_VENDOR->value));

        // Customer email mentions the refund amount
        $customerEmail = array_values(array_filter($sent, fn ($s) => $s['to'] === 'customer@example.com'))[0];
        self::assertStringContainsString('99.00', $customerEmail['text_body']);
    }

    #[Test]
    public function disputeOpenedSendsToAllAdminRecipients(): void
    {
        $service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: ['ops@3bayti.ae', 'security@3bayti.ae'],
            logger: new NullLogger(),
        );
        $order = $this->makeOrder('V3-105');

        $service->disputeOpened($order, [
            'event_type' => 'CHARGEBACK_OPENED',
            'amount' => '299.00',
            'currency' => 'AED',
            'reason' => 'not received',
        ]);

        self::assertCount(2, $this->mailer->sent());
        self::assertSame(1, $this->mailer->countFor('ops@3bayti.ae'));
        self::assertSame(1, $this->mailer->countFor('security@3bayti.ae'));
    }

    #[Test]
    public function disputeOpenedSendsNothingWhenAdminListEmpty(): void
    {
        $service = new OrderNotificationService(
            mailer: $this->mailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: [],  // empty list
        );
        $order = $this->makeOrder('V3-106');

        $service->disputeOpened($order, []);

        self::assertCount(0, $this->mailer->sent());
    }

    #[Test]
    public function mailerExceptionIsCaughtSilently(): void
    {
        // Throwing mailer simulates ZeptoMail upstream rejection.
        $throwingMailer = new class implements MailerInterface {
            public int $sendAttempts = 0;
            public function send(
                string $to, string $subject, string $textBody,
                string $htmlBody, array $context = [],
            ): void {
                $this->sendAttempts++;
                throw new MailerException(
                    kind: MailerException::KIND_TRANSPORT,
                    message: 'simulated upstream failure',
                );
            }
        };

        $service = new OrderNotificationService(
            mailer: $throwingMailer,
            renderer: new OrderEmailTemplateRenderer(),
            adminRecipients: [],
        );
        $order = $this->makeOrder('V3-107');

        // This MUST NOT throw — fire-and-forget contract
        $service->orderPaid($order);

        // Mailer was called (we tried), but caller didn't see the error
        self::assertSame(1, $throwingMailer->sendAttempts);
    }

    #[Test]
    public function customerWithEmptyEmailIsSkipped(): void
    {
        $order = $this->makeOrder('V3-108', userEmail: '');
        $vendor = $this->makeVendor(id: 5, email: 'v@shops.com');
        $this->addItem($order, $vendor, 'Item');

        $this->service->orderPaid($order);

        // No mail to customer (empty email), vendor route not invoked for orderPaid
        self::assertCount(0, $this->mailer->sent());
    }

    #[Test]
    public function vendorWithEmptyEmailIsSkippedButOthersStillSend(): void
    {
        $order = $this->makeOrder('V3-109');
        $vendorOk = $this->makeVendor(id: 5, email: 'good@shops.com');
        $vendorBad = $this->makeVendor(id: 6, email: '');  // empty
        $this->addItem($order, $vendorOk, 'Item A');
        $this->addItem($order, $vendorBad, 'Item B');

        $this->service->orderPlaced($order);

        // 1 customer + 1 good vendor = 2 (the empty-email vendor is skipped)
        self::assertCount(2, $this->mailer->sent());
        self::assertSame(1, $this->mailer->countFor('customer@example.com'));
        self::assertSame(1, $this->mailer->countFor('good@shops.com'));
    }

    // ===== Helpers =====

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
        // User ctor validates email non-empty; bypass with reflection
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
