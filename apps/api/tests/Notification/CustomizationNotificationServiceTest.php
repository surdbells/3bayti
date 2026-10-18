<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\CustomizationNotificationService;
use Bayti\Api\Notification\InMemoryMailer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The vendor-facing customization emails must link to the portal's real
 * requests page (apps/portal route 'customization'). They used to link to
 * '/vendor/customization-requests', which does not exist in the portal.
 */
#[CoversClass(CustomizationNotificationService::class)]
final class CustomizationNotificationServiceTest extends TestCase
{
    private ?string $previousPortalUrl = null;

    protected function setUp(): void
    {
        $this->previousPortalUrl = $_ENV['VENDOR_PORTAL_URL'] ?? null;
        $_ENV['VENDOR_PORTAL_URL'] = 'https://app.3bayti.ae/';
    }

    protected function tearDown(): void
    {
        if ($this->previousPortalUrl === null) {
            unset($_ENV['VENDOR_PORTAL_URL']);
        } else {
            $_ENV['VENDOR_PORTAL_URL'] = $this->previousPortalUrl;
        }
    }

    #[Test]
    public function newRequestEmailLinksToThePortalCustomizationPage(): void
    {
        $mailer = new InMemoryMailer();
        (new CustomizationNotificationService($mailer, new NullLogger()))
            ->customizationSubmitted($this->request());

        $this->assertVendorLink($mailer);
    }

    #[Test]
    public function quoteAcceptedEmailLinksToThePortalCustomizationPage(): void
    {
        $mailer = new InMemoryMailer();
        (new CustomizationNotificationService($mailer, new NullLogger()))
            ->customizationAccepted($this->request());

        $this->assertVendorLink($mailer);
    }

    private function assertVendorLink(InMemoryMailer $mailer): void
    {
        $sent = $mailer->sent();
        self::assertCount(1, $sent);
        self::assertSame('studio@atelier-noor.test', $sent[0]['to']);
        foreach (['text_body', 'html_body'] as $part) {
            self::assertStringContainsString('https://app.3bayti.ae/customization', $sent[0][$part]);
            self::assertStringNotContainsString('/vendor/customization-requests', $sent[0][$part]);
        }
    }

    private function request(): CustomizationRequest
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', 501);
        $this->setProp($product, 'name', 'Silk Abaya');

        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($vendor, 'id', 12);
        $this->setProp($vendor, 'name', 'Atelier Noor');
        $this->setProp($vendor, 'contactEmail', 'studio@atelier-noor.test');

        $customer = new User(
            email: 'customer@example.com',
            phone: '+971501234567',
            passwordHash: password_hash('p', PASSWORD_BCRYPT),
            countryCode: 'AE',
        );
        $this->setProp($customer, 'id', 42);

        return new CustomizationRequest($product, $vendor, $customer, 'Add gold sleeve embroidery');
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
