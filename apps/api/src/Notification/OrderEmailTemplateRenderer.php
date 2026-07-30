<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Chat\OrderDetailsMessageBuilder;
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
            EmailTemplate::ORDER_ACCEPTED_CUSTOMER => $isArabic
                ? $this->orderAcceptedCustomerAr($order, $extra)
                : $this->orderAcceptedCustomerEn($order, $extra),
            EmailTemplate::ORDER_PREPARING_CUSTOMER => $isArabic
                ? $this->orderPreparingCustomerAr($order, $extra)
                : $this->orderPreparingCustomerEn($order, $extra),
            EmailTemplate::ORDER_REJECTED_CUSTOMER => $isArabic
                ? $this->orderRejectedCustomerAr($order, $extra)
                : $this->orderRejectedCustomerEn($order, $extra),
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
            EmailTemplate::ORDER_STATUS_CHANGED_CUSTOMER => $isArabic
                ? $this->orderStatusChangedCustomerAr($order, $extra)
                : $this->orderStatusChangedCustomerEn($order, $extra),
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
            EmailTemplate::ORDER_STATUS_CHANGED_ADMIN => $this->orderStatusChangedAdminEn($order, $extra),

            // M3.2.X.18-G — Return request flow. Customer templates
            // dispatch by locale; vendor template uses vendor's
            // preferred locale; admin template always English.
            EmailTemplate::RETURN_SUBMITTED_CUSTOMER => $isArabic
                ? $this->returnSubmittedCustomerAr($order, $extra)
                : $this->returnSubmittedCustomerEn($order, $extra),
            EmailTemplate::RETURN_APPROVED_CUSTOMER => $isArabic
                ? $this->returnApprovedCustomerAr($order, $extra)
                : $this->returnApprovedCustomerEn($order, $extra),
            EmailTemplate::RETURN_DENIED_CUSTOMER => $isArabic
                ? $this->returnDeniedCustomerAr($order, $extra)
                : $this->returnDeniedCustomerEn($order, $extra),
            EmailTemplate::RETURN_PICKED_UP_CUSTOMER => $isArabic
                ? $this->returnPickedUpCustomerAr($order, $extra)
                : $this->returnPickedUpCustomerEn($order, $extra),
            EmailTemplate::RETURN_RECEIVED_BY_VENDOR_CUSTOMER => $isArabic
                ? $this->returnReceivedByVendorCustomerAr($order, $extra)
                : $this->returnReceivedByVendorCustomerEn($order, $extra),
            EmailTemplate::RETURN_REFUNDED_CUSTOMER => $isArabic
                ? $this->returnRefundedCustomerAr($order, $extra)
                : $this->returnRefundedCustomerEn($order, $extra),
            EmailTemplate::RETURN_SUBMITTED_VENDOR => $isArabic
                ? $this->returnSubmittedVendorAr($order, $extra)
                : $this->returnSubmittedVendorEn($order, $extra),
            EmailTemplate::RETURN_SUBMITTED_ADMIN => $this->returnSubmittedAdminEn($order, $extra),

            // M3.2.X.11 — cart-scoped templates are rendered by
            // CartEmailTemplateRenderer, not this class. If a caller
            // routes one here it's a wiring bug; fail loudly rather
            // than silently render an empty email.
            EmailTemplate::CART_ABANDONED_CUSTOMER => throw new \LogicException(
                'Cart-scoped template ' . $template->value
                . ' must be rendered by CartEmailTemplateRenderer, not OrderEmailTemplateRenderer.',
            ),
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
        [$detailsText, $detailsHtml] = $this->fullDetails(
            $order,
            $order->getItems(),
            false,
            'Here are your order details:',
        );

        return new RenderedEmail(
            subject: "Payment confirmed for order {$ref} — 3bayti",
            textBody: <<<TXT
Thank you — your payment has been confirmed and we're preparing your order now.

{$detailsText}

You'll get another email once it ships.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Payment confirmed",
                body: <<<HTML
<p>Thank you — your payment has been confirmed and we're preparing your order now.</p>
{$detailsHtml}
<p style="margin-top:16px;">You'll get another email once it ships.</p>
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
    private function orderAcceptedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'Your item');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "Your order {$ref} is confirmed by the seller — 3bayti",
            textBody: <<<TXT
Good news — the seller has accepted an item from your order and will begin preparing it.

Order reference: {$ref}
Item: {$itemName}

We'll keep you posted as it moves through to shipping.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Order confirmed by the seller",
                body: <<<HTML
<p>Good news — the seller has accepted an item from your order and will begin preparing it.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Item:</strong> {$itemNameEsc}</p>
<p>We'll keep you posted as it moves through to shipping.</p>
HTML,
            ),
        );
    }

    private function orderAcceptedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'منتجك');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "تم تأكيد طلبك {$ref} من قبل البائع — 3bayti",
            textBody: <<<TXT
خبر سار — قبل البائع أحد منتجات طلبك وسيبدأ في تجهيزه.

رقم الطلب: {$ref}
المنتج: {$itemName}

