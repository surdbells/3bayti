<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\GiftCard;

use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Domain\Cashback\CashbackRule;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardOrderReversal;
use Bayti\Api\Domain\GiftCard\GiftCardTransaction;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(GiftCardOrderReversal::class)]
#[CoversClass(GiftCard::class)]
#[CoversClass(CashbackAward::class)]
final class GiftCardOrderReversalTest extends TestCase
{
    // ── plan(): how a restore is split across cards ─────────────────────

    #[Test]
    public function fullRestoreGivesEveryCardBackWhatItIsOwed(): void
    {
        $plan = GiftCardOrderReversal::plan([7 => '60.00', 3 => '40.00'], null);
        self::assertSame([7 => '60.00', 3 => '40.00'], $plan);
    }

    #[Test]
    public function partialRestoreStopsAtTheLimitInCardOrder(): void
    {
        $plan = GiftCardOrderReversal::plan([7 => '60.00', 3 => '40.00'], '75.00');
        self::assertSame([7 => '60.00', 3 => '15.00'], $plan);
    }

    #[Test]
    public function restoreNeverExceedsWhatTheOrderStillHolds(): void
    {
        // Limit larger than what is owed: only the owed amount comes back.
        $plan = GiftCardOrderReversal::plan([7 => '25.50'], '500.00');
        self::assertSame([7 => '25.50'], $plan);
    }

    #[Test]
    public function nothingOwedOrZeroLimitRestoresNothing(): void
    {
        self::assertSame([], GiftCardOrderReversal::plan([], null));
        self::assertSame([], GiftCardOrderReversal::plan([7 => '0.00'], null));
        self::assertSame([], GiftCardOrderReversal::plan([7 => '10.00'], '0.00'));
    }

    // ── restoreForOrder(): orders that used no gift card ────────────────

    #[Test]
    public function orderWithoutGiftCardNeverTouchesTheDatabase(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getConnection');
        $em->expects(self::never())->method('flush');

        $svc   = new GiftCardOrderReversal($em, new NullLogger());
        $order = new Order(user: $this->user(), orderReference: '3B-NOGC', subtotal: '99.00');

        self::assertSame('0.00', $svc->restoreForOrder($order, 'Order cancelled'));
        self::assertSame('0.00', $svc->outstandingForOrder($order));
        self::assertFalse($svc->hasRestoredForOrder($order));
    }

    // ── GiftCard::restoreOrderDebit() ───────────────────────────────────

    #[Test]
    public function restoreCreditsTheBalanceAndWritesAnOrderTaggedLedgerRow(): void
    {
        $card = $this->card(denomination: '200.00', balance: '50.00', status: GiftCard::STATUS_PARTIALLY_USED);

        $tx = $card->restoreOrderDebit('100.00', '3B-ORDER-1', 55, 'Order cancelled: changed mind');

        self::assertSame('150.00', $card->getBalance());
        self::assertSame(GiftCard::STATUS_PARTIALLY_USED, $card->getStatus());
        self::assertSame(GiftCardTransaction::TYPE_CREDIT, $tx->getType());
        self::assertSame('100.00', $tx->getAmount());
        self::assertSame('150.00', $tx->getBalanceAfter());
        self::assertSame('3B-ORDER-1', $tx->getOrderReference());
        self::assertSame(55, $tx->getOrderId());
        self::assertSame('Order cancelled: changed mind', $tx->getReason());
    }

    #[Test]
    public function exhaustedCardBackToFullBalanceIsActiveAgain(): void
    {
        $card = $this->card(denomination: '100.00', balance: '0.00', status: GiftCard::STATUS_EXHAUSTED);

        $card->restoreOrderDebit('100.00', '3B-ORDER-2', 56, 'Payment failed');

        self::assertSame('100.00', $card->getBalance());
        self::assertSame(GiftCard::STATUS_ACTIVE, $card->getStatus());
        self::assertTrue($card->isSpendable());
    }

    #[Test]
    public function cardThatExpiredWhileTheOrderWasOpenGetsAThirtyDayGraceWindow(): void
    {
        $past = new \DateTimeImmutable('-2 days', new \DateTimeZone('UTC'));
        $card = $this->card(denomination: '100.00', balance: '0.00', status: GiftCard::STATUS_EXPIRED, expiresAt: $past);

        $card->restoreOrderDebit('100.00', '3B-ORDER-3', 57, 'Order cancelled');

        self::assertTrue($card->isSpendable());
        $expires = $card->getExpiresAt();
        self::assertNotNull($expires);
        self::assertGreaterThan(new \DateTimeImmutable('+29 days'), $expires);
        self::assertLessThan(new \DateTimeImmutable('+31 days'), $expires);
    }

    #[Test]
    public function unexpiredCardKeepsItsExpiry(): void
    {
        $future = new \DateTimeImmutable('+90 days', new \DateTimeZone('UTC'));
        $card = $this->card(denomination: '100.00', balance: '20.00', status: GiftCard::STATUS_PARTIALLY_USED, expiresAt: $future);

        $card->restoreOrderDebit('30.00', '3B-ORDER-4', 58, 'Refund');

        self::assertEquals($future, $card->getExpiresAt());
    }

    #[Test]
    public function voidedCardIsNeverRevived(): void
    {
        $card = $this->card(denomination: '100.00', balance: '0.00', status: GiftCard::STATUS_VOIDED);

        $this->expectException(\LogicException::class);
        $card->restoreOrderDebit('40.00', '3B-ORDER-5', 59, 'Order cancelled');
    }

