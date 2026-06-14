<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Compliance;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Notification\MailerException;
use Bayti\Api\Notification\MailerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Notifies a vendor of an admin's KYC compliance decision through two
 * channels: the in-app feed (a NotificationLog row with a '.vendor'
 * template, so it surfaces in the top-bar bell) and a transactional email.
 * Email failures never block the review action — they are logged.
 */
class ComplianceNotificationService
{
    private const TEMPLATE_APPROVED = 'compliance.approved.vendor';
    private const TEMPLATE_REJECTED = 'compliance.rejected.vendor';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function approved(Vendor $vendor): void
    {
        $this->notify($vendor, self::TEMPLATE_APPROVED, null);
    }

    public function rejected(Vendor $vendor, ?string $note): void
    {
        $this->notify($vendor, self::TEMPLATE_REJECTED, $note);
    }

    private function notify(Vendor $vendor, string $template, ?string $note): void
    {
        try {
            $email = $vendor->getContactEmail();
        } catch (\Error) {
            return; // contactEmail uninitialised — nothing to notify
        }
        if ($email === '') {
            return;
        }

        // 1. In-app feed entry (independent of email delivery).
        try {
            /** @var NotificationLogRepository $repo */
            $repo = $this->em->getRepository(NotificationLog::class);
            $repo->save(NotificationLog::sent(orderId: null, template: $template, recipient: $email));
        } catch (\Throwable $e) {
            $this->logger->warning('compliance.notification.feed_persist_failed', [
                'vendor_id' => $vendor->getId(),
                'template'  => $template,
                'error'     => $e->getMessage(),
            ]);
        }

        // 2. Transactional email (best-effort).
        [$subject, $text, $html] = $this->compose($vendor, $template, $note);
        try {
            $this->mailer->send($email, $subject, $text, $html, [
                'vendor_id' => $vendor->getId(),
                'template'  => $template,
            ]);
        } catch (MailerException $e) {
            $this->logger->warning('compliance.notification.email_failed', [
                'vendor_id' => $vendor->getId(),
                'template'  => $template,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string} [subject, text, html]
     */
    private function compose(Vendor $vendor, string $template, ?string $note): array
    {
        $store = $vendor->getName();
        if ($template === self::TEMPLATE_APPROVED) {
            $subject = 'Your compliance documents have been approved';
            $intro = "Good news — the compliance documents for {$store} have been approved. Your store is fully verified.";
            $noteHtml = '';
            $noteText = '';
        } else {
            $subject = 'Action needed: your compliance submission was rejected';
            $intro = "The compliance documents for {$store} were not approved. Please review the reason below and re-upload your documents.";
            $reason = $note !== null && $note !== '' ? $note : 'No specific reason was provided.';
            $noteHtml = '<p style="margin:16px 0;padding:12px 16px;background:#fdf2f2;border-left:4px solid #dc2626;border-radius:4px;">'
                . '<strong>Reason:</strong> ' . htmlspecialchars($reason, ENT_QUOTES) . '</p>';
            $noteText = "\n\nReason: {$reason}";
        }

        $text = "{$intro}{$noteText}\n\n— 3bayti";
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1f2937;line-height:1.6;">'
            . '<p style="margin:0 0 12px;">' . htmlspecialchars($intro, ENT_QUOTES) . '</p>'
            . $noteHtml
            . '<p style="margin:16px 0 0;color:#6b7280;">— 3bayti</p>'
            . '</div>';

        return [$subject, $text, $html];
    }
}
