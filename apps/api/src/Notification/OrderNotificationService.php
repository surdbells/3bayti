<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Wires order lifecycle events to outbound email.
 *
 * Each public method represents a lifecycle moment + dispatches
 * the appropriate emails to the appropriate recipients. Mailer
 * failures are caught and logged — they never block the calling
 * action (e.g. payment confirmation must persist even if the
 * confirmation email fails).
 *
 * Recipient resolution
 * ====================
 *   Customer: order.getUser().getEmail()
 *   Vendor:   vendor.getContactEmail() for each unique vendor in
 *             the order's items (one email per vendor per event)
 *   Admin:    admin_email_recipients from config — comma-separated
 *             list of addresses. Empty list ⇒ no admin emails sent
 *             (degrades cleanly in dev/test).
 *
 * Idempotency
 * ===========
 * Notifications are fire-and-forget; callers should ensure they
 * call notification methods only once per legitimate transition.
 * We do NOT track "already sent" state — duplicate calls cause
 * duplicate emails. Acceptable trade-off vs. introducing a
 * notification_log table for M3.1.7.
 *
 * Future: add notification_log if we get reports of duplicate
 * emails from genuine retries.
 */
final class OrderNotificationService
{
    /**
     * @param list<string> $adminRecipients Email addresses of admin/ops
     *        people to copy on critical events (disputes, etc.). Empty
     *        list disables admin notifications.
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly OrderEmailTemplateRenderer $renderer,
        private readonly array $adminRecipients = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    // -----------------------------------------------------------------
    // Lifecycle hooks — called from controllers / services
    // -----------------------------------------------------------------

    /**
     * Customer just submitted the order (still pending_payment).
     * Sends:
     *   - 1 email to customer (order received, pending payment)
     *   - 1 email per vendor in the order (new order to prepare)
     */
    public function orderPlaced(Order $order): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_PLACED_CUSTOMER);
        $this->sendToVendors($order, EmailTemplate::ORDER_PLACED_VENDOR);
    }

    /**
     * Payment confirmed (webhook or reconciliation). Customer-only.
     */
    public function orderPaid(Order $order): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_PAID_CUSTOMER);
    }

    /**
     * Payment failed (webhook). Customer-only.
     */
    public function orderPaymentFailed(Order $order): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER);
    }

    /**
     * A single item was marked shipped by its vendor.
     * Customer gets a per-item email (we don't wait for the whole
     * order to ship — they want to know as each piece moves).
     */
    public function itemShipped(Order $order, OrderItem $item): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_SHIPPED_CUSTOMER, [
            'item_name' => $item->getProductNameSnapshot(),
        ]);
    }

    /**
     * A single item was marked delivered.
     */
    public function itemDelivered(Order $order, OrderItem $item): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_DELIVERED_CUSTOMER, [
            'item_name' => $item->getProductNameSnapshot(),
        ]);
    }

    /**
     * Order was cancelled (admin or customer-self-serve).
     * Sends:
     *   - 1 to customer (with refund info if applicable)
     *   - 1 per vendor (do-not-ship notice)
     *
     * @param array{
     *   refund_issued?: bool,
     *   refund_amount?: ?string,
     *   reason?: string
     * } $details
     */
    public function orderCancelled(Order $order, array $details = []): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_CANCELLED_CUSTOMER, $details);
        $this->sendToVendors($order, EmailTemplate::ORDER_CANCELLED_VENDOR, $details);
    }

    /**
     * Refund issued (full or partial). Customer-only.
     *
     * @param array{
     *   refund_amount: string,
     *   is_full_refund: bool
     * } $details
     */
    public function orderRefunded(Order $order, array $details): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_REFUNDED_CUSTOMER, $details);
    }

    /**
     * Dispute opened against an order (webhook event).
     * Notifies admins so they can triage. Order owner is intentionally
     * NOT notified here — disputes are operational concerns.
     *
     * @param array{
     *   event_type?: string,
     *   amount?: ?string,
     *   currency?: ?string,
     *   reason?: ?string
     * } $details
     */
    public function disputeOpened(Order $order, array $details = []): void
    {
        if ($this->adminRecipients === []) {
            $this->logger->info('notification.dispute.no_admin_recipients_configured', [
                'order_id' => $order->getId(),
            ]);
            return;
        }
        foreach ($this->adminRecipients as $adminEmail) {
            $this->safeSend(
                to: $adminEmail,
                template: EmailTemplate::DISPUTE_OPENED_ADMIN,
                order: $order,
                extra: $details,
            );
        }
    }

    // -----------------------------------------------------------------
    // Internal dispatch
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     */
    private function sendToCustomer(Order $order, EmailTemplate $template, array $extra = []): void
    {
        $email = $order->getUser()->getEmail();
        if ($email === '') {
            $this->logger->warning('notification.customer.no_email', [
                'order_id' => $order->getId(),
                'template' => $template->value,
            ]);
            return;
        }
        $this->safeSend($email, $template, $order, $extra);
    }

    /**
     * Send the template once to each unique vendor in the order's items.
     * The 'vendor_items' context lists which items belong to that vendor.
     *
     * @param array<string, mixed> $extra
     */
    private function sendToVendors(Order $order, EmailTemplate $template, array $extra = []): void
    {
        /** @var array<int, array{vendor: Vendor, items: list<string>}> $byVendor */
        $byVendor = [];
        foreach ($order->getItems() as $item) {
            /** @var OrderItem $item */
            $vendor = $item->getVendor();
            $vid = $vendor->getId() ?? 0;
            if (!isset($byVendor[$vid])) {
                $byVendor[$vid] = ['vendor' => $vendor, 'items' => []];
            }
            $byVendor[$vid]['items'][] = $item->getProductNameSnapshot();
        }

        foreach ($byVendor as $group) {
            // Defensive: in some test setups (and rare prod cases where
            // a vendor was created with a placeholder), contactEmail may
            // be unset or empty. Guard before reading the typed property
            // (which would throw if uninitialized) and skip with a log.
            $vendor = $group['vendor'];
            try {
                $email = $vendor->getContactEmail();
            } catch (\Error $e) {
                $this->logger->warning('notification.vendor.contact_email_unset', [
                    'order_id' => $order->getId(),
                    'vendor_id' => $vendor->getId(),
                    'template' => $template->value,
                ]);
                continue;
            }
            if ($email === '') {
                $this->logger->warning('notification.vendor.no_email', [
                    'order_id' => $order->getId(),
                    'vendor_id' => $vendor->getId(),
                    'template' => $template->value,
                ]);
                continue;
            }
            $vendorExtra = array_merge($extra, ['vendor_items' => $group['items']]);
            $this->safeSend($email, $template, $order, $vendorExtra);
        }
    }

    /**
     * Render + send + catch-and-log. Never throws.
     *
     * @param array<string, mixed> $extra
     */
    private function safeSend(
        string $to,
        EmailTemplate $template,
        Order $order,
        array $extra = [],
    ): void {
        try {
            $rendered = $this->renderer->render($template, $order, $extra);
            $this->mailer->send(
                to: $to,
                subject: $rendered->subject,
                textBody: $rendered->textBody,
                htmlBody: $rendered->htmlBody,
                context: [
                    'template' => $template->value,
                    'order_id' => $order->getId(),
                    'order_reference' => $order->getOrderReference(),
                ],
            );
        } catch (MailerException $e) {
            // Per the interface contract: log + continue. Email
            // failures must NEVER bubble up to abort the primary
            // action (order placement, payment confirmation, etc.).
            $this->logger->error('notification.send_failed', [
                'to' => $to,
                'template' => $template->value,
                'order_id' => $order->getId(),
                'kind' => $e->kind,
                'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            // Defensive: any other exception (template rendering bug,
            // null deref, etc.) must also not block the caller.
            $this->logger->error('notification.unexpected_error', [
                'to' => $to,
                'template' => $template->value,
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }
    }
}
