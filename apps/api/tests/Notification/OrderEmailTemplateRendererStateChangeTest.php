<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\EmailTemplate;
use Bayti\Api\Notification\OrderEmailTemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F7c, customer notifications for the previously-silent item transitions
 * (accepted, preparing, rejected). Verifies each template renders the order
 * reference + item name in subject/text/html, in both locales.
 */
#[CoversClass(OrderEmailTemplateRenderer::class)]
final class OrderEmailTemplateRendererStateChangeTest extends TestCase
{
    private OrderEmailTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new OrderEmailTemplateRenderer();
    }

    /**
     * @return array<string, array{0: EmailTemplate}>
     */
    public static function stateChangeTemplates(): array
    {
        return [
            'accepted'  => [EmailTemplate::ORDER_ACCEPTED_CUSTOMER],
            'preparing' => [EmailTemplate::ORDER_PREPARING_CUSTOMER],
            'rejected'  => [EmailTemplate::ORDER_REJECTED_CUSTOMER],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('stateChangeTemplates')]
    public function rendersStateChangeTemplateEn(EmailTemplate $template): void
    {
        $order = $this->makeOrder('V3-777');
        $r = $this->renderer->render($template, $order, ['item_name' => 'Silk Abaya'], 'en');

        self::assertStringContainsString('V3-777', $r->subject);
        self::assertStringContainsString('V3-777', $r->textBody);
        self::assertStringContainsString('V3-777', $r->htmlBody);
        self::assertStringContainsString('Silk Abaya', $r->textBody);
        self::assertStringContainsString('Silk Abaya', $r->htmlBody);
        self::assertNotSame('', $r->subject);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('stateChangeTemplates')]
    public function rendersStateChangeTemplateAr(EmailTemplate $template): void
    {
        $order = $this->makeOrder('V3-778');
        $r = $this->renderer->render($template, $order, ['item_name' => 'عباية حرير'], 'ar');

        self::assertStringContainsString('V3-778', $r->subject);
        self::assertStringContainsString('عباية حرير', $r->textBody);
        self::assertStringContainsString('عباية حرير', $r->htmlBody);
    }

    #[Test]
    public function rejectedTemplateEscapesHtmlInItemName(): void
    {
        $order = $this->makeOrder('V3-779');
        $r = $this->renderer->render(
            EmailTemplate::ORDER_REJECTED_CUSTOMER,
            $order,
            ['item_name' => '<script>alert(1)</script>'],
            'en',
        );
        self::assertStringContainsString('&lt;script&gt;', $r->htmlBody);
        self::assertStringNotContainsString('<script>alert(1)</script>', $r->htmlBody);
    }

    private function makeOrder(string $reference): Order
    {
        $user = new User('customer@example.com', '+971501234567', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $rp = new \ReflectionProperty($user, 'id');
        $rp->setAccessible(true);
        $rp->setValue($user, 42);

        $order = new Order(user: $user, orderReference: $reference, subtotal: '99.00');
        $rp = new \ReflectionProperty($order, 'id');
        $rp->setAccessible(true);
        $rp->setValue($order, 100);
        return $order;
    }
}
