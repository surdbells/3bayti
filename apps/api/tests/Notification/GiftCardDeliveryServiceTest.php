<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\GiftCardDeliveryService;
use Bayti\Api\Notification\GiftCardEmailTemplateRenderer;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Bayti\Api\Sms\InMemorySmsSender;
use Bayti\Api\Sms\NullSmsSender;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(GiftCardDeliveryService::class)]
#[CoversClass(GiftCardEmailTemplateRenderer::class)]
final class GiftCardDeliveryServiceTest extends TestCase
{
    private InMemoryMailer $mailer;
    private InMemorySmsSender $sms;

    protected function setUp(): void
    {
        $this->mailer = new InMemoryMailer();
        $this->sms = new InMemorySmsSender();
    }

    #[Test]
    public function emailOnlyCardDeliversEmailAndMarksDelivered(): void
    {
        $card = $this->makeCard(email: 'sara@example.com', phone: null);
        $service = $this->makeService();

        $service->deliver($card);

        self::assertCount(1, $this->mailer->sent());
        self::assertSame('sara@example.com', $this->mailer->sent()[0]['to']);
        self::assertCount(0, $this->sms->sent());
        self::assertNotNull($card->getEmailDeliveredAt());
        self::assertNull($card->getSmsDeliveredAt());
        self::assertFalse($card->needsEmailDelivery());
    }

    #[Test]
    public function smsOnlyCardDeliversSmsAndMarksDelivered(): void
    {
        $card = $this->makeCard(email: null, phone: '+971501234567');
        $service = $this->makeService();

        $service->deliver($card);

        self::assertCount(0, $this->mailer->sent());
        self::assertCount(1, $this->sms->sent());
        self::assertSame('+971501234567', $this->sms->sent()[0]['to']);
        self::assertStringContainsString('3bayti gift card', $this->sms->sent()[0]['message']);
        self::assertStringContainsString($card->formattedCode(), $this->sms->sent()[0]['message']);
        self::assertNotNull($card->getSmsDeliveredAt());
        self::assertNull($card->getEmailDeliveredAt());
    }

    #[Test]
    public function bothChannelsDeliverWhenBothContactsPresent(): void
    {
        $card = $this->makeCard(email: 'sara@example.com', phone: '+971501234567');
        $service = $this->makeService();

        $service->deliver($card);

        self::assertCount(1, $this->mailer->sent());
        self::assertCount(1, $this->sms->sent());
        self::assertNotNull($card->getEmailDeliveredAt());
        self::assertNotNull($card->getSmsDeliveredAt());
    }

    #[Test]
    public function noContactDeliversNothing(): void
    {
        $card = $this->makeCard(email: null, phone: null);
        $service = $this->makeService();

        $service->deliver($card);

        self::assertCount(0, $this->mailer->sent());
        self::assertCount(0, $this->sms->sent());
        self::assertNull($card->getEmailDeliveredAt());
        self::assertNull($card->getSmsDeliveredAt());
    }

    #[Test]
    public function redeliveringAlreadyDeliveredCardIsNoOp(): void
    {
        $card = $this->makeCard(email: 'sara@example.com', phone: '+971501234567');
        $service = $this->makeService();

        $service->deliver($card);
        $firstEmailAt = $card->getEmailDeliveredAt();
        $firstSmsAt = $card->getSmsDeliveredAt();

        // Second deliver() must not send again.
        $service->deliver($card);

        self::assertCount(1, $this->mailer->sent());
        self::assertCount(1, $this->sms->sent());
        self::assertSame($firstEmailAt, $card->getEmailDeliveredAt());
        self::assertSame($firstSmsAt, $card->getSmsDeliveredAt());
    }

