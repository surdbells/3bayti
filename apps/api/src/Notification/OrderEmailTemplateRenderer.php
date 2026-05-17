<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\User\User;

/**
 * Renders email templates for order lifecycle events.
 *
 * Why a single class with a switch (vs. one class per template)
 * ==============================================================
 * Each template is ~30-50 lines of HTML + plain-text composition.
 * Splitting them into 11 separate files would add navigation
 * overhead without separation benefits — they all share the same
 * Order/OrderItem inputs and the same shared header/footer.
 *
 * If templates grow significantly (image attachments, rich
 * layouts, localization), this becomes the seam to extract.
 *
 * HTML safety
 * ===========
 * All user-supplied strings are escaped via htmlspecialchars
 * before insertion. Order references and amounts are
 * server-generated so safe by construction, but product names,
 * snapshotted from user-input product catalog, get escaped.
 *
 * Plain-text body
 * ===============
 * Required for accessibility, spam filter avoidance, and clients
 * that strip HTML. Generated alongside HTML — both bodies are
 * always non-empty.
 *
 * Localization (M3.2.X.7)
 * ========================
 * render() accepts a $locale parameter (default 'en' for
 * backwards compatibility). The match expression delegates to
 * either *En() or *Ar() private methods based on locale.
 *
 * Template method naming convention:
 *   orderPlacedCustomerEn()  → English version
 *   orderPlacedCustomerAr()  → Arabic version
 *
 * Arabic methods land in sub-phases C + D with actual translations;
 * stubs exist now (in this sub-phase B) so the match expression
 * compiles + tests cover the dispatch logic before translation
 * content lands.
 *
 * wrapHtml() also takes a locale; emits correct lang= and dir=
 * attributes on the wrapping <html> element so email clients
 * render Arabic in RTL.
 *
 * Admin templates (DISPUTE_OPENED_ADMIN) are LOCKED to English
 * regardless of locale parameter — admin emails are always
 * English per Q-VendorAdminLocale = A locked. The match
 * expression enforces this by always calling the English variant.
 */
final class OrderEmailTemplateRenderer
{
    /**
     * Render the given template against the order context.
     *
     * @param array<string, mixed> $extra Optional template-specific
     *        context (e.g. refund amount, cancellation reason).
     * @param string $locale One of User::SUPPORTED_LOCALES.
     *        Defaults to 'en' for backwards compatibility with
     *        existing callers that don't pass it. M3.2.X.7-B
     *        adds locale-aware dispatch; existing English-only
     *        behavior preserved when locale='en' or omitted.
     */
    public function render(
        EmailTemplate $template,
        Order $order,
        array $extra = [],
        string $locale = User::LOCALE_EN,
    ): RenderedEmail {
        $isArabic = ($locale === User::LOCALE_AR);

        return match ($template) {
            EmailTemplate::ORDER_PLACED_CUSTOMER => $isArabic
                ? $this->orderPlacedCustomerAr($order)
                : $this->orderPlacedCustomerEn($order),
            EmailTemplate::ORDER_PAID_CUSTOMER => $isArabic
                ? $this->orderPaidCustomerAr($order)
                : $this->orderPaidCustomerEn($order),
            EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER => $isArabic
                ? $this->orderPaymentFailedCustomerAr($order)
                : $this->orderPaymentFailedCustomerEn($order),
            EmailTemplate::ORDER_SHIPPED_CUSTOMER => $isArabic
                ? $this->orderShippedCustomerAr($order, $extra)
                : $this->orderShippedCustomerEn($order, $extra),
            EmailTemplate::ORDER_DELIVERED_CUSTOMER => $isArabic
                ? $this->orderDeliveredCustomerAr($order, $extra)
                : $this->orderDeliveredCustomerEn($order, $extra),
            EmailTemplate::ORDER_CANCELLED_CUSTOMER => $isArabic
                ? $this->orderCancelledCustomerAr($order, $extra)
                : $this->orderCancelledCustomerEn($order, $extra),
            EmailTemplate::ORDER_REFUNDED_CUSTOMER => $isArabic
                ? $this->orderRefundedCustomerAr($order, $extra)
                : $this->orderRefundedCustomerEn($order, $extra),
            EmailTemplate::ORDER_PLACED_VENDOR => $isArabic
                ? $this->orderPlacedVendorAr($order, $extra)
                : $this->orderPlacedVendorEn($order, $extra),
            EmailTemplate::ORDER_CANCELLED_VENDOR => $isArabic
                ? $this->orderCancelledVendorAr($order, $extra)
                : $this->orderCancelledVendorEn($order, $extra),
            // Admin emails are ALWAYS English regardless of locale
            // (Q-VendorAdminLocale = A locked). The locale parameter
            // is intentionally ignored here.
            EmailTemplate::DISPUTE_OPENED_ADMIN => $this->disputeOpenedAdminEn($order, $extra),
        };
    }

