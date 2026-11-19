<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\ReleaseCashbackCommand;
use Bayti\Api\Domain\Cashback\CashbackService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReleaseCashbackCommand::class)]
final class ReleaseCashbackCommandTest extends TestCase
{
    #[Test]
    public function passesLimitAndDryRunAndPrintsProgress(): void
    {
        $svc = $this->createMock(CashbackService::class);
        $svc->expects(self::once())
            ->method('releaseDue')
            ->willReturnCallback(function (\DateTimeImmutable $now, int $limit, bool $dryRun, ?callable $progress): array {
                self::assertSame(5, $limit);
                self::assertTrue($dryRun);
                self::assertNotNull($progress);
                $progress(11, CashbackService::OUTCOME_WOULD_ISSUE);
                $progress(12, CashbackService::OUTCOME_WOULD_CANCEL);
                return ['due' => 2, 'issued' => 1, 'cancelled' => 1, 'skipped' => 0, 'errors' => 0];
            });

        $tester = $this->tester($svc);
        $code = $tester->execute(['--dry-run' => true, '--limit' => '5']);

        self::assertSame(Command::SUCCESS, $code);
        $out = $tester->getDisplay();
        self::assertStringContainsString('Award #11 → would_issue', $out);
        self::assertStringContainsString('Award #12 → would_cancel', $out);
        self::assertStringContainsString('DRY RUN', $out);
    }

    #[Test]
    public function failsWhenAnAwardErrored(): void
    {
        $svc = $this->createMock(CashbackService::class);
        $svc->method('releaseDue')->willReturn(['due' => 1, 'issued' => 0, 'cancelled' => 0, 'skipped' => 0, 'errors' => 1]);

        self::assertSame(Command::FAILURE, $this->tester($svc)->execute([]));
    }

    private function tester(CashbackService $svc): CommandTester
    {
        $app = new Application();
        $app->add(new ReleaseCashbackCommand($svc, new NullLogger()));
        return new CommandTester($app->find('cashback:release'));
    }
}