    #[Test]
    public function mailerThrowsIsNonBlockingAndLeavesChannelUndelivered(): void
    {
        $throwingMailer = new class implements MailerInterface {
            public int $attempts = 0;
            public function send(string $to, string $subject, string $textBody, string $htmlBody, array $context = []): void
            {
                $this->attempts++;
                throw new MailerException(MailerException::KIND_TRANSPORT, 'boom');
            }
        };

        $card = $this->makeCard(email: 'sara@example.com', phone: '+971501234567');
        $service = new GiftCardDeliveryService(
            renderer: new GiftCardEmailTemplateRenderer(),
            mailer: $throwingMailer,
            smsSender: $this->sms,
            em: $this->makeEm(),
            logger: new NullLogger(),
        );

        // Must NOT throw.
        $service->deliver($card);

        self::assertSame(1, $throwingMailer->attempts);
        // Email failed -> still needs delivery (cron will retry).
        self::assertNull($card->getEmailDeliveredAt());
        self::assertTrue($card->needsEmailDelivery());
        // SMS is independent -> delivered fine.
        self::assertNotNull($card->getSmsDeliveredAt());
        self::assertCount(1, $this->sms->sent());
    }

    #[Test]
    public function smsThrowsIsNonBlockingAndEmailStillDelivers(): void
    {
        $throwingSms = new InMemorySmsSender(throwOnSend: true);
        $card = $this->makeCard(email: 'sara@example.com', phone: '+971501234567');
        $service = new GiftCardDeliveryService(
            renderer: new GiftCardEmailTemplateRenderer(),
            mailer: $this->mailer,
            smsSender: $throwingSms,
            em: $this->makeEm(),
            logger: new NullLogger(),
        );

        $service->deliver($card);

        self::assertNotNull($card->getEmailDeliveredAt());
        self::assertNull($card->getSmsDeliveredAt());
        self::assertTrue($card->needsSmsDelivery());
        self::assertCount(1, $this->mailer->sent());
    }

    #[Test]
    public function smsNotConfiguredLeavesChannelUndeliveredAndEmailStillDelivers(): void
    {
        // NullSmsSender = SMS not enabled/configured. A card with a phone must
        // NOT be marked SMS-delivered off the silent no-op, it stays pending so
        // the scheduled dispatcher actually sends it once real SMS is enabled.
        $card = $this->makeCard(email: 'sara@example.com', phone: '+971501234567');
        $service = new GiftCardDeliveryService(
            renderer: new GiftCardEmailTemplateRenderer(),
            mailer: $this->mailer,
            smsSender: new NullSmsSender(),
            em: $this->makeEm(),
            logger: new NullLogger(),
        );

        $service->deliver($card);

        // Email delivers normally.
        self::assertNotNull($card->getEmailDeliveredAt());
        self::assertCount(1, $this->mailer->sent());
        // SMS not sent AND not marked delivered -> still pending (honest).
        self::assertNull($card->getSmsDeliveredAt());
        self::assertTrue($card->needsSmsDelivery());
    }

    // ---- resend() : the admin manual "Send to recipient" action --------

    #[Test]
    public function resendForceSendsEvenWhenAlreadyDelivered(): void
    {
        $card = $this->makeCard(email: 'sara@example.com', phone: '+971501234567');
        $service = $this->makeService();

        $service->deliver($card);
        self::assertCount(1, $this->mailer->sent());
        self::assertCount(1, $this->sms->sent());

        // deliver() again is a no-op (idempotency guard); resend() must force
        // a fresh send on both channels.
        $result = $service->resend($card);

        self::assertSame(['email' => 'sent', 'sms' => 'sent'], $result);
        self::assertCount(2, $this->mailer->sent());
        self::assertCount(2, $this->sms->sent());
        self::assertNotNull($card->getEmailDeliveredAt());
        self::assertNotNull($card->getSmsDeliveredAt());
    }

    #[Test]
    public function resendReportsNoRecipientForAbsentChannel(): void
    {
        $card = $this->makeCard(email: 'sara@example.com', phone: null);

        $result = $this->makeService()->resend($card);

        self::assertSame(['email' => 'sent', 'sms' => 'no_recipient'], $result);
        self::assertCount(1, $this->mailer->sent());
        self::assertCount(0, $this->sms->sent());
    }

