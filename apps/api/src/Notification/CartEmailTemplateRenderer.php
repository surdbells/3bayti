<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\User\User;

/**
 * Render cart-scoped email templates (M3.2.X.11-D).
 *
 * Parallel to OrderEmailTemplateRenderer; takes a Cart instead
 * of an Order. The two classes don't share an interface because
 * the inputs are fundamentally different domain objects — a
 * common interface would either be Order-shaped (broken for cart
 * use) or so generic it loses type safety. Consolidation can
 * happen later if a third notification context (e.g. wishlist
 * reminders, vendor onboarding sequences) appears.
 *
 * Locale dispatch mirrors the order renderer (EN default, AR
 * when locale = User::LOCALE_AR). RTL HTML wrap via local
 * wrapHtml helper.
 *
 * Q-RenderingApproach = B locked: parallel class chosen over
 * extending OrderEmailTemplateRenderer (1650+ lines tightly
 * coupled to Order entity shape).
 *
 * Required $extra keys
 * ====================
 *   unsubscribe_url  — full https URL with signed token, built
 *                       by CartNotificationService (X.11-E) via
 *                       the UnsubscribeTokenIssuer (X.11-G)
 *
 * Optional $extra keys
 * ====================
 *   resume_url       — link back to the cart on the web/mobile
 *                       app. If omitted, no CTA button rendered.
 *                       Default is the cart-restoration URL
 *                       https://3bayti.ae/cart?cart_id=...
 */
final class CartEmailTemplateRenderer
{
    /**
     * @param array<string, mixed> $extra
     */
    public function render(
        EmailTemplate $template,
        Cart $cart,
        array $extra = [],
        string $locale = User::LOCALE_EN,
    ): RenderedEmail {
        $isArabic = ($locale === User::LOCALE_AR);

        return match ($template) {
            EmailTemplate::CART_ABANDONED_CUSTOMER => $isArabic
                ? $this->cartAbandonedCustomerAr($cart, $extra)
                : $this->cartAbandonedCustomerEn($cart, $extra),
            // Defensive: anything else routed here is a wiring bug.
            // The order renderer handles all other templates.
            default => throw new \LogicException(
                'Non-cart template ' . $template->value
                . ' must be rendered by OrderEmailTemplateRenderer.',
            ),
        };
    }

    // =================================================================
    // English
    // =================================================================

    /**
     * @param array<string, mixed> $extra
     */
    private function cartAbandonedCustomerEn(Cart $cart, array $extra): RenderedEmail
    {
        $itemCount = $cart->getItems()->count();
        $itemNoun = $itemCount === 1 ? 'item' : 'items';
        $subject = "You left {$itemCount} {$itemNoun} in your cart — 3bayti";

        $itemListText = $this->itemListText($cart);
        $itemListHtml = $this->itemListHtml($cart);

        $unsubscribeUrl = $this->extractString($extra, 'unsubscribe_url');
        $resumeUrl = $this->extractString($extra, 'resume_url');

        $resumeText = $resumeUrl !== ''
            ? "Resume your cart: {$resumeUrl}\n\n"
            : '';
        $resumeHtml = $resumeUrl !== ''
            ? sprintf(
                '<p style="text-align: center; margin: 32px 0;">'
                . '<a href="%s" style="background: #B9935A; color: #ffffff; padding: 12px 24px; '
                . 'text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 600;">'
                . 'Resume Your Cart</a></p>',
                $this->esc($resumeUrl),
            )
            : '';

        $textBody = <<<TXT
We noticed you left some items in your cart on 3bayti.

Your cart:
{$itemListText}

{$resumeText}Your selections are saved and ready when you return.

—

If you no longer wish to receive these reminders, you can unsubscribe here:
{$unsubscribeUrl}

— 3bayti
TXT;

        $htmlBody = <<<HTML
<p>We noticed you left some items in your cart on 3bayti.</p>
<p style="font-weight: 600; margin-top: 24px;">Your cart:</p>
{$itemListHtml}
{$resumeHtml}
<p>Your selections are saved and ready when you return.</p>
<p style="font-size: 12px; color: #8e8e93; margin-top: 32px;">
  If you no longer wish to receive these reminders,
  <a href="{$this->esc($unsubscribeUrl)}" style="color: #8e8e93;">unsubscribe here</a>.
</p>
HTML;

        return new RenderedEmail(
            subject: $subject,
            textBody: $textBody,
            htmlBody: $this->wrapHtml($subject, $htmlBody, User::LOCALE_EN),
        );
    }

