<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\ReconcilePendingOrdersCommand;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Payment\OrderStatusResponse;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the M3.1.7-B reconciliation command.
 *
 * Strategy: mock the EntityManager + its repository handles, mock
 * the gateway, drive the command through Symfony's CommandTester.
 * No real DB; no real HTTP calls.
 *
 * Test matrix covers the command's full decision tree:
 *   - No stuck orders → SUCCESS, no flush
 *   - Order missing INITIATE transaction → deferred + warning
 *   - Order with empty provider_order_ref → deferred
 *   - Gateway throws → deferred + warning, no abort
 *   - Gateway says terminal+paid → markPaid called
 *   - Gateway says terminal+!paid → markFailed called
 *   - Gateway says !terminal → no mutation
 *   - --dry-run: same logic but no flush
 */
final class ReconcilePendingOrdersCommandTest extends TestCase
{
    private EntityManagerInterface $em;
    private OrderRepository $orderRepo;
    private PaymentTransactionRepository $txnRepo;
    private PaymentGatewayInterface $gateway;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->orderRepo = $this->createMock(OrderRepository::class);
        $this->txnRepo = $this->createMock(PaymentTransactionRepository::class);
        $this->gateway = $this->createMock(PaymentGatewayInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // EM::getRepository routes to the right mock per class.
        $this->em->method('getRepository')->willReturnCallback(
            fn (string $class): object => match ($class) {
                Order::class => $this->orderRepo,
                PaymentTransaction::class => $this->txnRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            }
        );
    }