    // -----------------------------------------------------------------
    // Customer templates
    // -----------------------------------------------------------------

    private function orderPlacedCustomerEn(Order $order): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $total = $order->getTotal();
        $currency = $order->getCurrency();
        $itemsList = $this->itemListText($order);
        $itemsHtml = $this->itemListHtml($order);

        return new RenderedEmail(
            subject: "Order {$ref} received — 3bayti",
            textBody: <<<TXT
Thank you for your order!

Order reference: {$ref}
Total: {$total} {$currency}

Items:
{$itemsList}

We'll send another email once your payment is confirmed.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Order received",
                body: <<<HTML
<p>Thank you for your order!</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Total:</strong> {$this->esc($total)} {$this->esc($currency)}</p>
<h3>Items</h3>
{$itemsHtml}
<p>We'll send another email once your payment is confirmed.</p>
HTML,
            ),
        );
    }

    private function orderPaidCustomerEn(Order $order): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $total = $order->getTotal();
        $currency = $order->getCurrency();

        return new RenderedEmail(
            subject: "Payment confirmed for order {$ref} — 3bayti",
            textBody: <<<TXT
Your payment has been confirmed.

Order reference: {$ref}
Amount: {$total} {$currency}

We're preparing your order now. You'll get another email once it ships.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Payment confirmed",
                body: <<<HTML
<p>Your payment has been confirmed.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Amount:</strong> {$this->esc($total)} {$this->esc($currency)}</p>
<p>We're preparing your order now. You'll get another email once it ships.</p>
HTML,
            ),
        );
    }

    private function orderPaymentFailedCustomerEn(Order $order): RenderedEmail
    {
        $ref = $order->getOrderReference();

        return new RenderedEmail(
            subject: "Payment failed for order {$ref} — 3bayti",
            textBody: <<<TXT
We weren't able to process the payment for your order.

Order reference: {$ref}

You can try again from the app, or contact our support team if you
need help.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Payment failed",
                body: <<<HTML
<p>We weren't able to process the payment for your order.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}</p>
<p>You can try again from the app, or contact our support team if you need help.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderShippedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'Your item');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "Your order {$ref} has shipped — 3bayti",
            textBody: <<<TXT
Good news — an item from your order is on its way.

Order reference: {$ref}
Item: {$itemName}

You'll receive another email when it's delivered.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Order shipped",
                body: <<<HTML
<p>Good news — an item from your order is on its way.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Item:</strong> {$itemNameEsc}</p>
<p>You'll receive another email when it's delivered.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderDeliveredCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'Your item');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "Order {$ref} delivered — 3bayti",
            textBody: <<<TXT
An item from your order has been delivered.

Order reference: {$ref}
Item: {$itemName}

We hope you enjoy it. If there's anything wrong, contact us within
7 days and we'll make it right.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Order delivered",
                body: <<<HTML