    // =================================================================
    // Arabic
    // =================================================================

    /**
     * @param array<string, mixed> $extra
     */
    private function cartAbandonedCustomerAr(Cart $cart, array $extra): RenderedEmail
    {
        $itemCount = $cart->getItems()->count();
        $itemNoun = $itemCount === 1 ? 'منتج' : 'منتجات';
        $subject = "لقد تركت {$itemCount} {$itemNoun} في سلتك — 3bayti";

        $itemListText = $this->itemListText($cart);
        $itemListHtml = $this->itemListHtml($cart);

        $unsubscribeUrl = $this->extractString($extra, 'unsubscribe_url');
        $resumeUrl = $this->extractString($extra, 'resume_url');

        $resumeText = $resumeUrl !== ''
            ? "استأنف سلتك: {$resumeUrl}\n\n"
            : '';
        $resumeHtml = $resumeUrl !== ''
            ? sprintf(
                '<p style="text-align: center; margin: 32px 0;">'
                . '<a href="%s" style="background: #B9935A; color: #ffffff; padding: 12px 24px; '
                . 'text-decoration: none; border-radius: 6px; display: inline-block; font-weight: 600;">'
                . 'استأنف سلتك</a></p>',
                $this->esc($resumeUrl),
            )
            : '';

        $textBody = <<<TXT
لاحظنا أنك تركت بعض المنتجات في سلتك على 3bayti.

سلتك:
{$itemListText}

{$resumeText}اختياراتك محفوظة وجاهزة عند عودتك.

—

إذا كنت لا ترغب في تلقي هذه التذكيرات، يمكنك إلغاء الاشتراك هنا:
{$unsubscribeUrl}

— 3bayti
TXT;

        $htmlBody = <<<HTML
<p>لاحظنا أنك تركت بعض المنتجات في سلتك على 3bayti.</p>
<p style="font-weight: 600; margin-top: 24px;">سلتك:</p>
{$itemListHtml}
{$resumeHtml}
<p>اختياراتك محفوظة وجاهزة عند عودتك.</p>
<p style="font-size: 12px; color: #8e8e93; margin-top: 32px;">
  إذا كنت لا ترغب في تلقي هذه التذكيرات،
  <a href="{$this->esc($unsubscribeUrl)}" style="color: #8e8e93;">يمكنك إلغاء الاشتراك هنا</a>.
</p>
HTML;

        return new RenderedEmail(
            subject: $subject,
            textBody: $textBody,
            htmlBody: $this->wrapHtml($subject, $htmlBody, User::LOCALE_AR),
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function itemListText(Cart $cart): string
    {
        $lines = [];
        foreach ($cart->getItems() as $item) {
            $lines[] = sprintf(
                '- %s x %d',
                $this->itemDisplayName($item),
                $item->getQuantity(),
            );
        }
        return implode("\n", $lines);
    }

    private function itemListHtml(Cart $cart): string
    {
        $items = '';
        foreach ($cart->getItems() as $item) {
            $items .= sprintf(
                '<li style="padding: 4px 0;">%s <span style="color: #8e8e93;">×%d</span></li>',
                $this->esc($this->itemDisplayName($item)),
                $item->getQuantity(),
            );
        }
        return '<ul style="padding-inline-start: 20px;">' . $items . '</ul>';
    }

    private function itemDisplayName(CartItem $item): string
    {
        try {
            return $item->getProduct()->getName();
        } catch (\Throwable) {
            // Defensive — the product reference could be missing in
            // tests or after a product hard-delete. Fall back to a
            // neutral label rather than throwing during email render.
            return 'item';
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function extractString(array $extra, string $key): string
    {
        $v = $extra[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    private function wrapHtml(string $title, string $body, string $locale): string
    {
        $titleEsc = $this->esc($title);
        $dir = ($locale === User::LOCALE_AR) ? 'rtl' : 'ltr';
        $tagline = ($locale === User::LOCALE_AR)
            ? '3bayti — السوق الإلكتروني المتميز في الإمارات'
            : '3bayti — premium UAE marketplace';
        $taglineEsc = $this->esc($tagline);
        return <<<HTML
<!DOCTYPE html>
<html lang="{$locale}" dir="{$dir}">
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
  <p style="font-size: 12px; color: #8e8e93;">{$taglineEsc}</p>
</body>
</html>
HTML;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
