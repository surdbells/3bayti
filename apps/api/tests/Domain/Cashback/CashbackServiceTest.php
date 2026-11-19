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
        $this->awards->method('countOtherPaidProductOrders')->willReturn(3);
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

        // 150 of the 200 order total was refunded to the card: 100 of it is
        // the returned item's own refund, the other 50 is a partial refund
        // on the item the customer kept.
        $txns = $this->createMock(PaymentTransactionRepository::class);
        $txns->method('sumRefundsForOrder')->willReturn('150.00');

        self::assertSame(CashbackService::OUTCOME_ISSUED, $this->service($returns, $txns)->releaseAwardById(900, $this->now()));
        // 10.00 - 5.00 (returned item) = 5.00, then x (1 - 50/100): only the
        // refund the returned item does not already explain scales the rest.
        self::assertSame('2.50', $award->getAmount());
        self::assertSame('10.00', $award->getAccruedAmount());
        self::assertSame('2.50', $award->getGiftCard()?->getBalance());
        $lines = $award->getLines()->toArray();
        self::assertSame('0.00', $lines[0]->getAmount());
        self::assertSame('2.50', $lines[1]->getAmount());
        self::assertSame('5.00', $lines[0]->getAccruedAmount());
    }

    #[Test]
    public function refundingADroppedItemDoesNotReduceTheAwardTwice(): void
    {
        // Item 2 rejected and its 100 refunded; item 1 delivered. The order
        // rollup stays FULFILLING (a rejected item never rolls up to
        // delivered), but fulfilment is finished: the award is due.
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->awards->method('lockById')->willReturn($award);
        $this->settleItems($order, [OrderItem::ITEM_STATUS_DELIVERED, OrderItem::ITEM_STATUS_REJECTED], $this->now()->modify('-20 days'));
        self::assertSame(Order::STATUS_FULFILLING, $order->getStatus());

        $txns = $this->createMock(PaymentTransactionRepository::class);
        $txns->method('sumRefundsForOrder')->willReturn('100.00');

        self::assertSame(CashbackService::OUTCOME_ISSUED, $this->service(null, $txns)->releaseAwardById(900, $this->now()));
        // Item 1's 5.00, NOT 5.00 x 0.5.
        self::assertSame('5.00', $award->getAmount());
    }

    #[Test]
    public function anOrderWithARejectedItemIsDueOnceEveryItemIsSettled(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->awards->method('lockById')->willReturn($award);

        // Item 1 still on its way: not due.
        $this->settleItems($order, [OrderItem::ITEM_STATUS_SHIPPED, OrderItem::ITEM_STATUS_REJECTED], $this->now()->modify('-20 days'));
        self::assertSame(CashbackService::OUTCOME_NOT_DUE, $this->service()->releaseAwardById(900, $this->now()));
        self::assertNull($award->expectedReleaseAt());

        // Delivered 5 days ago (hold 14): not due yet, but the release date is known.
        $this->settleItems($order, [OrderItem::ITEM_STATUS_DELIVERED, OrderItem::ITEM_STATUS_REJECTED], $this->now()->modify('-5 days'));
        self::assertSame(CashbackService::OUTCOME_NOT_DUE, $this->service()->releaseAwardById(900, $this->now()));
        self::assertNotNull($award->expectedReleaseAt());

        // 20 days ago: released, without the rejected item's line.
        $this->settleItems($order, [OrderItem::ITEM_STATUS_DELIVERED, OrderItem::ITEM_STATUS_REJECTED], $this->now()->modify('-20 days'));
        self::assertSame(CashbackService::OUTCOME_ISSUED, $this->service()->releaseAwardById(900, $this->now()));
        self::assertSame('5.00', $award->getAmount());
    }

    #[Test]
    public function anAdminOverrideToDeliveredStartsTheHold(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->awards->method('lockById')->willReturn($award);

        $order->overrideStatus(Order::STATUS_DELIVERED);
        $deliveredAt = $order->getDeliveredAt();
        self::assertNotNull($deliveredAt);
        self::assertNotNull($award->expectedReleaseAt());
        self::assertSame(CashbackService::OUTCOME_NOT_DUE, $this->service()->releaseAwardById(900, $this->now()));
        self::assertSame(
            CashbackService::OUTCOME_ISSUED,
            $this->service()->releaseAwardById(900, $deliveredAt->modify('+15 days')),
        );
    }

    #[Test]
    public function releaseCapsAnAwardAtTheLargestGiftCard(): void
    {
        $order = $this->paidOrder();
        $rule = $this->rule(1, '5.00');
        $award = new CashbackAward($order, $this->user, '15000.00', 'AED', 14, 365);
        $award->addLine($rule, 1, '100.00', '7500.00');
        $award->addLine($rule, 2, '100.00', '7500.00');
        $this->setProp($award, 'id', 900);
        $this->deliver($order, $this->now()->modify('-30 days'));
        $this->awards->method('lockById')->willReturn($award);

        self::assertSame(CashbackService::OUTCOME_ISSUED, $this->service()->releaseAwardById(900, $this->now()));
        self::assertSame(GiftCard::MAX_DENOMINATION, $award->getAmount());
        self::assertSame(GiftCard::MAX_DENOMINATION, $award->getGiftCard()?->getBalance());
    }

    #[Test]
    public function aFailedReleaseClearsTheUnitOfWorkBeforeTheNextAward(): void
    {
        $this->awards->method('findDueIds')->willReturn([901, 902]);
        $this->awards->method('lockById')->willThrowException(new \RuntimeException('boom'));
        $this->em->method('isOpen')->willReturn(true);
        $this->em->expects(self::exactly(2))->method('clear');

        $stats = $this->service()->releaseDue($this->now());
        self::assertSame(2, $stats['errors']);
    }

    #[Test]
    public function aClosedEntityManagerStopsTheReleaseBatch(): void
    {
        $this->awards->method('findDueIds')->willReturn([901, 902, 903]);
        $this->awards->expects(self::once())->method('lockById')->willThrowException(new \RuntimeException('flush failed'));
        $this->em->method('isOpen')->willReturn(false);
        $this->em->expects(self::never())->method('clear');

        $stats = $this->service()->releaseDue($this->now());
        self::assertSame(3, $stats['errors']);
        self::assertSame(3, $stats['due']);
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
    public function aCancelRacingAReleaseClawsBackInsteadOfLeavingTheCardIssued(): void
    {
        // cancelForOrder read the award as pending, but a release committed
        // before it got the row lock: the locked re-read sees `issued`.
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $card = GiftCard::issueCashback($this->user, '10.00', $this->now()->modify('+365 days'), 900, '3B-TEST1');
        $this->awards->method('findOneByOrder')->willReturn($award);
        $this->awards->method('lockById')->willReturnCallback(function () use ($award, $card): CashbackAward {
            if ($award->isPending()) {
                $award->markIssued($card, $this->now());
            }
            return $award;
        });

        $this->service()->cancelForOrder($order, 'Order cancelled');

        self::assertSame(CashbackAward::STATUS_REVERSED, $award->getStatus());
        self::assertSame('0.00', $card->getBalance());
    }

    #[Test]
    public function aPartialRefundAfterReleaseClawsBackTheRefundedShare(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $card = GiftCard::issueCashback($this->user, '10.00', $this->now()->modify('+365 days'), 900, '3B-TEST1');
        $award->markIssued($card, $this->now());
        $this->awards->method('findOneByOrder')->willReturn($award);
        $this->awards->method('lockById')->willReturn($award);

        // 100 of the 200 order refunded after the card was issued.
        $txns = $this->createMock(PaymentTransactionRepository::class);
        $txns->method('sumRefundsForOrder')->willReturn('100.00');
        $svc = $this->service(null, $txns);

        $svc->settleIssuedForOrder($order, 'Partial refund: damaged');
        self::assertSame(CashbackAward::STATUS_ISSUED, $award->getStatus());
        self::assertSame('5.00', $award->getReversedAmount());
        self::assertSame('5.00', $card->getBalance());

        // Idempotent: nothing more to claw back for the same refunds.
        $svc->settleIssuedForOrder($order, 'Partial refund: damaged');
        self::assertSame('5.00', $award->getReversedAmount());
    }

    #[Test]
    public function aReversalRecordsWhatTheCustomerHadAlreadySpent(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $card = GiftCard::issueCashback($this->user, '10.00', $this->now()->modify('+365 days'), 900, '3B-TEST1');
        $award->markIssued($card, $this->now());
        $this->awards->method('lockById')->willReturn($award);
        $card->debit('7.00', '3B-OTHER', 999);

        $out = $this->service()->reverseAward($award, 'Order refunded');

        self::assertSame(['value' => '10.00', 'collected' => '3.00'], $out);
        self::assertSame('7.00', $award->getUncollectedAmount());
    }

    #[Test]
    public function onlyAnIssuedAwardCanBeReversed(): void
    {
        $award = $this->pendingAward($this->paidOrder());
        $this->awards->method('lockById')->willReturn($award);
        $this->expectException(\DomainException::class);
        $this->service()->reverseAward($award, 'nope');
    }

    // ── read-only estimates ──────────────────────────────────────────

    #[Test]
    public function productBadgeShowsTheBestCoveringRuleAndSkipsSpentBudgets(): void
    {
        $vendor = new Vendor('vendor-10', 'Vendor 10', 'v10@example.test');
        $this->setProp($vendor, 'id', 10);
        $product = new Product(vendor: $vendor, slug: 'p-1', name: 'P 1');
        $this->setProp($product, 'id', 1);
        $this->setProp($product, 'price', '200.00');

        $spent = $this->rule(1, '20.00');
        $spent->setPriority(5);
        $spent->setBudgetTotal('100.00');
        $best = $this->rule(2, '10.00');
        $best->setMaxRewardPerOrder('15.00');
        $other = $this->rule(3, '5.00');
        $this->rules->method('findLive')->willReturn([$spent, $best, $other]);
        $this->rules->method('spentByRuleIds')->willReturn([1 => '100.00']);

        $badge = $this->service()->badgeForProduct($product);
        self::assertNotNull($badge);
        self::assertSame('percentage', $badge['type']);
        self::assertSame('10.00', $badge['value']);
        self::assertSame('15.00', $badge['max']);
    }

    #[Test]
    public function orderSummaryIsTheCustomerFacingAwardShape(): void
    {
        $order = $this->paidOrder();
        $award = $this->pendingAward($order);
        $this->deliver($order, new DateTimeImmutable('2026-11-01T10:00:00+00:00'));
        $this->awards->method('findOneByOrder')->willReturn($award);

        self::assertSame([
            'status'       => 'pending',
            'amount'       => '10.00',
            'currency'     => 'AED',
            'release_at'   => '2026-11-15T10:00:00+00:00',
            'gift_card_id' => null,
        ], $this->service()->orderSummary($order));
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

    /**
     * Set the item statuses (in item order), stamp every item's last change
     * at $at and roll the order up the way the item controllers do.
     *
     * @param list<string> $statuses
     */
    private function settleItems(Order $order, array $statuses, DateTimeImmutable $at): void
    {
        foreach (array_values($order->getItems()->toArray()) as $i => $item) {
            $this->setProp($item, 'itemStatus', $statuses[$i]);
            $this->setProp($item, 'updatedAt', $at);
        }
        $order->recomputeStatusFromItems();
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
