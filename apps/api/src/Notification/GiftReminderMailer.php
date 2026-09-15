<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\GiftReminder\GiftReminder;
use Bayti\Api\Domain\User\User;
use Psr\Log\LoggerInterface;

/**
 * Emails a gift-reminder nudge whose CTA deep-links into the Ain Gift Concierge
 * on the CUSTOMER storefront (WEB_APP_URL), pre-filled from the reminder's brief.
 * The reminder carries NO product ids — picks are resolved live at click time.
 * Fire-and-forget: a mail failure never blocks the dispatch loop.
 */
class GiftReminderMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendNudge(User $user, GiftReminder $reminder, int $stageDays): void
    {
        $email = $user->getEmail();
        if ($email === '') {
            return;
        }

        $isAr = str_starts_with(strtolower($user->getLocale()), 'ar');
        $recipient = $reminder->getRecipientName();
        $occasion = $reminder->getOccasion();
        $link = $this->giftLink($reminder);

        if ($isAr) {
            $subject = sprintf('تذكير: هدية %s لـ%s قريباً', $occasion, $recipient);
            $text = sprintf(
                "تبقّى %d يوم على %s لـ%s. دع عين تختر لك هدية مثالية من متاجرنا.\n%s",
                $stageDays,
                $occasion,
                $recipient,
                $link,
            );
            $cta = 'اعثر على هدية';
            $heading = sprintf('%s لـ%s قريباً', $occasion, $recipient);
            $sub = sprintf('تبقّى %d يوم. دع عين تختار الهدية المثالية.', $stageDays);
        } else {
            $subject = sprintf("Reminder: %s's %s is coming up", $recipient, $occasion);
            $text = sprintf(
                "%s's %s is %d day%s away. Let Ain find the perfect gift from our stores.\n%s",
                $recipient,
                $occasion,
                $stageDays,
                $stageDays === 1 ? '' : 's',
                $link,
            );
            $cta = 'Find a gift';
            $heading = sprintf("%s's %s is coming up", $recipient, $occasion);
            $sub = sprintf('%d day%s to go — let Ain find the perfect gift.', $stageDays, $stageDays === 1 ? '' : 's');
        }

        $html = $this->html($heading, $sub, $cta, $link);

        try {
            $this->mailer->send($email, $subject, $text, $html, [
                'template' => 'gift_reminder.nudge',
                'gift_reminder_id' => $reminder->getId(),
                'user_id' => $user->getId(),
                'stage' => $stageDays,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('gift_reminder.email_failed', [
                'gift_reminder_id' => $reminder->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function giftLink(GiftReminder $reminder): string
    {
        $base = rtrim((string) ($_ENV['WEB_APP_URL'] ?? 'https://3bayti.ae'), '/');
        $query = array_filter([
            'occasion' => $reminder->getOccasion(),
            'budget_max' => $reminder->getBudgetMax(),
            'category_slug' => $reminder->getCategorySlug(),
            'gift_reminder_id' => (string) ($reminder->getId() ?? ''),
        ], static fn ($v): bool => $v !== null && $v !== '');

        return $base . '/gift-ain?' . http_build_query($query);
    }

    private function html(string $heading, string $sub, string $cta, string $link): string
    {
        $h = htmlspecialchars($heading, ENT_QUOTES);
        $s = htmlspecialchars($sub, ENT_QUOTES);
        $c = htmlspecialchars($cta, ENT_QUOTES);
        $l = htmlspecialchars($link, ENT_QUOTES);

        return <<<HTML
            <div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#2e241c">
              <div style="text-align:center;padding:8px 0 20px">
                <span style="display:inline-block;width:56px;height:56px;border-radius:50%;background:#5a3a2c;color:#fff;line-height:56px;font-size:24px">&#127873;</span>
              </div>
              <h1 style="font-size:20px;text-align:center;margin:0 0 8px;color:#5a3a2c">{$h}</h1>
              <p style="font-size:15px;text-align:center;color:#5a4a3c;margin:0 0 24px">{$s}</p>
              <div style="text-align:center">
                <a href="{$l}" style="display:inline-block;background:#5a3a2c;color:#fff;text-decoration:none;padding:12px 28px;border-radius:999px;font-weight:bold">{$c}</a>
              </div>
            </div>
            HTML;
    }
}
