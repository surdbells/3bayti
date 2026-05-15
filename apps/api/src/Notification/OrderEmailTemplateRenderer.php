<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;

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
 * Localization
 * ============
 * Templates are English-only in M3.1.7. Arabic and other locales
 * deferred to a future I18n phase. The strings here are the
 * source of truth pending that work.
 */
final class OrderEmailTemplateRenderer
{
    /**
     * Render the given template against the order context.
     *
     * @param array<string, mixed> $extra Optional template-specific
     *        context (e.g. refund amount, cancellation reason).
     */
    public function render(
        EmailTemplate $template,
        Order $order,
        array $extra = [],
    ): RenderedEmail {
        return match ($template) {
            EmailTemplate::ORDER_PLACED_CUSTOMER => $this->orderPlacedCustomer($order),
            EmailTemplate::ORDER_PAID_CUSTOMER => $this->orderPaidCustomer($order),
            EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER => $this->orderPaymentFailedCustomer($order),
            EmailTemplate::ORDER_SHIPPED_CUSTOMER => $this->orderShippedCustomer($order, $extra),
            EmailTemplate::ORDER_DELIVERED_CUSTOMER => $this->orderDeliveredCustomer($order, $extra),
            EmailTemplate::ORDER_CANCELLED_CUSTOMER => $this->orderCancelledCustomer($order, $extra),
            EmailTemplate::ORDER_REFUNDED_CUSTOMER => $this->orderRefundedCustomer($order, $extra),
            EmailTemplate::ORDER_PLACED_VENDOR => $this->orderPlacedVendor($order, $extra),
            EmailTemplate::ORDER_CANCELLED_VENDOR => $this->orderCancelledVendor($order, $extra),
            EmailTemplate::DISPUTE_OPENED_ADMIN => $this->disputeOpenedAdmin($order, $extra),
        };
    }

    // -----------------------------------------------------------------
    // Customer templates
    // -----------------------------------------------------------------

    private function orderPlacedCustomer(Order $order): RenderedEmail
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

    private function orderPaidCustomer(Order $order): RenderedEmail
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

    private function orderPaymentFailedCustomer(Order $order): RenderedEmail
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
    private function orderShippedCustomer(Order $order, array $extra): RenderedEmail
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
    private function orderDeliveredCustomer(Order $order, array $extra): RenderedEmail
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
    private function orderCancelledCustomer(Order $order, array $extra): RenderedEmail
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
    private function orderRefundedCustomer(Order $order, array $extra): RenderedEmail
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
    private function orderPlacedVendor(Order $order, array $extra): RenderedEmail
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
    private function orderCancelledVendor(Order $order, array $extra): RenderedEmail
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
    private function disputeOpenedAdmin(Order $order, array $extra): RenderedEmail
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
    private function wrapHtml(string $title, string $body): string
    {
        $titleEsc = $this->esc($title);
        return <<<HTML
<!DOCTYPE html>
<html>
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