سنبقيك على اطلاع حتى يتم الشحن.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "تم تأكيد الطلب من قبل البائع",
                body: <<<HTML
<p>خبر سار — قبل البائع أحد منتجات طلبك وسيبدأ في تجهيزه.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>المنتج:</strong> {$itemNameEsc}</p>
<p>سنبقيك على اطلاع حتى يتم الشحن.</p>
HTML,
            ),
        );
    }

    private function orderPreparingCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'Your item');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "Your order {$ref} is being prepared — 3bayti",
            textBody: <<<TXT
An item from your order is now being prepared for shipment.

Order reference: {$ref}
Item: {$itemName}

You'll receive a notification as soon as it ships.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Order being prepared",
                body: <<<HTML
<p>An item from your order is now being prepared for shipment.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Item:</strong> {$itemNameEsc}</p>
<p>You'll receive a notification as soon as it ships.</p>
HTML,
            ),
        );
    }

    private function orderPreparingCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'منتجك');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "جارٍ تجهيز طلبك {$ref} — 3bayti",
            textBody: <<<TXT
يتم الآن تجهيز أحد منتجات طلبك للشحن.

رقم الطلب: {$ref}
المنتج: {$itemName}

ستصلك رسالة فور شحنه.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "جارٍ تجهيز الطلب",
                body: <<<HTML
<p>يتم الآن تجهيز أحد منتجات طلبك للشحن.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>المنتج:</strong> {$itemNameEsc}</p>
<p>ستصلك رسالة فور شحنه.</p>
HTML,
            ),
        );
    }

    private function orderRejectedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'An item');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "Update on your order {$ref} — 3bayti",
            textBody: <<<TXT
We're sorry — the seller is unable to fulfil an item from your order.

Order reference: {$ref}
Item: {$itemName}

If you were charged for this item, a refund will be processed automatically. Any other items in your order are unaffected.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "Update on your order",
                body: <<<HTML
