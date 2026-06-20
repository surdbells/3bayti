<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\GiftCard;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\GiftCard\PurchaseGiftCardController;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Focused tests for the recipient-contact additions to
 * PurchaseGiftCardController: the optional recipient_email +
 * recipient_phone are validated and threaded into the created card.
 *
 * Invokes the controller directly with a mocked EM/repository so the
 * created GiftCard can be inspected without a database.
 */
#[CoversClass(PurchaseGiftCardController::class)]
final class PurchaseGiftCardControllerTest extends TestCase
{
    private ?GiftCard $saved = null;

    #[Test]
    public function acceptsRecipientEmailAndPhoneAndThreadsThemIntoTheCard(): void
    {
        $response = $this->invoke([
            'denomination' => '500.00',
            'theme' => 'birthday',
            'recipient_name' => 'Sara',
            'recipient_email' => 'sara@example.com',
            'recipient_phone' => '+971501234567',
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(GiftCard::class, $this->saved);
        self::assertSame('sara@example.com', $this->saved->getRecipientEmail());
        self::assertSame('+971501234567', $this->saved->getRecipientPhone());
        self::assertTrue($this->saved->needsEmailDelivery());
        self::assertTrue($this->saved->needsSmsDelivery());

        $body = json_decode((string) $response->getBody(), true);
        // Serializer masks the contact in the response.
        self::assertSame('s***@example.com', $body['data']['recipient_email']);
        self::assertStringEndsWith('67', (string) $body['data']['recipient_phone']);
        self::assertNull($body['data']['email_delivered_at']);
        self::assertNull($body['data']['sms_delivered_at']);
    }

    #[Test]
    public function contactIsOptionalBackwardCompatible(): void
    {
        $response = $this->invoke([
            'denomination' => '100.00',
            'theme' => 'eid',
        ]);

        self::assertSame(201, $response->getStatusCode());
        self::assertNull($this->saved->getRecipientEmail());
        self::assertNull($this->saved->getRecipientPhone());
        self::assertFalse($this->saved->needsEmailDelivery());
        self::assertFalse($this->saved->needsSmsDelivery());
    }

    #[Test]
    public function invalidEmailIsRejected(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('recipient_email');
        $this->invoke([
            'denomination' => '100.00',
            'theme' => 'eid',
            'recipient_email' => 'not-an-email',
        ]);
    }

    #[Test]
    public function invalidPhoneIsRejected(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('recipient_phone');
        $this->invoke([
            'denomination' => '100.00',
            'theme' => 'eid',
            'recipient_phone' => 'abc123',
        ]);
    }

    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function invoke(array $body): \Psr\Http\Message\ResponseInterface
    {
        $user = new User('buyer@example.com', '+971500000000', password_hash('p', PASSWORD_BCRYPT), 'AE');

        $repo = $this->createMock(GiftCardRepository::class);
        $repo->method('save')->willReturnCallback(function (GiftCard $card): void {
            $this->saved = $card;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $controller = new PurchaseGiftCardController(new ResponseFactory(), $em);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/v3/gift-cards/purchase')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user)
            ->withParsedBody($body);

        return $controller($request);
    }
}
