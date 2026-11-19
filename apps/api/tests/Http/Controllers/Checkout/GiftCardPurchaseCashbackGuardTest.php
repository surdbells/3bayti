<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Checkout;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Checkout\InitiateCheckoutController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cashback programme guard: cashback credit can't buy gift cards. A
 * gift-card purchase that names a cashback card (gift_card_code) or asks
 * for the wallet while it holds cashback credit is refused with a 422
 * CASHBACK_CREDIT_NOT_ALLOWED, before any order or gateway call.
 */
#[CoversClass(InitiateCheckoutController::class)]
final class GiftCardPurchaseCashbackGuardTest extends HttpTestCase
{
    #[Test]
    public function aCashbackCardCannotPayForAGiftCard(): void
    {
        $user = $this->makeUser(id: 7);
        $pending = $this->pendingCard($user);
        $cashback = $this->cashbackCard($user);

        $gcRepo = $this->createMock(GiftCardRepository::class);
        $gcRepo->method('find')->with(900)->willReturn($pending);
        $gcRepo->method('findByCode')->willReturn($cashback);

        $response = $this->initiate($user, $gcRepo, [
            'gift_card_purchase_id' => 900,
            'gift_card_code' => $cashback->formattedCode(),
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(ErrorCodes::CASHBACK_CREDIT_NOT_ALLOWED, $this->jsonBody($response)['error']['code']);
    }

    #[Test]
    public function aWalletHoldingCashbackCreditCannotPayForAGiftCard(): void
    {
        $user = $this->makeUser(id: 7);
        $pending = $this->pendingCard($user);

        $gcRepo = $this->createMock(GiftCardRepository::class);
        $gcRepo->method('find')->with(900)->willReturn($pending);
        $gcRepo->method('findSpendableByUser')->willReturn([$this->cashbackCard($user)]);

        $response = $this->initiate($user, $gcRepo, [
            'gift_card_purchase_id' => 900,
            'use_gift_wallet' => true,
        ]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(ErrorCodes::CASHBACK_CREDIT_NOT_ALLOWED, $this->jsonBody($response)['error']['code']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function initiate(User $user, GiftCardRepository $gcRepo, array $body): \Psr\Http\Message\ResponseInterface
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('initiateCheckout');

        $em = $this->stubEm(function ($em) use ($userRepo, $gcRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [GiftCard::class, $gcRepo],
            ]);
            $em->expects(self::never())->method('persist');
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $pair = $this->app->getContainer()->get(JwtService::class)->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', '/v3/checkout/initiate', $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function pendingCard(User $user): GiftCard
    {
        $card = new GiftCard(buyerUser: $user, denomination: '500.00', theme: 'birthday');
        $ref = new \ReflectionProperty(GiftCard::class, 'id');
        $ref->setValue($card, 900);
        return $card;
    }

    private function cashbackCard(User $user): GiftCard
    {
        $card = GiftCard::issueCashback($user, '25.00', new DateTimeImmutable('+1 year', new DateTimeZone('UTC')), 44, '3B-ORDER');
        $ref = new \ReflectionProperty(GiftCard::class, 'id');
        $ref->setValue($card, 901);
        return $card;
    }
}