<p>We're sorry — the seller is unable to fulfil an item from your order.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Item:</strong> {$itemNameEsc}</p>
<p>If you were charged for this item, a refund will be processed automatically. Any other items in your order are unaffected.</p>
HTML,
            ),
        );
    }

    private function orderRejectedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $itemName = (string) ($extra['item_name'] ?? 'أحد المنتجات');
        $itemNameEsc = $this->esc($itemName);

        return new RenderedEmail(
            subject: "تحديث بخصوص طلبك {$ref} — 3bayti",
            textBody: <<<TXT
نأسف — البائع غير قادر على تنفيذ أحد منتجات طلبك.

رقم الطلب: {$ref}
المنتج: {$itemName}

إذا تم خصم قيمة هذا المنتج، فسيتم رد المبلغ تلقائيًا. باقي منتجات طلبك غير متأثرة.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "تحديث بخصوص طلبك",
                body: <<<HTML
<p>نأسف — البائع غير قادر على تنفيذ أحد منتجات طلبك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>المنتج:</strong> {$itemNameEsc}</p>
<p>إذا تم خصم قيمة هذا المنتج، فسيتم رد المبلغ تلقائيًا. باقي منتجات طلبك غير متأثرة.</p>
HTML,
            ),
        );
    }

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

    /**
     * Generic order status-change notice. Used when an admin overrides
     * the order-level status to a value that has no dedicated customer
     * template (e.g. paid, fulfilling, shipped, delivered). The
     * human-readable status label is passed via $extra['status_label'];
     * we fall back to the raw status code if absent.
     *
     * @param array<string, mixed> $extra
     */
    private function orderStatusChangedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $statusLabel = $this->statusLabelEn((string) ($extra['new_status'] ?? ''));

        return new RenderedEmail(
            subject: "Update on your order {$ref} — 3bayti",
            textBody: <<<TXT
There's an update on your order.

Order reference: {$ref}
Status: {$statusLabel}

You can view the latest details any time in the 3bayti app.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Order update',
                body: <<<HTML
<p>There's an update on your order.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Status:</strong> {$this->esc($statusLabel)}</p>
<p>You can view the latest details any time in the 3bayti app.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function orderStatusChangedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $statusLabel = $this->statusLabelAr((string) ($extra['new_status'] ?? ''));

        return new RenderedEmail(
            subject: "تحديث بخصوص طلبك {$ref} — 3bayti",
            textBody: <<<TXT
هناك تحديث بخصوص طلبك.

رقم الطلب: {$ref}
الحالة: {$statusLabel}

يمكنك الاطلاع على آخر التفاصيل في أي وقت من تطبيق 3bayti.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تحديث الطلب',
                body: <<<HTML
<p>هناك تحديث بخصوص طلبك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>الحالة:</strong> {$this->esc($statusLabel)}</p>
<p>يمكنك الاطلاع على آخر التفاصيل في أي وقت من تطبيق 3bayti.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * Map an Order::STATUS_* code to a friendly English label. Unknown
     * codes pass through verbatim (defensive — never render an empty
     * status line).
     */
    private function statusLabelEn(string $status): string
    {
        return match ($status) {
            Order::STATUS_PENDING_PAYMENT => 'Pending payment',
            Order::STATUS_PAID => 'Paid',
            Order::STATUS_FULFILLING => 'Being fulfilled',
            Order::STATUS_SHIPPED => 'Shipped',
            Order::STATUS_DELIVERED => 'Delivered',
            Order::STATUS_CANCELLED => 'Cancelled',
            Order::STATUS_REFUNDED => 'Refunded',
            Order::STATUS_FAILED => 'Failed',
            default => $status !== '' ? $status : 'Updated',
        };
    }

    private function statusLabelAr(string $status): string
    {
        return match ($status) {
            Order::STATUS_PENDING_PAYMENT => 'بانتظار الدفع',
            Order::STATUS_PAID => 'مدفوع',
            Order::STATUS_FULFILLING => 'قيد التجهيز',
            Order::STATUS_SHIPPED => 'تم الشحن',
            Order::STATUS_DELIVERED => 'تم التسليم',
            Order::STATUS_CANCELLED => 'ملغي',
            Order::STATUS_REFUNDED => 'تم الاسترداد',
            Order::STATUS_FAILED => 'فشل',
            default => $status !== '' ? $status : 'تم التحديث',
        };
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
        $vendorName = (string) ($extra['vendor_name'] ?? '');
        $greeting = $vendorName !== '' ? "Hi {$vendorName}," : 'Hi,';
        [$detailsText, $detailsHtml] = $this->vendorDetails($order, $extra, false);

        return new RenderedEmail(
            subject: "New order {$ref} — items to prepare",
            textBody: <<<TXT
{$greeting}

You have a new PAID order to fulfil. Everything you need to prepare and
ship it is below.

{$detailsText}

Mark each item 'accepted', then 'shipped', from your vendor dashboard
once you've packed and dispatched.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: "New order to prepare",
                body: <<<HTML
<p>{$this->esc($greeting)}</p>
<p>You have a new <strong>paid</strong> order to fulfil. Everything you need to prepare and ship it is below.</p>
{$detailsHtml}
<p style="margin-top:16px;">Mark each item <strong>accepted</strong>, then <strong>shipped</strong>, from your vendor dashboard once you've packed and dispatched.</p>
HTML,
            ),
        );
    }

    /**
     * Full detail block(s) for a vendor's items. Prefers the rich
     * OrderDetailsMessageBuilder snapshot from 'vendor_order_items'
     * (list<OrderItem>); falls back to bare names in 'vendor_items' if a
     * caller didn't pass the items (defensive — the paid-order path always
     * passes them).
     *
     * @param array<string, mixed> $extra
     * @return array{0: string, 1: string} [text, html]
     */
    private function vendorDetails(Order $order, array $extra, bool $isArabic): array
    {
        $items = $extra['vendor_order_items'] ?? null;
        if (is_array($items) && $items !== []) {
            return $this->fullDetails($order, $items, $isArabic, 'Order details:', 'تفاصيل الطلب:');
        }

        // Fallback: names only.
        $names = $extra['vendor_items'] ?? [];
        $text = '';
        $html = '<ul>';
        if (is_array($names)) {
            foreach ($names as $name) {
                $text .= '  - ' . (string) $name . "\n";
                $html .= '<li>' . $this->esc((string) $name) . '</li>';
            }
        }
        $html .= '</ul>';
        return [$text, $html];
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

    /**
     * Admin alert — an admin manually overrode an order or item status.
     * Surfaces in the admin bell + ops email. Always English
     * (Q-VendorAdminLocale = A locked).
     *
     * $extra:
     *   new_status  — the status the order/item was forced into
     *   old_status  — the previous status (optional)
     *   scope       — 'order' | 'item' (optional, defaults 'order')
     *   item_name   — name of the item when scope='item' (optional)
     *   actor       — email/name of the admin who made the change (optional)
     *   reason      — admin's reason for the override (optional)
     *
     * @param array<string, mixed> $extra
     */
    private function orderStatusChangedAdminEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $newStatus = (string) ($extra['new_status'] ?? '');
        $oldStatus = (string) ($extra['old_status'] ?? '');
        $scope = (string) ($extra['scope'] ?? 'order');
        $itemName = (string) ($extra['item_name'] ?? '');
        $actor = (string) ($extra['actor'] ?? '');
        $reason = (string) ($extra['reason'] ?? '');

        $scopeLine = $scope === 'item' && $itemName !== ''
            ? "Item: {$itemName}\n"
            : '';
        $scopeHtml = $scope === 'item' && $itemName !== ''
            ? "<strong>Item:</strong> {$this->esc($itemName)}<br>"
            : '';
        $transition = $oldStatus !== ''
            ? "{$oldStatus} → {$newStatus}"
            : $newStatus;
        $actorLine = $actor !== '' ? "Changed by: {$actor}\n" : '';
        $actorHtml = $actor !== ''
            ? "<p><strong>Changed by:</strong> {$this->esc($actor)}</p>"
            : '';
        $reasonLine = $reason !== '' ? "Reason: {$reason}\n" : '';
        $reasonHtml = $reason !== ''
            ? "<p><strong>Reason:</strong> {$this->esc($reason)}</p>"
            : '';

        return new RenderedEmail(
            subject: "[OVERRIDE] {$scope} status changed on order {$ref}",
            textBody: <<<TXT
An admin manually overrode a status.

Order reference: {$ref}
Scope: {$scope}
{$scopeLine}Status: {$transition}
{$actorLine}{$reasonLine}
Review in the admin dashboard:
https://3bayti.ae/admin/orders

— 3bayti operations
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Status override',
                body: <<<HTML
<p>An admin manually overrode a status.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Scope:</strong> {$this->esc($scope)}<br>
{$scopeHtml}<strong>Status:</strong> {$this->esc($transition)}</p>
{$actorHtml}
{$reasonHtml}
<p>Review in the <a href="https://3bayti.ae/admin/orders">admin dashboard</a>.</p>
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
     * Full order-detail block(s) — the SAME snapshot the order chat opens with
     * (product, variant, measurements, pricing, totals, shipping + the
     * keep-communication-here policy footer), reused verbatim via
     * OrderDetailsMessageBuilder so the emails never drift from the chat. One
     * block per item passed in. Returns [plainText, html]; the HTML preserves
     * the line structure (pre-wrap + <br>).
     *
     * @param iterable<OrderItem> $items
     * @return array{0: string, 1: string} [text, html]
     */
    private function fullDetails(
        Order $order,
        iterable $items,
        bool $isArabic,
        ?string $introEn = null,
        ?string $introAr = null,
    ): array {
        $builder = new OrderDetailsMessageBuilder();
        $blocks = [];
        foreach ($items as $item) {
            $built = $builder->build($order, $item, $introEn, $introAr);
            $blocks[] = $isArabic ? $built[1] : $built[0];
        }
        $text = implode("\n\n", $blocks);
        $html = '<div style="white-space: pre-wrap; font-size: 14px; line-height: 1.6; '
            . 'color: #1c1c1e; background: #faf8f5; border: 1px solid #ece7df; '
            . 'border-radius: 8px; padding: 16px;">'
            . nl2br($this->esc($text))
            . '</div>';
        return [$text, $html];
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
        [$detailsText, $detailsHtml] = $this->fullDetails(
            $order,
            $order->getItems(),
            true,
            null,
            'إليك تفاصيل طلبك:',
        );

        return new RenderedEmail(
            subject: "تأكيد الدفع للطلب {$ref} — 3bayti",
            textBody: <<<TXT
شكراً لك — تم تأكيد دفعتك ونقوم الآن بتجهيز طلبك.

{$detailsText}

ستصلك رسالة أخرى عند شحنه.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تأكيد الدفع',
                body: <<<HTML
<p>شكراً لك — تم تأكيد دفعتك ونقوم الآن بتجهيز طلبك.</p>
{$detailsHtml}
<p style="margin-top:16px;">ستصلك رسالة أخرى عند شحنه.</p>
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
        $vendorName = (string) ($extra['vendor_name'] ?? '');
        $greeting = $vendorName !== '' ? "مرحباً {$vendorName}،" : 'مرحباً،';
        [$detailsText, $detailsHtml] = $this->vendorDetails($order, $extra, true);

        return new RenderedEmail(
            subject: "طلب جديد {$ref} — منتجات للتجهيز",
            textBody: <<<TXT
{$greeting}

لديك طلب جديد مدفوع للتجهيز. كل ما تحتاجه لتجهيزه وشحنه موضح أدناه.

{$detailsText}

يرجى تعليم كل منتج بحالة 'مقبول' ثم 'تم الشحن' من لوحة تحكم البائع بعد
تجهيزه وشحنه.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'طلب جديد للتجهيز',
                body: <<<HTML
<p>{$this->esc($greeting)}</p>
<p>لديك طلب جديد <strong>مدفوع</strong> للتجهيز. كل ما تحتاجه لتجهيزه وشحنه موضح أدناه.</p>
{$detailsHtml}
<p style="margin-top:16px;">يرجى تعليم كل منتج بحالة 'مقبول' ثم 'تم الشحن' من لوحة تحكم البائع بعد تجهيزه وشحنه.</p>
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

    // =================================================================
    // M3.2.X.18-G — Return request flow templates
    // =================================================================
    //
    // Six customer-facing lifecycle events (each EN + AR), one vendor
    // event (EN + AR per Q-VendorAdminLocale), one admin event (EN
    // only). The $extra context provides:
    //   - return_reference: like 'RET-7' for human-readable display
    //   - reason: the OrderReturnRequest reason code
    //   - admin_notes: admin's explanation (denial reasons especially)
    //   - refund_amount + refund_method + refund_reference: only on
    //     the refunded template
    //   - returned_items: list of item names being returned

    /**
     * @param array<string, mixed> $extra
     */
    private function returnSubmittedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $reason = (string) ($extra['reason'] ?? '');
        $itemsList = $this->returnedItemListText($extra);
        $itemsHtml = $this->returnedItemListHtml($extra);

        return new RenderedEmail(
            subject: "Return request received for order {$ref} — 3bayti",
            textBody: <<<TXT
We've received your return request and will review it shortly.

Order reference: {$ref}
Return reference: {$returnRef}
Reason: {$reason}

Items requested for return:
{$itemsList}
Our team typically reviews return requests within 1-2 business days.
You'll receive another email once a decision has been made.

You can track your return at any time in the 3bayti app.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Return request received',
                body: <<<HTML
<p>We've received your return request and will review it shortly.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Return reference:</strong> {$this->esc($returnRef)}<br>
<strong>Reason:</strong> {$this->esc($reason)}</p>
<h3>Items requested for return</h3>
{$itemsHtml}
<p>Our team typically reviews return requests within 1-2 business days. You'll receive another email once a decision has been made.</p>
<p>You can track your return at any time in the 3bayti app.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnSubmittedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $reason = (string) ($extra['reason'] ?? '');
        $itemsList = $this->returnedItemListText($extra);
        $itemsHtml = $this->returnedItemListHtml($extra);

        return new RenderedEmail(
            subject: "تم استلام طلب إرجاع للطلب {$ref} — 3bayti",
            textBody: <<<TXT
لقد تلقينا طلب الإرجاع الخاص بك وسنقوم بمراجعته قريباً.

رقم الطلب: {$ref}
رقم طلب الإرجاع: {$returnRef}
السبب: {$reason}

المنتجات المطلوب إرجاعها:
{$itemsList}
يقوم فريقنا عادةً بمراجعة طلبات الإرجاع خلال يوم إلى يومي عمل.
ستتلقى بريدًا إلكترونيًا آخر بمجرد اتخاذ القرار.

يمكنك متابعة حالة الإرجاع في أي وقت من تطبيق 3bayti.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم استلام طلب الإرجاع',
                body: <<<HTML
<p>لقد تلقينا طلب الإرجاع الخاص بك وسنقوم بمراجعته قريباً.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>رقم طلب الإرجاع:</strong> {$this->esc($returnRef)}<br>
<strong>السبب:</strong> {$this->esc($reason)}</p>
<h3>المنتجات المطلوب إرجاعها</h3>
{$itemsHtml}
<p>يقوم فريقنا عادةً بمراجعة طلبات الإرجاع خلال يوم إلى يومي عمل. ستتلقى بريدًا إلكترونيًا آخر بمجرد اتخاذ القرار.</p>
<p>يمكنك متابعة حالة الإرجاع في أي وقت من تطبيق 3bayti.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnApprovedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $adminNotes = (string) ($extra['admin_notes'] ?? '');
        $notesLine = $adminNotes !== '' ? "Note from our team: {$adminNotes}\n\n" : '';
        $notesHtml = $adminNotes !== ''
            ? "<p><strong>Note from our team:</strong> {$this->esc($adminNotes)}</p>"
            : '';

        return new RenderedEmail(
            subject: "Return approved for order {$ref} — 3bayti",
            textBody: <<<TXT
Good news — your return request has been approved.

Order reference: {$ref}
Return reference: {$returnRef}

{$notesLine}Next steps:
Our logistics partner will contact you within 2 business days to
arrange pickup of the returned items. Please keep the items in
their original condition and packaging.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Return approved',
                body: <<<HTML
<p><strong>Good news</strong> — your return request has been approved.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Return reference:</strong> {$this->esc($returnRef)}</p>
{$notesHtml}
<h3>Next steps</h3>
<p>Our logistics partner will contact you within 2 business days to arrange pickup of the returned items. Please keep the items in their original condition and packaging.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnApprovedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $adminNotes = (string) ($extra['admin_notes'] ?? '');
        $notesLine = $adminNotes !== '' ? "ملاحظة من فريقنا: {$adminNotes}\n\n" : '';
        $notesHtml = $adminNotes !== ''
            ? "<p><strong>ملاحظة من فريقنا:</strong> {$this->esc($adminNotes)}</p>"
            : '';

        return new RenderedEmail(
            subject: "تمت الموافقة على الإرجاع للطلب {$ref} — 3bayti",
            textBody: <<<TXT
أخبار جيدة — تمت الموافقة على طلب الإرجاع الخاص بك.

رقم الطلب: {$ref}
رقم طلب الإرجاع: {$returnRef}

{$notesLine}الخطوات التالية:
سيتواصل معك شريك الشحن خلال يومي عمل لترتيب استلام المنتجات
المراد إرجاعها. يرجى الاحتفاظ بالمنتجات بحالتها وعبواتها الأصلية.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تمت الموافقة على الإرجاع',
                body: <<<HTML
<p><strong>أخبار جيدة</strong> — تمت الموافقة على طلب الإرجاع الخاص بك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>رقم طلب الإرجاع:</strong> {$this->esc($returnRef)}</p>
{$notesHtml}
<h3>الخطوات التالية</h3>
<p>سيتواصل معك شريك الشحن خلال يومي عمل لترتيب استلام المنتجات المراد إرجاعها. يرجى الاحتفاظ بالمنتجات بحالتها وعبواتها الأصلية.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnDeniedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $adminNotes = (string) ($extra['admin_notes'] ?? '');

        return new RenderedEmail(
            subject: "Update on return for order {$ref} — 3bayti",
            textBody: <<<TXT
We've reviewed your return request and unfortunately are unable
to approve it at this time.

Order reference: {$ref}
Return reference: {$returnRef}

Reason from our team:
{$adminNotes}

If you have additional information you'd like us to consider, or
if you believe this decision was made in error, please reply to
this email or contact our support team via the 3bayti app.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Return request decision',
                body: <<<HTML
<p>We've reviewed your return request and unfortunately are unable to approve it at this time.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Return reference:</strong> {$this->esc($returnRef)}</p>
<h3>Reason from our team</h3>
<p>{$this->esc($adminNotes)}</p>
<p>If you have additional information you'd like us to consider, or if you believe this decision was made in error, please reply to this email or contact our support team via the 3bayti app.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnDeniedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $adminNotes = (string) ($extra['admin_notes'] ?? '');

        return new RenderedEmail(
            subject: "تحديث حول طلب الإرجاع للطلب {$ref} — 3bayti",
            textBody: <<<TXT
قمنا بمراجعة طلب الإرجاع الخاص بك ولسوء الحظ لن نتمكن من
الموافقة عليه في الوقت الحالي.

رقم الطلب: {$ref}
رقم طلب الإرجاع: {$returnRef}

السبب من فريقنا:
{$adminNotes}

إذا كان لديك معلومات إضافية ترغب في أن نأخذها بعين الاعتبار،
أو إذا كنت تعتقد أن هذا القرار اتُخذ بالخطأ، يرجى الرد على هذا
البريد أو التواصل مع فريق الدعم من تطبيق 3bayti.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'قرار بشأن طلب الإرجاع',
                body: <<<HTML
<p>قمنا بمراجعة طلب الإرجاع الخاص بك ولسوء الحظ لن نتمكن من الموافقة عليه في الوقت الحالي.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>رقم طلب الإرجاع:</strong> {$this->esc($returnRef)}</p>
<h3>السبب من فريقنا</h3>
<p>{$this->esc($adminNotes)}</p>
<p>إذا كان لديك معلومات إضافية ترغب في أن نأخذها بعين الاعتبار، أو إذا كنت تعتقد أن هذا القرار اتُخذ بالخطأ، يرجى الرد على هذا البريد أو التواصل مع فريق الدعم من تطبيق 3bayti.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnPickedUpCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');

        return new RenderedEmail(
            subject: "Items picked up for return {$returnRef} — 3bayti",
            textBody: <<<TXT
We've picked up your returned items.

Order reference: {$ref}
Return reference: {$returnRef}

The items are now in transit back to the seller. Once they
confirm receipt and verify the condition of the goods, we'll
process your refund.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Items picked up',
                body: <<<HTML
<p>We've picked up your returned items.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Return reference:</strong> {$this->esc($returnRef)}</p>
<p>The items are now in transit back to the seller. Once they confirm receipt and verify the condition of the goods, we'll process your refund.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnPickedUpCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');

        return new RenderedEmail(
            subject: "تم استلام منتجات الإرجاع {$returnRef} — 3bayti",
            textBody: <<<TXT
لقد قمنا باستلام المنتجات المراد إرجاعها.

رقم الطلب: {$ref}
رقم طلب الإرجاع: {$returnRef}

المنتجات الآن في طريقها إلى البائع. بمجرد تأكيد البائع استلام
المنتجات والتحقق من حالتها، سنقوم بمعالجة الاسترداد.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم استلام المنتجات',
                body: <<<HTML
<p>لقد قمنا باستلام المنتجات المراد إرجاعها.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>رقم طلب الإرجاع:</strong> {$this->esc($returnRef)}</p>
<p>المنتجات الآن في طريقها إلى البائع. بمجرد تأكيد البائع استلام المنتجات والتحقق من حالتها، سنقوم بمعالجة الاسترداد.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnReceivedByVendorCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');

        return new RenderedEmail(
            subject: "Return received — refund processing for {$returnRef}",
            textBody: <<<TXT
The seller has confirmed receipt of your returned items.

Order reference: {$ref}
Return reference: {$returnRef}

Your refund is being processed and will be issued within 2-3
business days. You'll receive a final confirmation email once
the refund has been completed.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Return received',
                body: <<<HTML
<p>The seller has confirmed receipt of your returned items.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Return reference:</strong> {$this->esc($returnRef)}</p>
<p>Your refund is being processed and will be issued within 2-3 business days. You'll receive a final confirmation email once the refund has been completed.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnReceivedByVendorCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');

        return new RenderedEmail(
            subject: "تم استلام الإرجاع — جاري معالجة الاسترداد {$returnRef}",
            textBody: <<<TXT
أكد البائع استلام المنتجات المُرجَعة.

رقم الطلب: {$ref}
رقم طلب الإرجاع: {$returnRef}

تتم الآن معالجة الاسترداد الخاص بك وسيتم إصداره خلال يومين إلى
ثلاثة أيام عمل. ستتلقى بريدًا إلكترونيًا تأكيديًا نهائيًا بمجرد
اكتمال عملية الاسترداد.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم استلام الإرجاع',
                body: <<<HTML
<p>أكد البائع استلام المنتجات المُرجَعة.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>رقم طلب الإرجاع:</strong> {$this->esc($returnRef)}</p>
<p>تتم الآن معالجة الاسترداد الخاص بك وسيتم إصداره خلال يومين إلى ثلاثة أيام عمل. ستتلقى بريدًا إلكترونيًا تأكيديًا نهائيًا بمجرد اكتمال عملية الاسترداد.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnRefundedCustomerEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $amount = (string) ($extra['refund_amount'] ?? '');
        $currency = (string) ($extra['refund_currency'] ?? $order->getCurrency());
        $method = (string) ($extra['refund_method'] ?? '');
        $reference = (string) ($extra['refund_reference'] ?? '');
        $refLine = $reference !== '' ? "Reference: {$reference}\n" : '';
        $refHtml = $reference !== ''
            ? "<p><strong>Reference:</strong> {$this->esc($reference)}</p>"
            : '';

        return new RenderedEmail(
            subject: "Refund issued for return {$returnRef} — 3bayti",
            textBody: <<<TXT
Your refund has been issued.

Order reference: {$ref}
Return reference: {$returnRef}
Refund amount: {$amount} {$currency}
Method: {$method}
{$refLine}
Depending on your bank, the funds typically appear in 5-10
business days.

Thank you for shopping with 3bayti.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Refund issued',
                body: <<<HTML
<p>Your refund has been issued.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Return reference:</strong> {$this->esc($returnRef)}<br>
<strong>Refund amount:</strong> {$this->esc($amount)} {$this->esc($currency)}<br>
<strong>Method:</strong> {$this->esc($method)}</p>
{$refHtml}
<p>Depending on your bank, the funds typically appear in 5-10 business days.</p>
<p>Thank you for shopping with 3bayti.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnRefundedCustomerAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $amount = (string) ($extra['refund_amount'] ?? '');
        $currency = (string) ($extra['refund_currency'] ?? $order->getCurrency());
        $method = (string) ($extra['refund_method'] ?? '');
        $reference = (string) ($extra['refund_reference'] ?? '');
        $refLine = $reference !== '' ? "المرجع: {$reference}\n" : '';
        $refHtml = $reference !== ''
            ? "<p><strong>المرجع:</strong> {$this->esc($reference)}</p>"
            : '';

        return new RenderedEmail(
            subject: "تم إصدار الاسترداد لطلب الإرجاع {$returnRef} — 3bayti",
            textBody: <<<TXT
تم إصدار الاسترداد الخاص بك.

رقم الطلب: {$ref}
رقم طلب الإرجاع: {$returnRef}
مبلغ الاسترداد: {$amount} {$currency}
الطريقة: {$method}
{$refLine}
تظهر الأموال عادةً خلال 5 إلى 10 أيام عمل، حسب البنك الذي
تتعامل معه.

شكراً لتسوقك من 3bayti.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'تم إصدار الاسترداد',
                body: <<<HTML
<p>تم إصدار الاسترداد الخاص بك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>رقم طلب الإرجاع:</strong> {$this->esc($returnRef)}<br>
<strong>مبلغ الاسترداد:</strong> {$this->esc($amount)} {$this->esc($currency)}<br>
<strong>الطريقة:</strong> {$this->esc($method)}</p>
{$refHtml}
<p>تظهر الأموال عادةً خلال 5 إلى 10 أيام عمل، حسب البنك الذي تتعامل معه.</p>
<p>شكراً لتسوقك من 3bayti.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnSubmittedVendorEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $reason = (string) ($extra['reason'] ?? '');
        $itemsList = $this->returnedItemListText($extra);
        $itemsHtml = $this->returnedItemListHtml($extra);

        return new RenderedEmail(
            subject: "Return requested on order {$ref}",
            textBody: <<<TXT
A customer has requested a return on items from your store.

Order reference: {$ref}
Return reference: {$returnRef}
Reason: {$reason}

Items being returned:
{$itemsList}
3bayti operations will review the customer's photo evidence and
decide whether to approve. If approved, our logistics partner
will pick the items up from the customer and deliver them to
you. You'll receive another email then.

No action required from you yet — this is a heads-up so you can
plan inventory.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'Return requested',
                body: <<<HTML
<p>A customer has requested a return on items from your store.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Return reference:</strong> {$this->esc($returnRef)}<br>
<strong>Reason:</strong> {$this->esc($reason)}</p>
<h3>Items being returned</h3>
{$itemsHtml}
<p>3bayti operations will review the customer's photo evidence and decide whether to approve. If approved, our logistics partner will pick the items up from the customer and deliver them to you. You'll receive another email then.</p>
<p><strong>No action required from you yet</strong> — this is a heads-up so you can plan inventory.</p>
HTML,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnSubmittedVendorAr(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $reason = (string) ($extra['reason'] ?? '');
        $itemsList = $this->returnedItemListText($extra);
        $itemsHtml = $this->returnedItemListHtml($extra);

        return new RenderedEmail(
            subject: "طلب إرجاع للطلب {$ref}",
            textBody: <<<TXT
طلب أحد العملاء إرجاع منتجات من متجرك.

رقم الطلب: {$ref}
رقم طلب الإرجاع: {$returnRef}
السبب: {$reason}

المنتجات المراد إرجاعها:
{$itemsList}
سيقوم فريق عمليات 3bayti بمراجعة الأدلة المصورة من العميل
واتخاذ قرار الموافقة. في حال الموافقة، سيقوم شريك الشحن
باستلام المنتجات من العميل وتسليمها إليك. ستتلقى بريداً
إلكترونياً آخر حينها.

لا يلزم منك أي إجراء حالياً — هذا إشعار حتى تتمكن من تخطيط
المخزون.

— 3bayti
TXT,
            htmlBody: $this->wrapHtml(
                title: 'طلب إرجاع',
                body: <<<HTML
<p>طلب أحد العملاء إرجاع منتجات من متجرك.</p>
<p><strong>رقم الطلب:</strong> {$this->esc($ref)}<br>
<strong>رقم طلب الإرجاع:</strong> {$this->esc($returnRef)}<br>
<strong>السبب:</strong> {$this->esc($reason)}</p>
<h3>المنتجات المراد إرجاعها</h3>
{$itemsHtml}
<p>سيقوم فريق عمليات 3bayti بمراجعة الأدلة المصورة من العميل واتخاذ قرار الموافقة. في حال الموافقة، سيقوم شريك الشحن باستلام المنتجات من العميل وتسليمها إليك. ستتلقى بريداً إلكترونياً آخر حينها.</p>
<p><strong>لا يلزم منك أي إجراء حالياً</strong> — هذا إشعار حتى تتمكن من تخطيط المخزون.</p>
HTML,
                locale: User::LOCALE_AR,
            ),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnSubmittedAdminEn(Order $order, array $extra): RenderedEmail
    {
        $ref = $order->getOrderReference();
        $returnRef = (string) ($extra['return_reference'] ?? '');
        $reason = (string) ($extra['reason'] ?? '');
        $customerNotes = (string) ($extra['customer_notes'] ?? '');
        $notesLine = $customerNotes !== '' ? "Customer notes: {$customerNotes}\n" : '';
        $notesHtml = $customerNotes !== ''
            ? "<p><strong>Customer notes:</strong> {$this->esc($customerNotes)}</p>"
            : '';
        $itemsList = $this->returnedItemListText($extra);
        $itemsHtml = $this->returnedItemListHtml($extra);

        return new RenderedEmail(
            subject: "[ACTION] New return request {$returnRef} on order {$ref}",
            textBody: <<<TXT
A new return request needs your review.

Order reference: {$ref}
Return reference: {$returnRef}
Reason: {$reason}
{$notesLine}
Items:
{$itemsList}
Review and decide:
https://3bayti.ae/admin/returns

— 3bayti operations
TXT,
            htmlBody: $this->wrapHtml(
                title: 'New return request',
                body: <<<HTML
<p>A new return request needs your review.</p>
<p><strong>Order reference:</strong> {$this->esc($ref)}<br>
<strong>Return reference:</strong> {$this->esc($returnRef)}<br>
<strong>Reason:</strong> {$this->esc($reason)}</p>
{$notesHtml}
<h3>Items</h3>
{$itemsHtml}
<p>Review and decide in the <a href="https://3bayti.ae/admin/returns">admin dashboard</a>.</p>
HTML,
            ),
        );
    }

    /**
     * Render a list of items being returned. Falls back gracefully
     * if the extra context didn't supply 'returned_items'.
     *
     * @param array<string, mixed> $extra
     */
    private function returnedItemListText(array $extra): string
    {
        $items = $extra['returned_items'] ?? [];
        if (!is_array($items) || $items === []) {
            return "  (item list unavailable)\n";
        }
        $out = '';
        foreach ($items as $name) {
            $out .= '  - ' . (string) $name . "\n";
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function returnedItemListHtml(array $extra): string
    {
        $items = $extra['returned_items'] ?? [];
        if (!is_array($items) || $items === []) {
            return '<p><em>(item list unavailable)</em></p>';
        }
        $out = '<ul>';
        foreach ($items as $name) {
            $out .= '<li>' . $this->esc((string) $name) . '</li>';
        }
        $out .= '</ul>';
        return $out;
    }
}