    #[Test]
    public function resendFallsBackToClaimedAccountEmailWhenNoDeliveryEmail(): void
    {
        // Card bought with only a phone (no delivery email on file), later
        // claimed by a recipient whose account email we now know. resend()
        // must be able to reach that account email.
        $card = $this->makeCard(email: null, phone: '+971501234567');
        $recipient = new User('funoun@example.com', '+971509999999', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $recipient->setName('Funoun', 'S');
        $card->assignRecipient($recipient);

        self::assertSame('funoun@example.com', $card->effectiveRecipientEmail());
        self::assertTrue($card->recipientEmailIsFromAccount());

        $result = $this->makeService()->resend($card);

        self::assertSame('sent', $result['email']);
        self::assertSame('funoun@example.com', $this->mailer->sent()[0]['to']);
        self::assertNotNull($card->getEmailDeliveredAt());
    }

    #[Test]
    public function resendFallsBackToClaimedAccountPhoneWhenNoDeliveryPhone(): void
    {
        // Card with a delivery email but no phone, claimed by a recipient whose
        // account carries a phone. resend() should SMS that account phone.
        $card = $this->makeCard(email: 'sara@example.com', phone: null);
        $recipient = new User('funoun@example.com', '+971508816837', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $recipient->setName('Funoun', 'S');
        $card->assignRecipient($recipient);

        self::assertSame('+971508816837', $card->effectiveRecipientPhone());
        self::assertTrue($card->recipientPhoneIsFromAccount());

        $result = $this->makeService()->resend($card);

        self::assertSame('sent', $result['sms']);
        self::assertSame('+971508816837', $this->sms->sent()[0]['to']);
    }

    #[Test]
    public function resendReportsNotConfiguredWhenSmsDisabled(): void
    {
        $card = $this->makeCard(email: null, phone: '+971501234567');
        $service = new GiftCardDeliveryService(
            renderer: new GiftCardEmailTemplateRenderer(),
            mailer: $this->mailer,
            smsSender: new NullSmsSender(),
            em: $this->makeEm(),
            logger: new NullLogger(),
        );

        $result = $service->resend($card);

        self::assertSame(['email' => 'no_recipient', 'sms' => 'not_configured'], $result);
        self::assertNull($card->getSmsDeliveredAt());
    }

    #[Test]
    public function resendReportsFailedWhenChannelThrows(): void
    {
        $throwingMailer = new class implements MailerInterface {
            public function send(string $to, string $subject, string $textBody, string $htmlBody, array $context = []): void
            {
                throw new MailerException(MailerException::KIND_TRANSPORT, 'boom');
            }
        };
        $card = $this->makeCard(email: 'sara@example.com', phone: null);
        $service = new GiftCardDeliveryService(
            renderer: new GiftCardEmailTemplateRenderer(),
            mailer: $throwingMailer,
            smsSender: $this->sms,
            em: $this->makeEm(),
            logger: new NullLogger(),
        );

        $result = $service->resend($card);

        self::assertSame('failed', $result['email']);
        self::assertNull($card->getEmailDeliveredAt());
    }

    // -----------------------------------------------------------------

    private function makeService(): GiftCardDeliveryService
    {
        return new GiftCardDeliveryService(
            renderer: new GiftCardEmailTemplateRenderer(),
            mailer: $this->mailer,
            smsSender: $this->sms,
            em: $this->makeEm(),
            logger: new NullLogger(),
        );
    }

    private function makeEm(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush'); // no-op
        return $em;
    }

    private function makeCard(?string $email, ?string $phone): GiftCard
    {
        $buyer = new User('buyer@example.com', '+971500000000', password_hash('p', PASSWORD_BCRYPT), 'AE');
        $buyer->setName('Omar', 'Khan');

        return new GiftCard(
            buyerUser: $buyer,
            denomination: '500.00',
            theme: 'birthday',
            recipientName: 'Sara',
            recipientMessage: 'Happy Birthday!',
            recipientPhotoUrl: null,
            scheduledDeliveryAt: null,
            recipientEmail: $email,
            recipientPhone: $phone,
        );
    }
}
