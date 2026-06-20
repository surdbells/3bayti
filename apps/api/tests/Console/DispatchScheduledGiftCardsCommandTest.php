<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\DispatchScheduledGiftCardsCommand;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\GiftCardDeliveryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for DispatchScheduledGiftCardsCommand.
 *
 * Strategy: mock the EM + GiftCardRepository + GiftCardDeliveryService;
 * drive via Symfony's CommandTester.
 *
 * Matrix:
 *   - No due cards → SUCCESS, no deliver()
 *   - Due cards → deliver() once per card
 *   - --dry-run → lists IDs without delivering
 *   - One card's deliver() throws → batch continues + error counted
 */
final class DispatchScheduledGiftCardsCommandTest extends TestCase
{
    private EntityManagerInterface $em;
    private GiftCardRepository $repo;
    private GiftCardDeliveryService $delivery;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repo = $this->createMock(GiftCardRepository::class);
        $this->delivery = $this->createMock(GiftCardDeliveryService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->em->method('getRepository')->willReturnCallback(
            fn (string $class): object => match ($class) {
                GiftCard::class => $this->repo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );
    }

    #[Test]
    public function noDueCardsExitsSuccessWithoutDelivery(): void
    {
        $this->repo->expects(self::once())
            ->method('findDueForDelivery')
            ->willReturn([]);
        $this->delivery->expects(self::never())->method('deliver');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Nothing to deliver', $tester->getDisplay());
    }

    #[Test]
    public function deliversOncePerDueCard(): void
    {
        $cards = [$this->makeCard(1), $this->makeCard(2), $this->makeCard(3)];
        $this->repo->method('findDueForDelivery')->willReturn($cards);

        $this->delivery->expects(self::exactly(3))->method('deliver');

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Found 3', $tester->getDisplay());
    }

    #[Test]
    public function dryRunListsIdsWithoutDelivering(): void
    {
        $cards = [$this->makeCard(11), $this->makeCard(22)];
        $this->repo->method('findDueForDelivery')->willReturn($cards);

        $this->delivery->expects(self::never())->method('deliver');

        $tester = $this->tester();
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('DRY RUN', $display);
        self::assertStringContainsString('11, 22', $display);
    }

    #[Test]
    public function oneCardFailureDoesNotAbortBatch(): void
    {
        $cards = [$this->makeCard(1), $this->makeCard(2), $this->makeCard(3)];
        $this->repo->method('findDueForDelivery')->willReturn($cards);

        // Second card throws; the other two must still be attempted.
        $seen = 0;
        $this->delivery->expects(self::exactly(3))
            ->method('deliver')
            ->willReturnCallback(function (GiftCard $card) use (&$seen): void {
                $seen++;
                if ($card->getId() === 2) {
                    throw new \RuntimeException('transient boom');
                }
            });

        $tester = $this->tester();
        $exit = $tester->execute([]);

        self::assertSame(3, $seen);
        // One error => FAILURE exit, but batch completed.
        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Gift card #2 failed', $tester->getDisplay());
    }

    // -----------------------------------------------------------------

    private function tester(): CommandTester
    {
        $command = new DispatchScheduledGiftCardsCommand($this->em, $this->delivery, $this->logger);
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('gift-cards:dispatch-scheduled'));
    }

    private function makeCard(int $id): GiftCard
    {
        $buyer = new User('buyer@example.com', '+971500000000', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $card = new GiftCard(
            buyerUser: $buyer,
            denomination: '500.00',
            theme: 'birthday',
            recipientName: 'Sara',
            recipientMessage: null,
            recipientPhotoUrl: null,
            scheduledDeliveryAt: null,
            recipientEmail: 'sara@example.com',
        );
        $ref = new \ReflectionProperty(GiftCard::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($card, $id);
        return $card;
    }
}