<p>An item from your order has been delivered.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Item:</strong> {$itemNameEsc}</p>
<p>We hope you enjoy it. If there's anything wrong, contact us within 7 days and we'll make it right.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderCancelledCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $refundIssued = (bool) ($extra['refund_issued'] ?? false);
        $refundAmount = $extra['refund_amount'] ?? null;
        $currency = $order->getCurrency();

        $refundLineText = $refundIssued && is_string($refundAmount)
            ? "A refund of {$refundAmount} {$currency} has been issued."
            : "No payment was charged.";
        $refundLineHtml = $refundIssued && is_string($refundAmount)
            ? "A refund of <strong>{$this->esc($refundAmount)} {$this->esc($currency)}</strong> has been issued."
            : "No payment was charged.";

        return new RenderedEmail(
            subject: "Order {$ref} cancelled — 3bayti",
            textBody: <<<TXT
Your order has been cancelled.

Order reference: {$ref}
{$refundLineText}

If this wasn't you, contact our support team right away.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Order cancelled",
                body: <<<HTML
<p>Your order has been cancelled.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
{$refundLineHtml}</p>
<p>If this wasn't you, contact our support team right away.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderRefundedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $amount = (string) ($extra['refund_amount'] ?? $order->getTotal());
        $isFull = (bool) ($extra['is_full_refund'] ?? false);
        $currency = $order->getCurrency();
        $kindText = $isFull ? 'full' : 'partial';

        return new RenderedEmail(
            subject: "Refund issued for order {$ref} — 3bayti",
            textBody: <<<TXT
We've issued a {$kindText} refund for your order.

Order reference: {$ref}
Refund amount: {$amount} {$currency}

Funds typically appear within 5-10 business days, depending on your
bank.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Refund issued",
                body: <<<HTML
<p>We've issued a {$this->esc($kindText)} refund for your order.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Refund amount:</strong> {$this->esc($amount)} {$this->esc($currency)}</p>
<p>Funds typically appear within 5-10 business days, depending on your bank.</p>
HTML,
            ),
        );
    }

    // -----------------------------------------------------------------
    // Vendor templates
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     */
    private function orderPlacedVendorEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $vendorItems = $extra['vendor_items'] ?? [];
        $itemsList = '';
        $itemsHtml = '';
        if (is_array($vendorItems)) {
            foreach ($vendorItems as $name) {
                $nameStr = (string) $name;
                $itemsList .= "  - {$nameStr}\n";
                $itemsHtml .= '<li>' . $this->esc($nameStr) . '</li>';
            }
        }

        return new RenderedEmail(
            subject: "New order {$ref} — items to prepare",
            textBody: <<<TXT
You have a new order to fulfil.

Order reference: {$ref}

Your items:
{$itemsList}
Please mark each item as 'accepted' and then 'shipped' from the
vendor dashboard once you've packed and dispatched.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "New order to prepare",
                body: <<<HTML
<p>You have a new order to fulfil.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}</p>
<h3>Your items</h3>
<ul>{$itemsHtml}</ul>
<p>Please mark each item as 'accepted' and then 'shipped' from the vendor dashboard once you've packed and dispatched.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderCancelledVendorEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $reason = (string) ($extra['reason'] ?? '');
        $reasonLine = $reason !== '' ? "Reason: {$reason}" : '';
        $reasonLineHtml = $reason !== '' ? "<p><strong>Reason:</strong> {$this->esc($reason)}</p>" : '';

        return new RenderedEmail(
            subject: "Order {$ref} cancelled — do not ship",
            textBody: <<<TXT
An order containing items from your store has been cancelled.

Order reference: {$ref}
{$reasonLine}

Do not ship these items. If they're already in transit, contact
support immediately.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Order cancelled — do not ship",
                body: <<<HTML
<p>An order containing items from your store has been cancelled.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}</p>
{$reasonLineHtml}
<p><strong>Do not ship these items.</strong> If they're already in transit, contact support immediately.</p>
HTML,
            ),
        );
    }

    // -----------------------------------------------------------------
    // Admin templates
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     */
    private function disputeOpenedAdminEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $amount = (string) ($extra['amount'] ?? $order->getTotal());
        $currency = (string) ($extra['currency'] ?? $order->getCurrency());
        $eventType = (string) ($extra['event_type'] ?? 'DISPUTE');
        $reason = (string) ($extra['reason'] ?? '');
        $reasonLine = $reason !== '' ? "Reason: {$reason}\n" : '';
        $reasonHtml = $reason !== '' ? "<p><strong>Reason:</strong> {$this->esc($reason)}</p>" : '';

        return new RenderedEmail(
            subject: "[ALERT] Dispute opened on order {$ref}",
            textBody: <<<TXT
A new payment dispute has been opened.

Order reference: {$ref}
Event type: {$eventType}
Amount: {$amount} {$currency}
{$reasonLine}
Review and respond in the admin dashboard:
https://3bayti.ae/admin/disputes

— 3bayti operations
TXT,
            htmlBody: $this->wrapHtml(
                title: "Dispute opened",
                body: <<<HTML
<p>A new payment dispute has been opened.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Event type:</strong> {$this->esc($eventType)}<br>
<strong>Amount:</strong> {$this->esc($amount)} {$this->esc($currency)}</p>
{$reasonHtml}
<p>Review and respond in the <a href="https://3bayti.ae/admin/disputes">admin dashboard</a>.</p>
HTML,
            ),
        );
    }

    // -----------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------

    private function itemListText(Order $order): string
    {
        $out = '';
        foreach ($order->getItems() as $item) {
            /** @var OrderItem $item */
            $name = $item->getProductNameSnapshot();
            $qty = $item->getQuantity();
            $price = $item->getUnitPrice();
            $out .= "  - {$name} (x{$qty}) — {$price}\n";
        }
        return $out;
    }

    private function itemListHtml(Order $order): string
    {
        $out = '<ul>';
        foreach ($order->getItems() as $item) {
            /** @var OrderItem $item */
            $name = $this->esc($item->getProductNameSnapshot());
            $qty = $item->getQuantity();
            $price = $this->esc($item->getUnitPrice());
            $out .= "<li>{$name} (x{$qty}) — {$price}</li>";
        }
        $out .= '</ul>';
        return $out;
    }

    /**
     * Wrap the body content in a minimal HTML envelope with branded
     * header + footer. Keeps templates focused on content, not
     * markup boilerplate.
     */
    /**
     * Wrap a body in the standard 3bayti email HTML scaffold.
     *
     * Emits proper lang= and dir= attributes on the wrapping <html>
     * element so email clients render Arabic in RTL (right-to-left).
     * Without these, Outlook and some webmail clients render Arabic
     * left-to-right which is visually broken.
     *
     * The brand name "3bayti" stays in Latin script (consistent
     * brand identity); the locale-specific tagline localizes.
     */
    private function wrapHtml(string $title, string $body, string $locale = User::LOCALE_EN): string
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

    // -----------------------------------------------------------------
    // Arabic templates — customer-facing (M3.2.X.7-C)
    // -----------------------------------------------------------------
    //
    // Modern Standard Arabic (MSA), formal/respectful register.
    // Translation notes:
    //   - Order references + product names preserve their original
    //     Latin script per UAE business convention (regional norm
    //     for commercial documents; recipients expect to see the
    //     same order reference they'd see on the web/mobile app)
    //   - Currency 'د.إ' (AED in Arabic) when locale=ar; 'AED' on
    //     the English side. The numeric value itself stays Western
    //     Arabic numerals (1,2,3 not ١,٢,٣) — UAE business convention
    //     for clarity in commercial contexts
    //   - Email signature '— 3bayti' kept in Latin for consistent
    //     brand identity (matches wrapHtml's tagline pattern)
    //   - HTML structure mirrors the English templates so RTL
    //     rendering flows naturally from the dir="rtl" on <html>
    //
    // OPERATOR FOLLOW-UP: a native Arabic reviewer pass before
    // production is recommended (documented in closure runbook).
    // The translations are formal MSA — unlikely to land badly but
    // a polish pass adds confidence. Not a hard blocker.

    private function orderPlacedCustomerAr(Order $order): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $total = $order->getTotal();
        $currency = $order->getCurrency();
        $itemsList = $this->itemListText($order);
        $itemsHtml = $this->itemListHtml($order);

        return new RenderedEmail(
            subject: "تم استلام طلبك {$ref} — 3bayti",
            textBody: <<<TXT
شكراً لطلبك!

رقم الطلب: {$ref}
المجموع: {$total} {$currency}

العناصر:
{$itemsList}

سنرسل لك بريداً إلكترونياً آخر بمجرد تأكيد الدفع.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم استلام طلبك',
                body: <<<HTML
<p>شكراً لطلبك!</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>المجموع:</strong> {$this->esc($total)} {$this->esc($currency)}</p>
<h3>العناصر</h3>
{$itemsHtml}
<p>سنرسل لك بريداً إلكترونياً آخر بمجرد تأكيد الدفع.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    private function orderPaidCustomerAr(Order $order): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $total = $order->getTotal();
        $currency = $order->getCurrency();

        return new RenderedEmail(
            subject: "تأكيد الدفع للطلب {$ref} — 3bayti",
            textBody: <<<TXT
تم تأكيد دفعتك بنجاح.

رقم الطلب: {$ref}
المبلغ: {$total} {$currency}

نقوم الآن بتجهيز طلبك. ستصلك رسالة أخرى عند شحنه.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تأكيد الدفع',
                body: <<<HTML
<p>تم تأكيد دفعتك بنجاح.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>المبلغ:</strong> {$this->esc($total)} {$this->esc($currency)}</p>
<p>نقوم الآن بتجهيز طلبك. ستصلك رسالة أخرى عند شحنه.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    private function orderPaymentFailedCustomerAr(Order $order): RenderedEmail
    {
        $ref = $order->getOrderReference();

        return new RenderedEmail(
            subject: "تعذّر إتمام الدفع للطلب {$ref} — 3bayti",
            textBody: <<<TXT
لم نتمكن من معالجة دفعة طلبك.

رقم الطلب: {$ref}

يمكنك المحاولة مجدداً من التطبيق، أو التواصل مع فريق الدعم لدينا
إذا احتجت إلى المساعدة.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تعذّر إتمام الدفع',
                body: <<<HTML
<p>لم نتمكن من معالجة دفعة طلبك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}</p>
<p>يمكنك المحاولة مجدداً من التطبيق، أو التواصل مع فريق الدعم لدينا إذا احتجت إلى المساعدة.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderShippedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'منتجك');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "تم شحن طلبك {$ref} — 3bayti",
            textBody: <<<TXT
خبر سار — أحد منتجات طلبك في طريقه إليك.

رقم الطلب: {$ref}
المنتج: {$itemName}

سيصلك بريد إلكتروني آخر عند تسليمه.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم شحن طلبك',
                body: <<<HTML
<p>خبر سار — أحد منتجات طلبك في طريقه إليك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>المنتج:</strong> {$itemNameEsc}</p>
<p>سيصلك بريد إلكتروني آخر عند تسليمه.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderDeliveredCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'منتجك');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "تم تسليم طلبك {$ref} — 3bayti",
            textBody: <<<TXT
تم تسليم أحد منتجات طلبك.

رقم الطلب: {$ref}
المنتج: {$itemName}

نتمنى أن ينال إعجابك. إذا كان هناك أي خلل، تواصل معنا خلال
7 أيام وسنعالج الأمر.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم تسليم طلبك',
                body: <<<HTML
<p>تم تسليم أحد منتجات طلبك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>المنتج:</strong> {$itemNameEsc}</p>
<p>نتمنى أن ينال إعجابك. إذا كان هناك أي خلل، تواصل معنا خلال 7 أيام وسنعالج الأمر.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderCancelledCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $refundIssued = (bool) ($extra['refund_issued'] ?? false);
        $refundAmount = $extra['refund_amount'] ?? null;
        $currency = $order->getCurrency();

        $refundLineText = $refundIssued && is_string($refundAmount)
            ? "تم إصدار استرداد بمبلغ {$refundAmount} {$currency}."
            : 'لم يتم تحصيل أي مبلغ.';
        $refundLineHtml = $refundIssued && is_string($refundAmount)
            ? "تم إصدار استرداد بمبلغ <strong>{$this->esc($refundAmount)} {$this->esc($currency)}</strong>."
            : 'لم يتم تحصيل أي مبلغ.';

        return new RenderedEmail(
            subject: "تم إلغاء طلبك {$ref} — 3bayti",
            textBody: <<<TXT
تم إلغاء طلبك.

رقم الطلب: {$ref}
{$refundLineText}

إذا لم تكن أنت من قام بذلك، تواصل مع فريق الدعم لدينا فوراً.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم إلغاء طلبك',
                body: <<<HTML
<p>تم إلغاء طلبك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
{$refundLineHtml}</p>
<p>إذا لم تكن أنت من قام بذلك، تواصل مع فريق الدعم لدينا فوراً.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderRefundedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $amount = (string) ($extra['refund_amount'] ?? $order->getTotal());
        $isFull = (bool) ($extra['is_full_refund'] ?? false);
        $currency = $order->getCurrency();
        $kindText = $isFull ? 'كامل' : 'جزئي';

        return new RenderedEmail(
            subject: "إصدار استرداد للطلب {$ref} — 3bayti",
            textBody: <<<TXT
قمنا بإصدار استرداد {$kindText} لطلبك.

رقم الطلب: {$ref}
مبلغ الاسترداد: {$amount} {$currency}

تظهر الأموال عادةً خلال 5 إلى 10 أيام عمل، حسب البنك الذي تتعامل معه.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'إصدار استرداد',
                body: <<<HTML
<p>قمنا بإصدار استرداد {$this->esc($kindText)} لطلبك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>مبلغ الاسترداد:</strong> {$this->esc($amount)} {$this->esc($currency)}</p>
<p>تظهر الأموال عادةً خلال 5 إلى 10 أيام عمل، حسب البنك الذي تتعامل معه.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    // -----------------------------------------------------------------
    // Arabic templates — vendor-facing (M3.2.X.7-D)
    // -----------------------------------------------------------------
    //
    // Vendor users opt in via Vendor.preferredLocale = 'ar'. Same
    // translation conventions as customer templates (UAE business
    // norms: Latin order references, Western Arabic numerals, etc.).
    //
    // No admin-facing Arabic template exists — admin notifications are
    // ALWAYS English per Q-VendorAdminLocale = A locked. The renderer's
    // match expression enforces this by always dispatching admin
    // templates to disputeOpenedAdminEn regardless of locale.

    /**
     * @param array<string, mixed> $extra
     */
    private function orderPlacedVendorAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $vendorItems = $extra['vendor_items'] ?? [];
        $itemsList = '';
        $itemsHtml = '';
        if (is_array($vendorItems)) {
            foreach ($vendorItems as $name) {
                $nameStr = (string) $name;
                $itemsList .= "  - {$nameStr}\n";
                $itemsHtml .= '<li>' . $this->esc($nameStr) . '</li>';
            }
        }

        return new RenderedEmail(
            subject: "طلب جديد {$ref} — منتجات للتجهيز",
            textBody: <<<TXT
لديك طلب جديد للتجهيز.

رقم الطلب: {$ref}

منتجاتك:
{$itemsList}
يرجى تعليم كل منتج بحالة 'مقبول' ثم 'تم الشحن' من لوحة تحكم
البائع بعد تجهيزه وشحنه.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'طلب جديد للتجهيز',
                body: <<<HTML
<p>لديك طلب جديد للتجهيز.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}</p>
<h3>منتجاتك</h3>
<ul>{$itemsHtml}</ul>
<p>يرجى تعليم كل منتج بحالة 'مقبول' ثم 'تم الشحن' من لوحة تحكم البائع بعد تجهيزه وشحنه.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderCancelledVendorAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $reason = (string) ($extra['reason'] ?? '');
        $reasonLine = $reason !== '' ? "السبب: {$reason}" : '';
        $reasonLineHtml = $reason !== ''
            ? "<p><strong>السبب:</strong> {$this->esc($reason)}</p>"
            : '';

        return new RenderedEmail(
            subject: "تم إلغاء الطلب {$ref} — لا تقم بالشحن",
            textBody: <<<TXT
تم إلغاء طلب يحتوي على منتجات من متجرك.

رقم الطلب: {$ref}
{$reasonLine}

لا تقم بشحن هذه المنتجات. إذا كانت قد شُحنت بالفعل، تواصل مع
فريق الدعم فوراً.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم إلغاء الطلب — لا تقم بالشحن',
                body: <<<HTML
<p>تم إلغاء طلب يحتوي على منتجات من متجرك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}</p>
{$reasonLineHtml}
<p><strong>لا تقم بشحن هذه المنتجات.</strong> إذا كانت قد شُحنت بالفعل، تواصل مع فريق الدعم فوراً.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }
}
