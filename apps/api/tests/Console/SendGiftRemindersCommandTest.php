<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\SendGiftRemindersCommand;
use Bayti\Api\Domain\GiftReminder\GiftReminder;
use Bayti\Api\Domain\GiftReminder\GiftReminderDispatchFinder;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\GiftReminderMailer;
use Bayti\Api\Notification\Push\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SendGiftRemindersCommand::class)]
final class SendGiftRemindersCommandTest extends TestCase
{
    #[Test]
    public function sendsPushAndEmailThenMarksTheStage(): void
    {
        $reminder = $this->makeReminder(10);

        $finder = $this->createMock(GiftReminderDispatchFinder::class);
        $finder->method('findDue')->willReturn([['id' => 10, 'stage' => 14]]);

        $push = $this->createMock(PushNotificationService::class);
        $push->expects(self::once())->method('giftReminderNudge')->with($reminder, 14);

        $mailer = $this->createMock(GiftReminderMailer::class);
        $mailer->expects(self::once())->method('sendNudge')->with($reminder->getUser(), $reminder, 14);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->with(GiftReminder::class, 10)->willReturn($reminder);
        $em->expects(self::once())->method('flush');

        $tester = new CommandTester(new SendGiftRemindersCommand($em, $finder, $push, $mailer, new NullLogger()));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(14, $reminder->getLastNotifiedStage());
    }

    #[Test]
    public function dryRunSendsNothing(): void
    {
        $finder = $this->createMock(GiftReminderDispatchFinder::class);
        $finder->method('findDue')->willReturn([['id' => 10, 'stage' => 7]]);

        $push = $this->createMock(PushNotificationService::class);
        $push->expects(self::never())->method('giftReminderNudge');
        $mailer = $this->createMock(GiftReminderMailer::class);
        $mailer->expects(self::never())->method('sendNudge');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $tester = new CommandTester(new SendGiftRemindersCommand($em, $finder, $push, $mailer, new NullLogger()));
        $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('DRY RUN', $tester->getDisplay());
    }

    #[Test]
    public function nothingDueIsANoOp(): void
    {
        $finder = $this->createMock(GiftReminderDispatchFinder::class);
        $finder->method('findDue')->willReturn([]);

        $push = $this->createMock(PushNotificationService::class);
        $push->expects(self::never())->method('giftReminderNudge');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $tester = new CommandTester(new SendGiftRemindersCommand(
            $em,
            $finder,
            $push,
            $this->createMock(GiftReminderMailer::class),
            new NullLogger(),
        ));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }

    private function makeReminder(int $id): GiftReminder
    {
        $user = new User('u@example.test', '+971500000000', 'x', 'AE');
        $uref = new \ReflectionProperty(User::class, 'id');
        $uref->setAccessible(true);
        $uref->setValue($user, 1);

        $r = new GiftReminder($user, 'My Sister', 'Eid', new \DateTimeImmutable('+13 days'));
        $ref = new \ReflectionProperty(GiftReminder::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($r, $id);
        return $r;
    }
}
