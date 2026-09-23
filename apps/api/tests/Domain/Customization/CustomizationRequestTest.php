<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Customization;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the P5 CustomizationRequest state machine.
 *
 * Direct-construction tests (no DB, no DI); ids/relations set via
 * reflection, matching OrderReturnRequestTest.
 */
#[CoversClass(CustomizationRequest::class)]
final class CustomizationRequestTest extends TestCase
{
    #[Test]
    public function constructsPendingWithSnapshot(): void
    {
        $req = $this->make(measurement: ['bust' => 90, 'waist' => 70]);

        self::assertSame(CustomizationRequest::STATUS_PENDING, $req->getStatus());
        self::assertSame('Add gold sleeve embroidery', $req->getCustomerNotes());
        self::assertSame(['bust' => 90, 'waist' => 70], $req->getMeasurementSnapshot());
        self::assertNull($req->getQuoteAmount());
        self::assertFalse($req->isTerminal());
    }

    #[Test]
    public function rejectsBlankDescription(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty description');
        new CustomizationRequest($this->product(), $this->vendor(), $this->customer(), '   ');
    }

    #[Test]
    public function happyPathQuoteAcceptPayComplete(): void
    {
        $req = $this->make();

        $req->quote('150.00', 'aed', 7, 'Two weeks turnaround');
        self::assertSame(CustomizationRequest::STATUS_QUOTED, $req->getStatus());
        self::assertSame('150.00', $req->getQuoteAmount());
        self::assertSame('AED', $req->getQuoteCurrency()); // upper-cased
        self::assertSame(7, $req->getQuoteLeadTimeDays());
        self::assertNotNull($req->getQuotedAt());

        $req->acceptQuote();
        self::assertSame(CustomizationRequest::STATUS_ACCEPTED, $req->getStatus());

        $req->attachPaymentOrderReference('3B-CUS-0001');
        self::assertSame('3B-CUS-0001', $req->getPaymentOrderReference());

        $req->markPaid();
        self::assertSame(CustomizationRequest::STATUS_PAID, $req->getStatus());
        self::assertNotNull($req->getPaidAt());

        $req->markCompleted();
        self::assertSame(CustomizationRequest::STATUS_COMPLETED, $req->getStatus());
        self::assertTrue($req->isTerminal());
    }

    #[Test]
    public function vendorCanDeclineFromPending(): void
    {
        $req = $this->make();
        $req->declineByVendor('Not something we offer');
        self::assertSame(CustomizationRequest::STATUS_DECLINED, $req->getStatus());
        self::assertSame('Not something we offer', $req->getVendorNotes());
        self::assertTrue($req->isTerminal());
    }

    #[Test]
    public function customerCanRejectAQuote(): void
    {
        $req = $this->make();
        $req->quote('150.00', 'AED', null, null);
        $req->rejectQuote();
        self::assertSame(CustomizationRequest::STATUS_REJECTED, $req->getStatus());
        self::assertTrue($req->isTerminal());
    }

    #[Test]
    public function customerCanCancelFromPendingOrQuoted(): void
    {
        $a = $this->make();
        $a->cancelByCustomer();
        self::assertSame(CustomizationRequest::STATUS_CANCELLED, $a->getStatus());

        $b = $this->make();
        $b->quote('80.00', 'AED', null, null);
        $b->cancelByCustomer();
        self::assertSame(CustomizationRequest::STATUS_CANCELLED, $b->getStatus());
    }

    #[Test]
    public function cannotAcceptBeforeQuote(): void
    {
        $req = $this->make();
        $this->expectException(\DomainException::class);
        $req->acceptQuote();
    }

    #[Test]
    public function cannotQuoteTwice(): void
    {
        $req = $this->make();
        $req->quote('150.00', 'AED', null, null);
        $this->expectException(\DomainException::class);
        $req->quote('160.00', 'AED', null, null);
    }

    #[Test]
    public function cannotMarkPaidBeforeAccept(): void
    {
        $req = $this->make();
        $req->quote('150.00', 'AED', null, null);
        $this->expectException(\DomainException::class);
        $req->markPaid();
    }

    #[Test]
    public function rejectsNonPositiveQuote(): void
    {
        $req = $this->make();
        $this->expectException(\InvalidArgumentException::class);
        $req->quote('0.00', 'AED', null, null);
    }

    #[Test]
    public function rejectsMalformedCurrency(): void
    {
        $req = $this->make();
        $this->expectException(\InvalidArgumentException::class);
        $req->quote('150.00', 'DIRHAM', null, null);
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed>|null $measurement */
    private function make(?array $measurement = null): CustomizationRequest
    {
        return new CustomizationRequest(
            $this->product(),
            $this->vendor(),
            $this->customer(),
            'Add gold sleeve embroidery',
            $measurement,
        );
    }

    private function product(): Product
    {
        $p = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($p, 'id', 501);
        $this->setProp($p, 'name', 'Silk Abaya');
        return $p;
    }

    private function vendor(): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($v, 'id', 12);
        $this->setProp($v, 'name', 'Atelier Noor');
        return $v;
    }

    private function customer(): User
    {
        $u = new User(
            email: 'customer@example.com',
            phone: '+971501234567',
            passwordHash: password_hash('p', PASSWORD_BCRYPT),
            countryCode: 'AE',
        );
        $this->setProp($u, 'id', 42);
        return $u;
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
