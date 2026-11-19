<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Cashback;

use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Domain\Cashback\CashbackAwardRepository;
use Bayti\Api\Domain\Cashback\CashbackProgramSettings;
use Bayti\Api\Domain\Cashback\CashbackRule;
use Bayti\Api\Domain\Cashback\CashbackRuleRepository;
use Bayti\Api\Domain\Cashback\CashbackService;
use Bayti\Api\Domain\Cashback\CashbackSettingsStore;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestItem;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\CashbackNotifier;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CashbackService lifecycle against mocked repositories (no DB in CI):
 * idempotent accrual, release only after delivery + hold, no release while
 * a return is open, returned items + partial refunds reduce the amount,
 * cancel before release, reverse after release, double release = one card.
 */
#[CoversClass(CashbackService::class)]
#[CoversClass(CashbackAward::class)]
#[CoversClass(GiftCard::class)]
final class CashbackServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private CashbackAwardRepository&MockObject $awards;
    private CashbackRuleRepository&MockObject $rules;
    private OrderReturnRequestRepository&MockObject $returns;
    private PaymentTransactionRepository&MockObject $txns;
    private CashbackSettingsStore&MockObject $settings;
    private CashbackNotifier&MockObject $notifier;

    /** @var list<object> */
    private array $persisted = [];
    private User $user;

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->user = new User('cb@example.com', '+971500000001', 'x', 'AE');
        $this->setProp($this->user, 'id', 5);

        $this->awards = $this->createMock(CashbackAwardRepository::class);
        $this->rules = $this->createMock(CashbackRuleRepository::class);
        $this->returns = $this->createMock(OrderReturnRequestRepository::class);
        $this->txns = $this->createMock(PaymentTransactionRepository::class);
        $this->txns->method('sumRefundsForOrder')->willReturn('0.00');
        $this->returns->method('findAllByOrder')->willReturn([]);
        $this->settings = $this->createMock(CashbackSettingsStore::class);
        $this->settings->method('load')->willReturn(CashbackProgramSettings::fromArray(['enabled' => true, 'min_award' => '1.00']));
        $this->notifier = $this->createMock(CashbackNotifier::class);

        $conn = $this->createMock(Connection::class);
        $conn->method('isTransactionActive')->willReturn(true);

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getConnection')->willReturn($conn);
        $this->em->method('getRepository')->willReturnMap([
            [CashbackAward::class, $this->awards],
            [CashbackRule::class, $this->rules],
            [OrderReturnRequest::class, $this->returns],
            [PaymentTransaction::class, $this->txns],
        ]);
        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
    }

    // ── accrual ──────────────────────────────────────────────────────

    #[Test]
    public function accrualCreatesAPendingAwardAndIsIdempotent(): void
    {
        $order = $this->paidOrder();
        $rule = $this->rule(1, '5.00');
        $this->rules->method('findLive')->willReturn([$rule]);
        $this->rules->method('spentByRuleIds')->willReturn([]);
        $this->awards->method('customerAwardCounts')->willReturn([]);
        $this->awards->method('countEarlierPaidProductOrders')->willReturn(3);
        $this->awards->method('findOneByOrder')->willReturnCallback(function (): ?CashbackAward {
            foreach ($this->persisted as $o) {
                if ($o instanceof CashbackAward) {
                    return $o;
                }
            }
            return null;
        });

        $svc = $this->service();
        $award = $svc->accrueForPaidOrder($order);

        self::assertNotNull($award);
        self::assertSame(CashbackAward::STATUS_PENDING, $award->getStatus());
        self::assertSame('10.00', $award->getAmount());
        self::assertCount(2, $award->getLines());
        self::assertSame(14, $award->getHoldDays());

        $again = $svc->accrueForPaidOrder($order);
        self::assertSame($award, $again);
        self::assertCount(1, array_filter($this->persisted, static fn (object $o): bool => $o instanceof CashbackAward));
    }

    #[Test]
    public function accrualDoesNothingWhenTheProgrammeIsOff(): void
    {
        $settings = $this->createMock(CashbackSettingsStore::class);
        $settings->method('load')->willReturn(CashbackProgramSettings::default());
        $this->rules->expects(self::never())->method('findLive');

        $svc = new CashbackService($this->em, $settings, $this->notifier);
        self::assertNull($svc->accrueForPaidOrder($this->paidOrder()));
        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function giftCardPurchaseAndCustomizationOrdersNeverAccrue(): void
    {
        $synthetic = new Order($this->user, '3B-GC-1', '500.00');
        $this->setProp($synthetic, 'id', 78);
        $synthetic->markPaid();
        $this->rules->expects(self::never())->method('findLive');

        self::assertNull($this->service()->accrueForPaidOrder($synthetic));
    }

    #[Test]
    public function anOrderPaidWithCashbackCreditEarnsNothing(): void
    {
        $order = $this->paidOrder();
        $order->markUsedCashbackCredit();
        $this->rules->method('findLive')->willReturn([$this->rule(1, '5.00')]);
        $this->rules->method('spentByRuleIds')->willReturn([]);
        $this->awards->method('findOneByOrder')->willReturn(null);
        $this->awards->method('customerAwardCounts')->willReturn([]);

        self::assertNull($this->service()->accrueForPaidOrder($order));
        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function accrualNeverThrows(): void
    {
        $this->awards->method('findOneByOrder')->willThrowException(new \RuntimeException('db down'));
        self::assertNull($this->service()->accrueForPaidOrder($this->paidOrder()));
    }

    // ── release ──────────────────────────────────────────────────────

    #[Test]
    public function releaseWaitsForDeliveryPlusHold(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->awards->method('lockById')->willReturn($award);
        $now = $this->now();

        // Not delivered yet.
        self::assertSame(CashbackService::OUTCOME_NOT_DUE, $this->service()->releaseAwardById(900, $now));

        // Delivered 5 days ago, hold is 14.
        $this->deliver($order, $now->modify('-5 days'));
        self::assertSame(CashbackService::OUTCOME_NOT_DUE, $this->service()->releaseAwardById(900, $now));
        self::assertTrue($award->isPending());

        // Delivered 15 days ago: issue.
        $this->deliver($order, $now->modify('-15 days'));
        $this->notifier->expects(self::once())->method('cashbackIssued');
        self::assertSame(CashbackService::OUTCOME_ISSUED, $this->service()->releaseAwardById(900, $now));

        self::assertTrue($award->isIssued());
        $card = $award->getGiftCard();
        self::assertNotNull($card);
        self::assertSame(GiftCard::SOURCE_CASHBACK, $card->getSource());
        self::assertSame('10.00', $card->getBalance());
        self::assertSame(GiftCard::STATUS_ACTIVE, $card->getStatus());
        self::assertSame(5, $card->getRecipientUser()?->getId());
        self::assertTrue($card->isSpendableBy($this->user));
        self::assertSame(900, $card->getCashbackAwardId());
    }

    #[Test]
    public function noReleaseWhileAReturnIsOpen(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->deliver($order, $this->now()->modify('-30 days'));
        $this->awards->method('lockById')->willReturn($award);

        $rr = new OrderReturnRequest($order, $this->user, OrderReturnRequest::REASON_DEFECTIVE);
        $returns = $this->createMock(OrderReturnRequestRepository::class);
        $returns->method('findAllByOrder')->willReturn([$rr]);

        self::assertSame(CashbackService::OUTCOME_HELD_RETURN, $this->service($returns)->releaseAwardById(900, $this->now()));
        self::assertTrue($award->isPending());
    }

    #[Test]
    public function returnedItemsAndPartialRefundsReduceTheReleasedAmount(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->deliver($order, $this->now()->modify('-30 days'));
        $this->awards->method('lockById')->willReturn($award);

        // Item 1 came back to the vendor through a return.
        $rr = new OrderReturnRequest($order, $this->user, OrderReturnRequest::REASON_DEFECTIVE);
        $items = $order->getItems()->toArray();
        $rr->addItem(new OrderReturnRequestItem($items[0], 1));
        $this->setProp($rr, 'status', OrderReturnRequest::STATUS_REFUNDED);
        $returns = $this->createMock(OrderReturnRequestRepository::class);
        $returns->method('findAllByOrder')->willReturn([$rr]);

        // And 50 of the 200 order total was refunded to the card.
        $txns = $this->createMock(PaymentTransactionRepository::class);
        $txns->method('sumRefundsForOrder')->willReturn('50.00');

        self::assertSame(CashbackService::OUTCOME_ISSUED, $this->service($returns, $txns)->releaseAwardById(900, $this->now()));
        // 10.00 - 5.00 (returned item) = 5.00, then x 0.75 (25% refunded)
        self::assertSame('3.75', $award->getAmount());
        self::assertSame('10.00', $award->getAccruedAmount());
        self::assertSame('3.75', $award->getGiftCard()?->getBalance());
        $lines = $award->getLines()->toArray();
        self::assertSame('0.00', $lines[0]->getAmount());
        self::assertSame('3.75', $lines[1]->getAmount());
    }

    #[Test]
    public function aDeadOrderCancelsTheAwardAtRelease(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $order->overrideStatus(Order::STATUS_REFUNDED);
        $this->awards->method('lockById')->willReturn($award);

        self::assertSame(CashbackService::OUTCOME_CANCELLED, $this->service()->releaseAwardById(900, $this->now()));
        self::assertSame(CashbackAward::STATUS_CANCELLED, $award->getStatus());
        self::assertSame('order_refunded', $award->getStatusReason());
    }

    #[Test]
    public function releaseRechecksTheLedgerForCashbackCredit(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->deliver($order, $this->now()->modify('-30 days'));
        $order->applyGiftCard('20.00', 'ABCDABCDABCDABCD');
        $this->awards->method('lockById')->willReturn($award);
        $this->awards->method('orderSpentCashbackCredit')->willReturn(true);

        self::assertSame(CashbackService::OUTCOME_CANCELLED, $this->service()->releaseAwardById(900, $this->now()));
        self::assertSame('paid_with_cashback', $award->getStatusReason());
    }

    #[Test]
    public function aDoubleReleaseIssuesOneCard(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->deliver($order, $this->now()->modify('-30 days'));
        $this->awards->method('lockById')->willReturn($award);
        $this->notifier->expects(self::once())->method('cashbackIssued');

        $svc = $this->service();
        self::assertSame(CashbackService::OUTCOME_ISSUED, $svc->releaseAwardById(900, $this->now()));
        // A racing cron / admin "release now" re-reads the locked row: issued.
        self::assertSame(CashbackService::OUTCOME_NOT_PENDING, $svc->releaseAwardById(900, $this->now(), true));
        self::assertCount(1, array_filter($this->persisted, static fn (object $o): bool => $o instanceof GiftCard));
    }

    #[Test]
    public function dryRunChangesNothing(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->deliver($order, $this->now()->modify('-30 days'));
        $this->awards->method('lockById')->willReturn($award);

        self::assertSame(CashbackService::OUTCOME_WOULD_ISSUE, $this->service()->releaseAwardById(900, $this->now(), false, true));
        self::assertTrue($award->isPending());
        self::assertSame([], $this->persisted);
    }

    // ── cancel / reverse ─────────────────────────────────────────────

    #[Test]
    public function cancellingBeforeReleaseCancelsThePendingAward(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->awards->method('findOneByOrder')->willReturn($award);
        $this->awards->method('lockById')->willReturn($award);

        $this->service()->cancelForOrder($order, 'Order cancelled: customer request');

        self::assertSame(CashbackAward::STATUS_CANCELLED, $award->getStatus());
        self::assertNull($award->getGiftCard());
    }

    #[Test]
    public function reversingAfterReleaseClawsBackThroughTheLedger(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $card = GiftCard::issueCashback($this->user, '10.00', $this->now()->modify('+365 days'), 900, '3B-TEST1');
        $award->markIssued($card, $this->now());
        $this->awards->method('findOneByOrder')->willReturn($award);
        $this->awards->method('lockById')->willReturn($award);

        // The customer already spent 7.00 of it.
        $card->debit('7.00', '3B-OTHER', 999);

        $this->service()->cancelForOrder($order, 'Order refunded');

        self::assertSame(CashbackAward::STATUS_REVERSED, $award->getStatus());
        self::assertSame('0.00', $card->getBalance());
        $reversals = $award->getSnapshot()['reversals'] ?? [];
        self::assertIsArray($reversals);
        self::assertSame('3.00', $reversals[0]['collected']);
        self::assertSame('7.00', $reversals[0]['written_off']);
        $last = $card->getTransactions()->last();
        self::assertNotFalse($last);
        self::assertSame('debit', $last->getType());
        self::assertSame('3.00', $last->getAmount());
    }

    #[Test]
    public function aReturnClawsBackOnlyWhatTheReturnedItemsEarned(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $card = GiftCard::issueCashback($this->user, '10.00', $this->now()->modify('+365 days'), 900, '3B-TEST1');
        $award->markIssued($card, $this->now());
        $this->awards->method('findOneByOrder')->willReturn($award);
        $this->awards->method('lockById')->willReturn($award);

        $rr = new OrderReturnRequest($order, $this->user, OrderReturnRequest::REASON_DEFECTIVE);
        $rr->addItem(new OrderReturnRequestItem($order->getItems()->toArray()[0], 1));

        $this->service()->reverseForReturn($rr, 'Return refunded');

        self::assertSame(CashbackAward::STATUS_ISSUED, $award->getStatus());
        self::assertSame('5.00', $award->getReversedAmount());
        self::assertSame('5.00', $card->getBalance());
    }

    #[Test]
    public function onlyAnIssuedAwardCanBeReversed(): void
    {
        $award = $this->pendingAward($this->paidOrder());
        $this->awards->method('lockById')->willReturn($award);
        $this->expectException(\DomainException::class);
        $this->service()->reverseAward($award, 'nope');
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function service(
        ?OrderReturnRequestRepository $returns = null,
        ?PaymentTransactionRepository $txns = null,
    ): CashbackService {
        $em = $this->em;
        if ($returns !== null || $txns !== null) {
            $em = $this->createMock(EntityManagerInterface::class);
            $em->method('getConnection')->willReturn($this->em->getConnection());
            $em->method('getRepository')->willReturnMap([
                [CashbackAward::class, $this->awards],
                [CashbackRule::class, $this->rules],
                [OrderReturnRequest::class, $returns ?? $this->returns],
                [PaymentTransaction::class, $txns ?? $this->txns],
            ]);
            $em->method('persist')->willReturnCallback(function (object $o): void {
                $this->persisted[] = $o;
            });
        }
        return new CashbackService($em, $this->settings, $this->notifier);
    }

    private function paidOrder(): Order
    {
        $order = new Order($this->user, '3B-TEST1', '0.00', '10.00', '0.00', 'AED', 'WEB');
        $this->setProp($order, 'id', 77);
        foreach ([[1, 10, 501], [2, 20, 502]] as [$itemId, $vendorId, $productId]) {
            $vendor = new Vendor("vendor-{$vendorId}", "Vendor {$vendorId}", "v{$vendorId}@example.test");
            $this->setProp($vendor, 'id', $vendorId);
            $product = new Product(vendor: $vendor, slug: "p-{$productId}", name: "P {$productId}");
            $this->setProp($product, 'id', $productId);
            $item = new OrderItem($product, $vendor, 1, '100.00', "P {$productId}");
            $this->setProp($item, 'id', $itemId);
            $order->addItem($item);
        }
        // total = 200 + 10 delivery; drop delivery for round numbers in the refund-share test
        $order->setDeliveryFee('0.00');
        $order->markPaid();
        return $order;
    }

    private function pendingAward(Order $order): CashbackAward
    {
        $rule = $this->rule(1, '5.00');
        $award = new CashbackAward($order, $this->user, '10.00', 'AED', 14, 365);
        $award->addLine($rule, 1, '100.00', '5.00');
        $award->addLine($rule, 2, '100.00', '5.00');
        $this->setProp($award, 'id', 900);
        return $award;
    }

    private function deliver(Order $order, DateTimeImmutable $at): void
    {
        $this->setProp($order, 'status', Order::STATUS_DELIVERED);
        $this->setProp($order, 'deliveredAt', $at);
    }

    private function rule(int $id, string $pct): CashbackRule
    {
        $rule = new CashbackRule('Rule ' . $id, CashbackRule::REWARD_PERCENTAGE, $pct);
        $rule->setActive(true);
        $this->setProp($rule, 'id', $id);
        return $rule;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function setProp(object $o, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($o, $prop);
        $ref->setValue($o, $value);
    }
}