    #[Test]
    public function restoreAmountMustBePositive(): void
    {
        $card = $this->card(denomination: '100.00', balance: '0.00', status: GiftCard::STATUS_EXHAUSTED);

        $this->expectException(\InvalidArgumentException::class);
        $card->restoreOrderDebit('0.00', '3B-ORDER-6', 60, 'Order cancelled');
    }

    // ── cashback clawback recovery ──────────────────────────────────────

    #[Test]
    public function aFullyClawedBackCashbackSpendDoesNotComeBackWhenItsOrderIsCancelled(): void
    {
        // Cashback card of 50; 30 spent on order Z; the award is then fully
        // reversed: 20 collected, 30 written off. Z is cancelled.
        [$card, $award, $order] = $this->clawedBackCashback(reverse: null);
        self::assertSame('0.00', $card->getBalance());
        self::assertSame('30.00', $award->getUncollectedAmount());

        $restored = $this->reversal($card, $award)->restoreForOrder($order, 'Order cancelled');

        self::assertSame('30.00', $restored);
        // Credited back to the card, then debited straight back.
        self::assertSame('0.00', $card->getBalance());
        self::assertSame('0.00', $award->getUncollectedAmount());
        $last = $card->getTransactions()->last();
        self::assertNotFalse($last);
        self::assertSame(GiftCardTransaction::TYPE_DEBIT, $last->getType());
        self::assertSame('30.00', $last->getAmount());
    }

    #[Test]
    public function aPartlyClawedBackCashbackKeepsOnlyWhatTheCustomerIsStillOwed(): void
    {
        // Card of 50, 30 spent on Z, then 25 of the award clawed back:
        // 20 collected, 5 written off. Z cancelled: the 30 comes back, 5 is
        // recovered, the customer keeps the 25 they still earned.
        [$card, $award, $order] = $this->clawedBackCashback(reverse: '25.00');
        self::assertSame('5.00', $award->getUncollectedAmount());

        $this->reversal($card, $award)->restoreForOrder($order, 'Order cancelled');

        self::assertSame('25.00', $card->getBalance());
        self::assertSame('0.00', $award->getUncollectedAmount());
        $recoveries = $award->getSnapshot()['recoveries'] ?? [];
        self::assertIsArray($recoveries);
        self::assertSame('5.00', $recoveries[0]['amount']);
    }

    #[Test]
    public function aCashbackCardWithNothingUncollectedIsRestoredNormally(): void
    {
        [$card, $award, $order] = $this->clawedBackCashback(reverse: false);
        self::assertSame('0.00', $award->getUncollectedAmount());

        $this->reversal($card, $award)->restoreForOrder($order, 'Payment failed');

        self::assertSame('50.00', $card->getBalance());
    }

    // ── builders ────────────────────────────────────────────────────────

    /**
     * A 50.00 cashback card, 30.00 of it spent on order 3B-Z (id 55), then
     * the award reversed: null = in full, a string = that amount, false = not.
     *
     * @return array{GiftCard, CashbackAward, Order}
     */
    private function clawedBackCashback(string|false|null $reverse): array
    {
        $user = $this->user();
        $earnedOn = new Order(user: $user, orderReference: '3B-Y', subtotal: '500.00');
        $this->set($earnedOn, 'id', 54);
        $rule = new CashbackRule('R', CashbackRule::REWARD_PERCENTAGE, '10.00');
        $award = new CashbackAward($earnedOn, $user, '50.00', 'AED', 0, 365);
        $award->addLine($rule, null, '500.00', '50.00');
        $this->set($award, 'id', 900);

        $card = GiftCard::issueCashback($user, '50.00', new \DateTimeImmutable('+365 days'), 900, '3B-Y');
        $this->set($card, 'id', 11);
        $award->markIssued($card, new \DateTimeImmutable());

        $order = new Order(user: $user, orderReference: '3B-Z', subtotal: '30.00');
        $this->set($order, 'id', 55);
        $order->applyGiftCard('30.00', $card->getCode());
        $card->debit('30.00', '3B-Z', 55);

        if ($reverse !== false) {
            $value = $reverse ?? $award->remainingAmount();
            $collected = bccomp($card->getBalance(), $value, 2) < 0 ? $card->getBalance() : $value;
            $card->adminDebit($collected, 'Cashback reversed');
            $award->recordReversal($value, $collected, 'Order Y refunded', $reverse === null);
        }
        return [$card, $award, $order];
    }

    private function reversal(GiftCard $card, CashbackAward $award): GiftCardOrderReversal
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchFirstColumn')->willReturn([11]);
        $conn->method('fetchAllAssociative')->willReturn([
            ['gift_card_id' => 11, 'held' => '30.00', 'last_debit' => 7],
        ]);
        $conn->method('isTransactionActive')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?object => match ($class) {
                GiftCard::class       => $card,
                CashbackAward::class  => $award,
                default               => null,
            },
        );
        return new GiftCardOrderReversal($em, new NullLogger());
    }

    private function card(
        string $denomination,
        string $balance,
        string $status,
        ?\DateTimeImmutable $expiresAt = null,
    ): GiftCard {
        $card = (new \ReflectionClass(GiftCard::class))->newInstanceWithoutConstructor();
        $this->set($card, 'denomination', $denomination);
        $this->set($card, 'balance', $balance);
        $this->set($card, 'status', $status);
        $this->set($card, 'expiresAt', $expiresAt);
        $this->set($card, 'transactions', new ArrayCollection());
        return $card;
    }

    private function user(): User
    {
        $user = new User('gc@example.com', '+971501234567', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $this->set($user, 'id', 9);
        return $user;
    }

    private function set(object $obj, string $prop, mixed $value): void
    {
        $rp = new \ReflectionProperty($obj::class, $prop);
        $rp->setAccessible(true);
        $rp->setValue($obj, $value);
    }
}