    private function tester(): CommandTester
    {
        $command = new ReconcilePendingOrdersCommand($this->em, $this->gateway, $this->logger);
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('orders:reconcile-pending'));
    }

    /** Build a mock Order with the configured id + reference. */
    private function makeOrder(int $id = 1, string $reference = 'V3-001'): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getOrderReference')->willReturn($reference);
        return $order;
    }

    /** Build a mock PaymentTransaction with the given provider_order_ref. */
    private function makeTxn(?string $providerOrderRef): PaymentTransaction
    {
        $txn = $this->createMock(PaymentTransaction::class);
        $txn->method('getProviderOrderRef')->willReturn($providerOrderRef);
        return $txn;
    }

    private function statusResponse(string $status, bool $terminal, bool $paid): OrderStatusResponse
    {
        return new OrderStatusResponse(
            providerOrderRef: 'NOON-REF-1',
            status: $status,
            terminal: $terminal,
            paid: $paid,
            amount: '299.00',
            currency: 'AED',
            rawResponse: [],
        );
    }

    #[Test]
    public function reportsSuccessWhenNoStuckOrders(): void
    {
        $this->orderRepo->expects(self::once())
            ->method('findStuckPendingPayment')
            ->willReturn([]);

        $this->em->expects(self::never())->method('flush');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Found 0 stuck order(s)', $tester->getDisplay());
        self::assertStringContainsString('Nothing to reconcile', $tester->getDisplay());
    }

    #[Test]
    public function skipsOrderWithoutInitiateTransaction(): void
    {
        $order = $this->makeOrder(1, 'V3-001');
        $this->orderRepo->method('findStuckPendingPayment')->willReturn([$order]);
        $this->txnRepo->method('findLatestInitiateForOrder')->willReturn(null);

        $this->logger->expects(self::atLeastOnce())->method('warning')
            ->with(self::stringContains('no_initiate_transaction'), self::anything());

        // No flush because nothing resolved
        $this->em->expects(self::never())->method('flush');

        $tester = $this->tester();
        $tester->execute([]);
        self::assertStringContainsString('no INITIATE transaction', $tester->getDisplay());
    }

    #[Test]
    public function skipsOrderWithEmptyProviderOrderRef(): void
    {
        $order = $this->makeOrder(2, 'V3-002');
        $this->orderRepo->method('findStuckPendingPayment')->willReturn([$order]);
        $this->txnRepo->method('findLatestInitiateForOrder')->willReturn($this->makeTxn(null));

        $this->em->expects(self::never())->method('flush');

        $tester = $this->tester();
        $tester->execute([]);
        self::assertStringContainsString('no provider_order_ref', $tester->getDisplay());
    }

    #[Test]
    public function continuesPastGatewayException(): void
    {
        $order1 = $this->makeOrder(3, 'V3-003');
        $order2 = $this->makeOrder(4, 'V3-004');
        $this->orderRepo->method('findStuckPendingPayment')
            ->willReturn([$order1, $order2]);
        $this->txnRepo->method('findLatestInitiateForOrder')
            ->willReturn($this->makeTxn('NOON-REF'));

        // First order errors; second succeeds with paid
        $this->gateway->method('retrieveOrder')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new PaymentGatewayException(
                    message: 'temporary 503',
                    kind: PaymentGatewayException::KIND_NETWORK,
                )),
                $this->statusResponse('PAID', true, true),
            );

        $order2->expects(self::once())->method('markPaid');
        // One resolved → one flush
        $this->em->expects(self::once())->method('flush');

        $tester = $this->tester();
        $exit = $tester->execute([]);
        self::assertSame(0, $exit);

        $output = $tester->getDisplay();
        self::assertStringContainsString('retrieveOrder failed', $output);
        self::assertStringContainsString('marked_paid', $output);
    }

    #[Test]
    public function marksPaidWhenGatewayReportsTerminalPaid(): void
    {
        $order = $this->makeOrder(5, 'V3-005');
        $this->orderRepo->method('findStuckPendingPayment')->willReturn([$order]);
        $this->txnRepo->method('findLatestInitiateForOrder')
            ->willReturn($this->makeTxn('NOON-REF-5'));
        $this->gateway->method('retrieveOrder')
            ->willReturn($this->statusResponse('CAPTURED', true, true));

        $order->expects(self::once())->method('markPaid');
        $this->em->expects(self::once())->method('flush');

        $tester = $this->tester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('marked_paid', $output);
        self::assertStringContainsString('1 resolved (1 paid + 0 failed)', $output);
    }

    #[Test]
    public function marksFailedWhenGatewayReportsTerminalNotPaid(): void
    {
        $order = $this->makeOrder(6, 'V3-006');
        $this->orderRepo->method('findStuckPendingPayment')->willReturn([$order]);
        $this->txnRepo->method('findLatestInitiateForOrder')
            ->willReturn($this->makeTxn('NOON-REF-6'));
        $this->gateway->method('retrieveOrder')
            ->willReturn($this->statusResponse('FAILED', true, false));

        $order->expects(self::once())->method('markFailed');
        $this->em->expects(self::once())->method('flush');

        $tester = $this->tester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('marked_failed', $output);
        self::assertStringContainsString('1 resolved (0 paid + 1 failed)', $output);
    }

    #[Test]
    public function leavesAloneWhenGatewayReportsNonTerminal(): void
    {
        $order = $this->makeOrder(7, 'V3-007');
        $this->orderRepo->method('findStuckPendingPayment')->willReturn([$order]);
        $this->txnRepo->method('findLatestInitiateForOrder')
            ->willReturn($this->makeTxn('NOON-REF-7'));
        $this->gateway->method('retrieveOrder')
            ->willReturn($this->statusResponse('AUTHORIZED', false, false));

        $order->expects(self::never())->method('markPaid');
        $order->expects(self::never())->method('markFailed');
        $this->em->expects(self::never())->method('flush');

        $tester = $this->tester();
        $tester->execute([]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('still_transient', $output);
    }

    #[Test]
    public function dryRunReportsWithoutFlushing(): void
    {
        $order = $this->makeOrder(8, 'V3-008');
        $this->orderRepo->method('findStuckPendingPayment')->willReturn([$order]);
        $this->txnRepo->method('findLatestInitiateForOrder')
            ->willReturn($this->makeTxn('NOON-REF-8'));
        $this->gateway->method('retrieveOrder')
            ->willReturn($this->statusResponse('CAPTURED', true, true));

        $order->expects(self::never())->method('markPaid');
        $order->expects(self::never())->method('markFailed');
        $this->em->expects(self::never())->method('flush');

        $tester = $this->tester();
        $tester->execute(['--dry-run' => true]);

        $output = $tester->getDisplay();
        self::assertStringContainsString('DRY RUN', $output);
        self::assertStringContainsString('would_mark_paid', $output);
    }

    #[Test]
    public function respectsCustomCutoffAndBatchSizeOptions(): void
    {
        $this->orderRepo->expects(self::once())
            ->method('findStuckPendingPayment')
            ->with(self::isInstanceOf(\DateTimeImmutable::class), 100)
            ->willReturn([]);

        $tester = $this->tester();
        $tester->execute(['--cutoff-min' => '30', '--batch-size' => '100']);
    }
}
