<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Compliance;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Compliance\ComplianceNotificationService;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Notification\MailerInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ComplianceNotificationService::class)]
final class ComplianceNotificationServiceTest extends TestCase
{
    private ?NotificationLog $logged = null;
    /** @var array{0:string,1:string}|null [to, subject] */
    private ?array $sent = null;

    private function service(): ComplianceNotificationService
    {
        $repo = $this->createMock(NotificationLogRepository::class);
        $repo->method('save')->willReturnCallback(function (NotificationLog $l): void {
            $this->logged = $l;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            function (string $to, string $subject): void { $this->sent = [$to, $subject]; }
        );

        return new ComplianceNotificationService($mailer, $em);
    }

    private function vendor(): Vendor
    {
        return new Vendor('halif', 'Halif Stores', 'halif@example.com');
    }

    #[Test]
    public function approvedWritesFeedEntryAndEmails(): void
    {
        $this->service()->approved($this->vendor());

        self::assertNotNull($this->logged);
        self::assertSame('compliance.approved.vendor', $this->logged->getTemplate());
        self::assertSame('halif@example.com', $this->logged->getRecipient());
        self::assertNotNull($this->sent);
        self::assertSame('halif@example.com', $this->sent[0]);
        self::assertStringContainsStringIgnoringCase('approved', $this->sent[1]);
    }

    #[Test]
    public function rejectedWritesFeedEntryAndEmailsWithReason(): void
    {
        $this->service()->rejected($this->vendor(), 'ID photo is blurry');

        self::assertNotNull($this->logged);
        self::assertSame('compliance.rejected.vendor', $this->logged->getTemplate());
        self::assertNotNull($this->sent);
        self::assertStringContainsStringIgnoringCase('rejected', $this->sent[1]);
    }
}
