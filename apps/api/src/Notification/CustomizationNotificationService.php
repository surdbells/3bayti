<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Customization\CustomizationRequest;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Transactional email for the bespoke-customization workflow (P5).
 *
 * Why NOT OrderNotificationService
 * ================================
 * That service's whole pipeline (renderer, LocaleResolver, NotificationLog)
 * is keyed on an Order. A CustomizationRequest is its own aggregate with no
 * Order until the customer pays, so this dedicated service composes the copy
 * itself and sends through the same MailerInterface. It still writes
 * NotificationLog rows (orderId is nullable) for the audit trail, and — like
 * the order stack — never lets an email failure bubble up to the caller.
 *
 * Five events (customer emails are bilingual by the customer's locale; the
 * two vendor emails are English, matching the order stack's vendor/admin
 * convention):
 *   - submitted → vendor      "you have a new request to quote"
 *   - quoted    → customer    "your quote is ready — accept or decline"
 *   - accepted  → vendor      "the customer accepted; payment in progress"
 *   - declined  → customer    "the vendor can't take this on"
 *   - completed → customer    "your customization is ready"
 */
final class CustomizationNotificationService
{
    private readonly string $webBase;
    private readonly string $portalBase;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly ?EntityManagerInterface $em = null,
    ) {
        $webBase = $_ENV['WEB_APP_URL'] ?? 'https://3bayti.ae';
        $portalBase = $_ENV['VENDOR_PORTAL_URL'] ?? 'https://app.3bayti.ae';
        $this->webBase = rtrim(is_string($webBase) ? $webBase : 'https://3bayti.ae', '/');
        $this->portalBase = rtrim(is_string($portalBase) ? $portalBase : 'https://app.3bayti.ae', '/');
    }

    // -----------------------------------------------------------------
    // Public hooks (one per lifecycle event)
    // -----------------------------------------------------------------

    /** A new request landed → nudge the vendor to quote it. */
    public function customizationSubmitted(CustomizationRequest $r): void
    {
        $to = $this->vendorEmail($r);
        $product = $this->esc($r->getProduct()->getName());
        $link = $this->portalBase . '/vendor/customization-requests';
        $subject = "New customization request for {$r->getProduct()->getName()}";
        $intro = "A customer has requested a bespoke customization on <strong>{$product}</strong>. "
            . 'Review the details and send them a quote.';
        $introText = "A customer has requested a bespoke customization on \"{$r->getProduct()->getName()}\". "
            . 'Review the details and send them a quote.';
        $this->safeSend(
            $r,
            $to,
            'customization.submitted.vendor',
            $subject,
            $this->text($introText, 'Review in your dashboard:', $link),
            $this->html('New customization request', $intro, 'Review request', $link, false),
        );
    }

    /** The vendor quoted → tell the customer to accept or decline. */
    public function customizationQuoted(CustomizationRequest $r): void
    {
        $customer = $r->getCustomer();
        $ar = $this->isArabic($customer);
        $to = trim($customer->getEmail());
        $product = $r->getProduct()->getName();
        $vendor = $r->getVendor()->getName();
        $amount = (string) $r->getQuoteAmount();
        $currency = (string) ($r->getQuoteCurrency() ?? 'AED');
        $lead = $r->getQuoteLeadTimeDays();
        $link = $this->webBase . '/account/customization-requests';

        if ($ar) {
            $subject = 'عرض السعر لطلب التخصيص جاهز — بيتي';
            $leadTxt = $lead !== null ? "، جاهز خلال {$lead} يوم" : '';
            $body = "قدّم {$this->esc($vendor)} عرض سعر بقيمة <strong>{$this->esc($amount)} {$this->esc($currency)}</strong> "
                . "لتخصيص \"{$this->esc($product)}\"{$this->esc($leadTxt)}. يمكنك قبول العرض أو رفضه من حسابك.";
            $bodyText = "قدّم {$vendor} عرض سعر بقيمة {$amount} {$currency} لتخصيص \"{$product}\"{$leadTxt}. "
                . 'يمكنك قبول العرض أو رفضه من حسابك.';
            $cta = 'عرض العرض';
        } else {
            $subject = 'Your customization quote is ready — 3bayti';
            $leadTxt = $lead !== null ? ", ready in about {$lead} day" . ($lead === 1 ? '' : 's') : '';
            $body = "{$this->esc($vendor)} has quoted <strong>{$this->esc($amount)} {$this->esc($currency)}</strong> "
                . "to customize \"{$this->esc($product)}\"{$this->esc($leadTxt)}. Accept or decline it from your account.";
            $bodyText = "{$vendor} has quoted {$amount} {$currency} to customize \"{$product}\"{$leadTxt}. "
                . 'Accept or decline it from your account.';
            $cta = 'View quote';
        }

        $this->safeSend(
            $r,
            $to,
            'customization.quoted.customer',
            $subject,
            $this->text($bodyText, $ar ? 'اذهب إلى حسابك:' : 'Go to your account:', $link),
            $this->html($subject, $body, $cta, $link, true, $ar),
        );
    }

    /** The customer accepted → tell the vendor (payment now in progress). */
    public function customizationAccepted(CustomizationRequest $r): void
    {
        $to = $this->vendorEmail($r);
        $product = $this->esc($r->getProduct()->getName());
        $link = $this->portalBase . '/vendor/customization-requests';
        $subject = "Customization quote accepted — {$r->getProduct()->getName()}";
        $body = "The customer accepted your quote to customize <strong>{$product}</strong> and is completing payment. "
            . "You'll see the request move to Paid once payment is confirmed — that's your cue to begin.";
        $bodyText = "The customer accepted your quote to customize \"{$r->getProduct()->getName()}\" and is "
            . "completing payment. You'll see the request move to Paid once payment is confirmed.";
        $this->safeSend(
            $r,
            $to,
            'customization.accepted.vendor',
            $subject,
            $this->text($bodyText, 'Open in your dashboard:', $link),
            $this->html('Quote accepted', $body, 'Open request', $link, false),
        );
    }

    /** The vendor declined → let the customer down gently. */
    public function customizationDeclined(CustomizationRequest $r): void
    {
        $customer = $r->getCustomer();
        $ar = $this->isArabic($customer);
        $to = trim($customer->getEmail());
        $product = $r->getProduct()->getName();
        $vendor = $r->getVendor()->getName();
        $reason = $r->getVendorNotes();
        $link = $this->webBase . '/account/customization-requests';

        if ($ar) {
            $subject = 'تحديث بشأن طلب التخصيص — بيتي';
            $body = "نعتذر، لا يستطيع {$this->esc($vendor)} تنفيذ تخصيص \"{$this->esc($product)}\" في الوقت الحالي.";
            $bodyText = "نعتذر، لا يستطيع {$vendor} تنفيذ تخصيص \"{$product}\" في الوقت الحالي.";
            if ($reason !== null && $reason !== '') {
                $body .= "<br><br>ملاحظة من المتجر: {$this->esc($reason)}";
                $bodyText .= "\n\nملاحظة من المتجر: {$reason}";
            }
            $cta = 'تصفّح المزيد';
        } else {
            $subject = 'Update on your customization request — 3bayti';
            $body = "We're sorry — {$this->esc($vendor)} isn't able to take on the customization of "
                . "\"{$this->esc($product)}\" right now.";
            $bodyText = "We're sorry — {$vendor} isn't able to take on the customization of \"{$product}\" right now.";
            if ($reason !== null && $reason !== '') {
                $body .= "<br><br>Note from the store: {$this->esc($reason)}";
                $bodyText .= "\n\nNote from the store: {$reason}";
            }
            $cta = 'Keep browsing';
        }

        $this->safeSend(
            $r,
            $to,
            'customization.declined.customer',
            $subject,
            $this->text($bodyText, $ar ? 'حسابك:' : 'Your account:', $link),
            $this->html($subject, $body, $cta, $link, true, $ar),
        );
    }

    /** The vendor finished the work → tell the customer it's ready. */
    public function customizationCompleted(CustomizationRequest $r): void
    {
        $customer = $r->getCustomer();
        $ar = $this->isArabic($customer);
        $to = trim($customer->getEmail());
        $product = $r->getProduct()->getName();
        $vendor = $r->getVendor()->getName();
        $link = $this->webBase . '/account/customization-requests';

        if ($ar) {
            $subject = 'تخصيصك جاهز — بيتي';
            $body = "أكمل {$this->esc($vendor)} تخصيص \"{$this->esc($product)}\". سيتواصل معك المتجر بشأن التسليم.";
            $bodyText = "أكمل {$vendor} تخصيص \"{$product}\". سيتواصل معك المتجر بشأن التسليم.";
            $cta = 'عرض التفاصيل';
        } else {
            $subject = 'Your customization is ready — 3bayti';
            $body = "{$this->esc($vendor)} has completed the customization of \"{$this->esc($product)}\". "
                . 'The store will be in touch about delivery.';
            $bodyText = "{$vendor} has completed the customization of \"{$product}\". "
                . 'The store will be in touch about delivery.';
            $cta = 'View details';
        }

        $this->safeSend(
            $r,
            $to,
            'customization.completed.customer',
            $subject,
            $this->text($bodyText, $ar ? 'التفاصيل:' : 'Details:', $link),
            $this->html($subject, $body, $cta, $link, true, $ar),
        );
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Resolve the vendor's notification email: the dedicated contact email,
     * falling back to the owner account's email. Empty string if neither.
     */
    private function vendorEmail(CustomizationRequest $r): string
    {
        $vendor = $r->getVendor();
        try {
            $contact = trim($vendor->getContactEmail());
        } catch (\Error) {
            // Typed property can be uninitialized on legacy vendor rows.
            $contact = '';
        }
        if ($contact !== '') {
            return $contact;
        }
        return trim($vendor->getOwnerUser()?->getEmail() ?? '');
    }

    private function isArabic(User $user): bool
    {
        return str_starts_with(strtolower($user->getLocale()), 'ar');
    }

    /**
     * Send + audit, swallowing every failure (email must never block the
     * lifecycle transition that triggered it).
     */
    private function safeSend(
        CustomizationRequest $r,
        string $to,
        string $template,
        string $subject,
        string $textBody,
        string $htmlBody,
    ): void {
        $to = trim($to);
        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning('customization.notification.invalid_recipient', [
                'customization_id' => $r->getId(),
                'template' => $template,
            ]);
            $this->persistLog(NotificationLog::skipped(null, $template, $to === '' ? '(empty)' : $to, 'invalid_email'));
            return;
        }

        try {
            $this->mailer->send($to, $subject, $textBody, $htmlBody, [
                'template' => $template,
                'customization_id' => $r->getId(),
            ]);
            $this->persistLog(NotificationLog::sent(null, $template, $to));
        } catch (MailerException $e) {
            $this->logger->error('customization.notification.send_failed', [
                'customization_id' => $r->getId(),
                'template' => $template,
                'error' => $e->getMessage(),
            ]);
            $this->persistLog(NotificationLog::failed(null, $template, $to, $e->kind, $e->getMessage()));
        } catch (\Throwable $e) {
            $this->logger->error('customization.notification.unexpected_error', [
                'customization_id' => $r->getId(),
                'template' => $template,
                'error' => $e->getMessage(),
            ]);
            $this->persistLog(NotificationLog::failed(null, $template, $to, $e::class, $e->getMessage()));
        }
    }

    private function persistLog(NotificationLog $log): void
    {
        if ($this->em === null) {
            return;
        }
        try {
            /** @var NotificationLogRepository $repo */
            $repo = $this->em->getRepository(NotificationLog::class);
            $repo->save($log);
        } catch (\Throwable $e) {
            $this->logger->error('customization.notification.log_persist_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Tiny inline composer (this workflow's emails are simple + link-led;
    // no order-item cards, so a minimal branded shell is enough).
    // -----------------------------------------------------------------

    private function text(string $body, string $ctaLabel, string $url): string
    {
        return $body . "\n\n" . $ctaLabel . ' ' . $url . "\n\n— 3bayti";
    }

    private function html(
        string $title,
        string $bodyHtml,
        string $ctaLabel,
        string $url,
        bool $withSupport,
        bool $ar = false,
    ): string {
        $dir = $ar ? 'rtl' : 'ltr';
        $lang = $ar ? 'ar' : 'en';
        $support = $withSupport
            ? '<p style="margin:24px 0 0;font-size:12px;color:#8a8178;">'
                . ($ar ? 'هل تحتاج مساعدة؟ راسلنا على support@3bayti.ae' : 'Need help? Contact support@3bayti.ae')
                . '</p>'
            : '';
        $safeTitle = $this->esc($title);
        $safeCta = $this->esc($ctaLabel);
        $safeUrl = $this->esc($url);

        return <<<HTML
<!DOCTYPE html>
<html lang="{$lang}" dir="{$dir}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f1ea;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f1ea;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e7ded0;">
        <tr><td style="background:#2e241c;padding:18px 28px;">
          <span style="color:#d8c9ad;font-size:18px;font-weight:700;letter-spacing:0.04em;">3bayti</span>
        </td></tr>
        <tr><td style="padding:28px;color:#2e241c;">
          <h1 style="margin:0 0 14px;font-size:19px;font-weight:600;">{$safeTitle}</h1>
          <p style="margin:0;font-size:15px;line-height:1.6;color:#4a4038;">{$bodyHtml}</p>
          <p style="margin:26px 0 0;">
            <a href="{$safeUrl}" style="display:inline-block;background:#2e241c;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:999px;font-size:14px;font-weight:600;">{$safeCta}</a>
          </p>
          {$support}
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
