<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Cashback;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Domain\Cashback\CashbackAwardRepository;
use Bayti\Api\Domain\Cashback\CashbackResult;
use Bayti\Api\Domain\Cashback\CashbackService;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderShipment;
use Bayti\Api\Domain\Order\OrderShipmentRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Cart\GetCartCashbackController;
use Bayti\Api\Http\Controllers\Me\GetMyCashbackController;
use Bayti\Api\Http\Controllers\Order\GetOrderController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(GetCartCashbackController::class)]
#[CoversClass(GetMyCashbackController::class)]
#[CoversClass(GetOrderController::class)]
final class CustomerCashbackControllersTest extends HttpTestCase
{
    #[Test]
    public function cartEstimateReportsAmountAndTheWalletHint(): void
    {
        $user = $this->makeUser(id: 7);
        $cart = $this->createMock(Cart::class);
        $cart->method('getItems')->willReturn(new ArrayCollection(['item']));
        $carts = $this->createMock(CartRepository::class);
        $carts->method('findActiveForUser')->willReturn($cart);
        $cards = $this->createMock(GiftCardRepository::class);
        $cards->method('findSpendableByUser')->willReturn([
            GiftCard::issueCashback($user, '20.00', new DateTimeImmutable('+1 year'), 1, '3B-X'),
        ]);

        $svc = $this->createMock(CashbackService::class);
        $svc->expects(self::once())->method('previewForCart')
            ->with($cart, $user, 'MOBILE', '0.00', false, false)
            ->willReturn(new CashbackResult('12.50', [], null, 14, 365));

        $this->bindEnv($user, [Cart::class => $carts, GiftCard::class => $cards]);
        $this->bind(CashbackService::class, $svc);

        $res = $this->get($user, '/v3/cart/cashback?channel=mobile');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame([
            'estimated_amount' => '12.50',
            'currency' => 'AED',
            'eligible' => true,
            'reason' => null,
            'hold_days' => 14,
            'uses_cashback_credit' => false,
            'wallet_has_cashback_credit' => true,
        ], $this->jsonBody($res)['data']);
    }

    #[Test]
    public function cartEstimateForAnEmptyCartIsIneligible(): void
    {
        $user = $this->makeUser(id: 7);
        $carts = $this->createMock(CartRepository::class);
        $carts->method('findActiveForUser')->willReturn(null);
        $this->bindEnv($user, [Cart::class => $carts]);

        $data = $this->jsonBody($this->get($user, '/v3/cart/cashback'))['data'];
        self::assertFalse($data['eligible']);
        self::assertSame('0.00', $data['estimated_amount']);
        self::assertSame(CashbackResult::REASON_EMPTY_CART, $data['reason']);
    }

    #[Test]
    public function cartEstimateRequiresAuth(): void
    {
        self::assertSame(401, $this->handle($this->jsonRequest('GET', '/v3/cart/cashback'))->getStatusCode());
    }

    #[Test]
    public function myCashbackListsAwardsWithTotals(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order($user, '3B-MINE', '100.00');
        $this->setId($order, 300);
        $award = new CashbackAward($order, $user, '5.00', 'AED', 14, 365);
        $this->setId($award, 40);

        $repo = $this->createMock(CashbackAwardRepository::class);
        $repo->method('findForUserPaginated')->with($user, 20, 0)->willReturn(['items' => [$award], 'total' => 1]);
        $repo->method('totalsByStatus')->with($user)->willReturn([
            'pending'   => ['count' => 1, 'amount' => '5.00', 'reversed' => '0.00'],
            'issued'    => ['count' => 2, 'amount' => '30.00', 'reversed' => '4.00'],
            'cancelled' => ['count' => 1, 'amount' => '7.00', 'reversed' => '0.00'],
            'reversed'  => ['count' => 1, 'amount' => '6.00', 'reversed' => '6.00'],
        ]);
        $this->bindEnv($user, [CashbackAward::class => $repo]);

        $res = $this->get($user, '/v3/me/cashback');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        self::assertSame(1, $body['meta']['total']);
        self::assertSame(40, $body['data'][0]['id']);
        self::assertSame('pending', $body['data'][0]['status']);
        self::assertSame('3B-MINE', $body['data'][0]['order_reference']);
        self::assertNull($body['data'][0]['release_at']);
        self::assertSame([
            'pending_amount' => '5.00',
            'earned_amount' => '26.00',
            'reversed_amount' => '10.00',
            'pending_count' => 1,
            'earned_count' => 2,
            'currency' => 'AED',
        ], $body['summary']);
    }

    #[Test]
    public function orderDetailCarriesTheCashbackField(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order($user, '3B-DETAIL', '100.00');
        $this->setId($order, 100);
        $orders = $this->createMock(OrderRepository::class);
        $orders->method('findForUser')->willReturn($order);
        $shipments = $this->createMock(OrderShipmentRepository::class);
        $shipments->method('findForOrder')->willReturn([]);

        $svc = $this->createMock(CashbackService::class);
        $svc->method('orderSummary')->willReturn([
            'status' => 'pending', 'amount' => '5.00', 'currency' => 'AED', 'release_at' => null, 'gift_card_id' => null,
        ]);
        $this->bindEnv($user, [Order::class => $orders, OrderShipment::class => $shipments, GiftCard::class => $this->createMock(GiftCardRepository::class)]);
        $this->bind(CashbackService::class, $svc);

        $res = $this->get($user, '/v3/orders/100');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('pending', $this->jsonBody($res)['order']['cashback']['status']);
    }

    #[Test]
    public function orderDetailCashbackIsNullWhenTheServiceHasNone(): void
    {
        $user = $this->makeUser(id: 7);
        $order = new Order($user, '3B-NONE', '100.00');
        $this->setId($order, 101);
        $orders = $this->createMock(OrderRepository::class);
        $orders->method('findForUser')->willReturn($order);
        $shipments = $this->createMock(OrderShipmentRepository::class);
        $shipments->method('findForOrder')->willReturn([]);
        // Real service against a stubbed EM: no cashback repo → null, no 500.
        $this->bindEnv($user, [Order::class => $orders, OrderShipment::class => $shipments, GiftCard::class => $this->createMock(GiftCardRepository::class)]);

        $res = $this->get($user, '/v3/orders/101');
        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        self::assertArrayHasKey('cashback', $this->jsonBody($res)['order']);
        self::assertNull($this->jsonBody($res)['order']['cashback']);
    }

    /** @param array<class-string, object> $repos */
    private function bindEnv(User $user, array $repos): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $map = [[User::class, $userRepo]];
        foreach ($repos as $class => $repo) {
            $map[] = [$class, $repo];
        }
        $em = $this->stubEm(static function ($em) use ($map): void {
            $em->method('getRepository')->willReturnMap($map);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function get(User $user, string $uri): ResponseInterface
    {
        $pair = $this->app->getContainer()->get(JwtService::class)->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], ['Authorization' => 'Bearer ' . $pair->accessToken]));
    }

    private function setId(object $o, int $id): void
    {
        (new \ReflectionProperty($o, 'id'))->setValue($o, $id);
    }
}
