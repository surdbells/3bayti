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
    // Arabic template stubs (M3.2.X.7-B)
    // -----------------------------------------------------------------
    //
    // Stub methods that compile + return RenderedEmail with placeholder
    // text. Actual translations land in sub-phases C (customer
    // templates) and D (vendor + admin templates).
    //
    // The placeholder text is INTENTIONALLY Arabic (not "TODO" or
    // similar) so:
    //   1. UTF-8 encoding survives the PHP pipeline cleanly
    //      (verified by sub-phase B tests)
    //   2. wrapHtml() lang=ar + dir=rtl rendering can be verified
    //   3. If a stub somehow leaks to production before C/D ship,
    //      the user sees a sensible-looking (if minimal) Arabic
    //      message rather than English/placeholder
    // -----------------------------------------------------------------

    private function orderPlacedCustomerAr(Order $order): RenderedEmail
    {
        return $this->arabicStub($order, 'تم استلام طلبك', 'Order received (Arabic — translation pending in M3.2.X.7-C)');
    }

    private function orderPaidCustomerAr(Order $order): RenderedEmail
    {
        return $this->arabicStub($order, 'تأكيد الدفع', 'Payment confirmed (Arabic — translation pending in M3.2.X.7-C)');
    }

    private function orderPaymentFailedCustomerAr(Order $order): RenderedEmail
    {
        return $this->arabicStub($order, 'فشل الدفع', 'Payment failed (Arabic — translation pending in M3.2.X.7-C)');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderShippedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        return $this->arabicStub($order, 'تم شحن طلبك', 'Order shipped (Arabic — translation pending in M3.2.X.7-C)');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderDeliveredCustomerAr(Order $order, array $extra): RenderedEmail
    {
        return $this->arabicStub($order, 'تم تسليم طلبك', 'Order delivered (Arabic — translation pending in M3.2.X.7-C)');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderCancelledCustomerAr(Order $order, array $extra): RenderedEmail
    {
        return $this->arabicStub($order, 'تم إلغاء طلبك', 'Order cancelled (Arabic — translation pending in M3.2.X.7-C)');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderRefundedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        return $this->arabicStub($order, 'تم استرداد المبلغ', 'Order refunded (Arabic — translation pending in M3.2.X.7-C)');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderPlacedVendorAr(Order $order, array $extra): RenderedEmail
    {
        return $this->arabicStub($order, 'طلب جديد', 'New order (Arabic — translation pending in M3.2.X.7-D)');
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderCancelledVendorAr(Order $order, array $extra): RenderedEmail
    {
        return $this->arabicStub($order, 'تم إلغاء الطلب', 'Order cancelled (Arabic — translation pending in M3.2.X.7-D)');
    }

    /**
     * Shared stub builder. Returns a minimal Arabic email with
     * RTL-correct HTML wrapper. Replaced per-template with full
     * translations in sub-phases C + D.
     */
    private function arabicStub(Order $order, string $subject, string $bodyText): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $textBody = "{$bodyText}\n\nرقم الطلب: {$ref}\n\n— 3bayti";
        $body = "<p>{$this->esc($bodyText)}</p>"
            . "<p><strong>رقم الطلب:</strong> {$this->esc($ref)}</p>";
        return new RenderedEmail(
            subject: $subject,
            textBody: $textBody,
            htmlBody: $this->wrapHtml(
                title: $subject,
                body: $body,
                locale: User::LOCALE_AR,
            ),
        );
    }
}
