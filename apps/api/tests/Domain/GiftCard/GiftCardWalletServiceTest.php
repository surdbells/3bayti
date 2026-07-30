<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\GiftCard;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardWalletService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GiftCardWalletService::class)]
final class GiftCardWalletServiceTest extends TestCase
{
    private function svc(): GiftCardWalletService
    {
        return new GiftCardWalletService($this->createMock(EntityManagerInterface::class));
    }

    /** Build a card carrying an arbitrary balance (bypasses the ≥100 denom rule). */
    private function cardWithBalance(string $balance): GiftCard
    {
        $card = (new \ReflectionClass(GiftCard::class))->newInstanceWithoutConstructor();
        $rp = new \ReflectionProperty(GiftCard::class, 'balance');
        $rp->setAccessible(true);
        $rp->setValue($card, $balance);
        return $card;
    }

    #[Test]
    public function planDrawsAcrossCardsUpToOrderTotal(): void
    {
        $cards = [
            $this->cardWithBalance('150.00'),
            $this->cardWithBalance('100.00'),
            $this->cardWithBalance('80.00'),
        ];
        $plan = $this->svc()->planApply($cards, '200.00');

        // 150 from card 1 + 50 from card 2 = 200; card 3 untouched.
        self::assertCount(2, $plan);
        self::assertSame('150.00', $plan[0]['amount']);
        self::assertSame('50.00', $plan[1]['amount']);
        self::assertSame('200.00', GiftCardWalletService::planTotal($plan));
    }

    #[Test]
    public function planStopsAtWalletBalanceWhenItUnderCoversTheOrder(): void
    {
        $cards = [$this->cardWithBalance('150.00'), $this->cardWithBalance('30.00')];
        $plan  = $this->svc()->planApply($cards, '500.00');

        // Wallet only holds 180; the rest (320) is left for the gateway.
        self::assertCount(2, $plan);
        self::assertSame('180.00', GiftCardWalletService::planTotal($plan));
    }

    #[Test]
    public function exactCoverUsesEveryCardOnce(): void
    {
        $cards = [$this->cardWithBalance('100.00'), $this->cardWithBalance('100.00')];
        $plan  = $this->svc()->planApply($cards, '200.00');

        self::assertCount(2, $plan);
        self::assertSame('200.00', GiftCardWalletService::planTotal($plan));
    }

    #[Test]
    public function zeroOrEmptyCasesDrawNothing(): void
    {
        $cards = [$this->cardWithBalance('150.00')];
        self::assertSame([], $this->svc()->planApply($cards, '0.00'));
        self::assertSame([], $this->svc()->planApply([], '200.00'));
        self::assertSame('0.00', GiftCardWalletService::planTotal([]));
        self::assertSame('150.00', GiftCardWalletService::sumBalances($cards));
    }

    #[Test]
    public function skipsZeroBalanceCards(): void
    {
        $cards = [
            $this->cardWithBalance('0.00'),
            $this->cardWithBalance('120.00'),
        ];
        $plan = $this->svc()->planApply($cards, '100.00');

        self::assertCount(1, $plan);
        self::assertSame('100.00', $plan[0]['amount']);
        self::assertSame('120.00', $plan[0]['card']->getBalance());
    }
}
