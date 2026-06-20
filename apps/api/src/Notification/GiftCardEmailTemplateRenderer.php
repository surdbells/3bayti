<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\GiftCard\GiftCard;

/**
 * Renders the recipient delivery email for a gift card.
 *
 * Mirrors OrderEmailTemplateRenderer's conventions:
 *   - render() returns a RenderedEmail{subject, textBody, htmlBody}
 *   - wrapHtml() supplies the branded HTML envelope
 *   - esc() escapes every user-supplied string before insertion
 *   - both text + html bodies are always non-empty
 *
 * The card is presented in a theme-aware "card" panel (accent colour
 * picked per theme) with the recipient greeting, who it's from (buyer's
 * first name), the amount, the personal message, the formatted code,
 * and clear redeem instructions + links.
 *
 * HTML safety
 * ===========
 * recipient_name, the buyer first name, and recipient_message are
 * user-supplied and escaped via esc(). The formatted code + amount +
 * currency are server-generated and safe by construction, but are
 * escaped anyway for defence in depth.
 */
final class GiftCardEmailTemplateRenderer
{
    private const REDEEM_URL = 'https://3bayti.ae/account/gift-cards';

    /**
     * Per-theme accent colour for the card panel. Kept in sync with
     * GiftCardSerializer::THEME_META accent_color values. Falls back to
     * the brand gold for unknown themes.
     */
    private const THEME_ACCENT = [
        GiftCard::THEME_BIRTHDAY   => '#E8C040',
        GiftCard::THEME_WEDDING    => '#D4AF37',
        GiftCard::THEME_EID        => '#D4AF37',
        GiftCard::THEME_MOTHER     => '#D4AF37',
        GiftCard::THEME_GRADUATION => '#D4AF37',
        GiftCard::THEME_LUXURY     => '#E8C040',
    ];

    private const THEME_BASE = [
        GiftCard::THEME_BIRTHDAY   => '#3A1A00',
        GiftCard::THEME_WEDDING    => '#260014',
        GiftCard::THEME_EID        => '#002A12',
        GiftCard::THEME_MOTHER     => '#22001A',
        GiftCard::THEME_GRADUATION => '#000C26',
        GiftCard::THEME_LUXURY     => '#1A1200',
    ];

    public function render(GiftCard $card): RenderedEmail
    {
        $recipientName = $card->getRecipientName() ?: 'there';
        $buyerFirst    = $card->getBuyerUser()->getFirstName() ?: 'A friend';
        $amount        = $card->getDenomination();
        $currency      = $card->getCurrency();
        $code          = $card->formattedCode();
        $message       = $card->getRecipientMessage();

        $accent = self::THEME_ACCENT[$card->getTheme()] ?? '#B9935A';
        $base   = self::THEME_BASE[$card->getTheme()] ?? '#1A1200';
        $redeemUrl = self::REDEEM_URL;

        // ── Plain-text body ──────────────────────────────────────────
        $messageBlockText = $message !== null && $message !== ''
            ? "\nA message from {$buyerFirst}:\n\"{$message}\"\n"
            : '';

        $textBody = <<<TXT
Hi {$recipientName},

{$buyerFirst} has sent you a 3bayti gift card worth {$amount} {$currency}!
{$messageBlockText}
Your gift card code:
{$code}

How to redeem:
  - In the 3bayti app: go to Gifts -> Redeem and enter the code above.
  - On the web: visit {$redeemUrl} and enter the code while signed in.

Your gift card is valid for 12 months and can be used across the whole
3bayti marketplace.

Enjoy!

— 3bayti
TXT;

        // ── HTML body ────────────────────────────────────────────────
        $messageBlockHtml = $message !== null && $message !== ''
            ? '<p style="margin:16px 0;"><em>A message from ' . $this->esc($buyerFirst)
              . ':</em><br>&ldquo;' . $this->esc($message) . '&rdquo;</p>'
            : '';

        $cardPanel = <<<HTML
<div style="background:{$base};border:2px solid {$accent};border-radius:12px;padding:24px;text-align:center;margin:24px 0;">
  <div style="color:{$accent};font-size:13px;letter-spacing:2px;text-transform:uppercase;">3bayti Gift Card</div>
  <div style="color:#FFFFFF;font-size:34px;font-weight:700;margin:12px 0;">{$this->esc($amount)} {$this->esc($currency)}</div>
  <div style="color:{$accent};font-size:22px;font-family:'Courier New',monospace;letter-spacing:3px;margin-top:8px;">{$this->esc($code)}</div>
</div>
HTML;

        $htmlBody = $this->wrapHtml(
            title: 'You\'ve received a gift card',
            body: <<<HTML
<p>Hi {$this->esc($recipientName)},</p>
<p><strong>{$this->esc($buyerFirst)}</strong> has sent you a 3bayti gift card!</p>
{$cardPanel}
{$messageBlockHtml}
<h3>How to redeem</h3>
<ul>
  <li>In the <strong>3bayti app</strong>: go to <strong>Gifts &rarr; Redeem</strong> and enter the code above.</li>
  <li>On the web: visit <a href="{$redeemUrl}">{$redeemUrl}</a> and enter the code while signed in.</li>
</ul>
<p>Your gift card is valid for <strong>12 months</strong> and can be used across the whole 3bayti marketplace.</p>
<p>Enjoy!</p>
HTML,
        );

        return new RenderedEmail(
            subject: 'You\'ve received a 3bayti gift card',
            textBody: $textBody,
            htmlBody: $htmlBody,
        );
    }

    private function wrapHtml(string $title, string $body): string
    {
        $titleEsc = $this->esc($title);
        return <<<HTML
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<title>{$titleEsc}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; color: #1c1c1e;">
  <div style="border-bottom: 2px solid #B9935A; padding-bottom: 12px; margin-bottom: 24px;">
    <h2 style="margin: 0; color: #B9935A;">3bayti</h2>
  </div>
  <h1 style="font-size: 20px; margin-top: 0;">{$titleEsc}</h1>
  {$body}
  <hr style="border: none; border-top: 1px solid #e5e5e7; margin-top: 32px;">
  <p style="font-size: 12px; color: #8e8e93;">3bayti — premium UAE marketplace</p>
</body>
</html>
HTML;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
