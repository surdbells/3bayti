<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Notification\NotificationLog;
use Bayti\Api\Domain\Notification\NotificationLogRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Doctrine\ORM\EntityManagerInterface;
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
 * Observability — notification_logs persistence (M3.2.X.4)
 * =========================================================
 * Every safeSend() call writes exactly one NotificationLog row
 * regardless of outcome:
 *   - status='sent'    when mailer->send() returns
 *   - status='failed'  when MailerException or generic \Throwable caught
 *   - status='skipped' when a guard short-circuits before sending
 *                       (no_email, contact_email_unset,
 *                        no_admin_recipients)
 *
 * Log persistence is wrapped in its own try/catch — if writing the
 * audit row itself fails, we log to PSR-3 and continue. The
 * notification log MUST NEVER block the primary action (the email
 * send + the controller response).
 *
 * Why EntityManager instead of NotificationLogRepository directly
 * ===============================================================
 * Construction-time `$em->getRepository(NotificationLog::class)`
 * eagerly triggers Doctrine metadata loading, which in test setups
 * with mocked EM may not have the entity registered. Holding the
 * EM and resolving the repository LAZILY inside safePersist() means:
 *   - Test mocks just need to wire NotificationLog into their
 *     willReturnMap when they care about persistence
 *   - Tests that don't care work unchanged (mock returns null →
 *     persistence becomes a no-op)
 *   - Production behavior is unchanged — single getRepository call
 *     per send attempt, fully cached after the first lookup
 *
 * Idempotency
 * ===========
 * Notifications are fire-and-forget; callers should ensure they
 * call notification methods only once per legitimate transition.
 * The notification_logs table now records duplicates but does NOT
 * deduplicate — adding an idempotency key is a future phase if
 * genuine duplicate-send incidents surface.
 */
final class OrderNotificationService
{
    /**
     * Short reason codes used in the error_message column of
     * status='skipped' NotificationLog rows. Stable taxonomy so
     * admin queries can filter / group by these values.
     */
    private const SKIP_REASON_CUSTOMER_NO_EMAIL = 'no_email';
    private const SKIP_REASON_VENDOR_CONTACT_EMAIL_UNSET = 'contact_email_unset';
    private const SKIP_REASON_VENDOR_NO_EMAIL = 'no_email';
    private const SKIP_REASON_NO_ADMIN_RECIPIENTS = 'no_admin_recipients';

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
        private readonly ?EntityManagerInterface $em = null,
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
            $this->persistSkipped(
                $order,
                EmailTemplate::DISPUTE_OPENED_ADMIN,
                '',
                self::SKIP_REASON_NO_ADMIN_RECIPIENTS,
            );
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
            $this->persistSkipped($order, $template, '', self::SKIP_REASON_CUSTOMER_NO_EMAIL);
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
                $this->persistSkipped(
                    $order,
                    $template,
                    '',
                    self::SKIP_REASON_VENDOR_CONTACT_EMAIL_UNSET,
                );
                continue;
            }
            if ($email === '') {
                $this->logger->warning('notification.vendor.no_email', [
                    'order_id' => $order->getId(),
                    'vendor_id' => $vendor->getId(),
                    'template' => $template->value,
                ]);
                $this->persistSkipped(
                    $order,
                    $template,
                    '',
                    self::SKIP_REASON_VENDOR_NO_EMAIL,
                );
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
            $this->persistSent($order, $template, $to);
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
            $this->persistFailed($order, $template, $to, $e->kind, $e->getMessage());
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
            $this->persistFailed($order, $template, $to, $e::class, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // notification_logs persistence (M3.2.X.4-B)
    // -----------------------------------------------------------------
    //
    // All three persist* helpers wrap the repository save() call in a
    // try/catch. If logging the log itself fails, we record that to
    // PSR-3 and continue — the notification log is a secondary
    // concern; it must NEVER block the primary action (the email send
    // succeeded; the controller response must proceed).
    //
    // The $logRepository nullable means tests + dev environments can
    // construct the service without wiring a repository (NullLogger
    // pattern). When null, persistence is a no-op.
    // -----------------------------------------------------------------

    private function persistSent(Order $order, EmailTemplate $template, string $recipient): void
    {
        $this->safePersist(NotificationLog::sent(
            orderId: $order->getId(),
            template: $template->value,
            recipient: $recipient,
        ));
    }

    private function persistFailed(
        Order $order,
        EmailTemplate $template,
        string $recipient,
        string $errorKind,
        string $errorMessage,
    ): void {
        $this->safePersist(NotificationLog::failed(
            orderId: $order->getId(),
            template: $template->value,
            recipient: $recipient,
            errorKind: $errorKind,
            errorMessage: $errorMessage,
        ));
    }

    private function persistSkipped(
        Order $order,
        EmailTemplate $template,
        string $recipient,
        string $reason,
    ): void {
        $this->safePersist(NotificationLog::skipped(
            orderId: $order->getId(),
            template: $template->value,
            recipient: $recipient,
            reason: $reason,
        ));
    }

    /**
     * Persist a NotificationLog row, catching repository failures so
     * they never propagate to the caller. The notification log must
     * never block the primary action.
     *
     * Repository resolution is LAZY (per request) — see the class
     * docblock for the rationale. In test environments where the EM
     * mock doesn't return a NotificationLogRepository for the entity,
     * the repository lookup returns null and persistence becomes a
     * no-op without raising.
     */
    private function safePersist(NotificationLog $log): void
    {
        if ($this->em === null) {
            return;
        }
        try {
            $repo = $this->em->getRepository(NotificationLog::class);
            if (!$repo instanceof NotificationLogRepository) {
                // Test EM mocks may return null or the base
                // EntityRepository for unmapped classes. Treat this
                // as 'no persistence configured' rather than failing.
                return;
            }
            $repo->save($log);
        } catch (\Throwable $e) {
            // The audit-of-the-audit. PSR-3 captures the persistence
            // failure for ops while we continue. Two ways this can
            // matter in practice: (a) database connection issues that
            // would also affect the primary action's persistence
            // (we'll see those failures in the primary action anyway),
            // (b) schema drift / constraint violations (rare; surface
            // here for triage).
            $this->logger->error('notification.log_persist_failed', [
                'template' => $log->getTemplate(),
                'recipient' => $log->getRecipient(),
                'order_id' => $log->getOrderId(),
                'status' => $log->getStatus(),
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }
    }
}
