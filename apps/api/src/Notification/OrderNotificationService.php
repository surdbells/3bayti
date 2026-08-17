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
        // M3.2.X.7-B: locale routing. Optional with null default
        // for backwards compatibility — existing callers/tests that
        // don't pass a resolver preserve current English-only
        // behavior (resolver missing → render() uses its default
        // locale='en' parameter).
        private readonly ?LocaleResolver $localeResolver = null,
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
        $this->sendToCustomer(
            $order,
            EmailTemplate::ORDER_PAID_CUSTOMER,
            $this->giftCardContext($order),
        );
    }

    /**
     * Extra template context for a gift-card PURCHASE order.
     *
     * Such an order is synthetic (no line items), so the confirmation email
     * has nothing to describe unless we resolve the funded card and pass its
     * details through. Returns an empty array for ordinary product orders.
     *
     * @return array<string, mixed>
     */
    private function giftCardContext(Order $order): array
    {
        try {
            /** @var \Bayti\Api\Domain\GiftCard\GiftCardRepository $repo */
            $repo = $this->em?->getRepository(\Bayti\Api\Domain\GiftCard\GiftCard::class);
            $card = $repo?->findByPurchaseOrderReference($order->getOrderReference());
        } catch (\Throwable) {
            return [];
        }
        if ($card === null) {
            return [];
        }

        return [
            'gift_card' => [
                'code'           => $card->formattedCode(),
                'denomination'   => $card->getDenomination(),
                'balance'        => $card->getBalance(),
                'currency'       => $card->getCurrency(),
                'theme'          => $card->getTheme(),
                'recipient_name' => $card->getRecipientName(),
                'message'        => $card->getRecipientMessage(),
                'expires_at'     => $card->getExpiresAt()?->format('d M Y'),
                'scheduled_at'   => $card->getScheduledDeliveryAt()?->format('d M Y'),
                // Whether we will deliver it for them, or they share the code.
                'auto_delivered' => $card->getRecipientEmail() !== null || $card->getRecipientPhone() !== null,
            ],
        ];
    }

    /**
     * Notify each vendor in the order that they have a new, PAID order to
     * prepare. Vendor-only — the customer is notified separately by
     * orderPaid().
     *
     * This is the vendor half of the order-confirmation that used to be
     * carried by orderPlaced() (which fired pre-payment). Vendors must
     * only be told to prepare an order once its payment is confirmed, so
     * the "new order to prepare" email now fires on the paid transition,
     * not when the customer merely reaches the gateway. Reuses the
     * existing ORDER_PLACED_VENDOR template (its copy is payment-agnostic
     * — "you have a new order").
     */
    public function orderPaidVendors(Order $order): void
    {
        $this->sendToVendors($order, EmailTemplate::ORDER_PLACED_VENDOR);
    }

    /**
     * Payment failed (webhook). Customer-only.
     */
    public function orderPaymentFailed(Order $order): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_PAYMENT_FAILED_CUSTOMER);
    }

    /**
     * Scheduled reminder that an order still needs payment. Customer-only.
     * Fired by the orders:send-payment-reminders cron (not a lifecycle
     * transition). `$reason` is 'pending' (order never paid) or 'failed'
     * (charge attempt failed, retryable) and only tunes the copy.
     *
     * `$followup` selects the second-stage template (a distinct string so it
     * has its own idempotency guard) with more urgent "final reminder" copy,
     * sent ~24h after the first reminder.
     */
    public function orderPaymentReminder(Order $order, string $reason = 'pending', bool $followup = false): void
    {
        $template = $followup
            ? EmailTemplate::ORDER_PAYMENT_REMINDER_2_CUSTOMER
            : EmailTemplate::ORDER_PAYMENT_REMINDER_CUSTOMER;
        $this->sendToCustomer($order, $template, [
            'reason' => $reason,
            'final' => $followup,
        ]);
    }

    /**
     * A single item was accepted by its vendor (the seller confirmed it
     * and will prepare it). Customer-only.
     */
    public function itemAccepted(Order $order, OrderItem $item): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_ACCEPTED_CUSTOMER, [
            'item_name' => $item->getProductNameSnapshot(),
            'order_item' => $item,
        ]);
    }

    /**
     * A single item moved into preparation. Customer-only.
     */
    public function itemPreparing(Order $order, OrderItem $item): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_PREPARING_CUSTOMER, [
            'item_name' => $item->getProductNameSnapshot(),
            'order_item' => $item,
        ]);
    }

    /**
     * A single item was rejected by its vendor (cannot be fulfilled).
     * Customer-only.
     */
    public function itemRejected(Order $order, OrderItem $item): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_REJECTED_CUSTOMER, [
            'item_name' => $item->getProductNameSnapshot(),
            'order_item' => $item,
        ]);
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
            'order_item' => $item,
        ]);
    }

    /**
     * A single item was marked delivered.
     */
    public function itemDelivered(Order $order, OrderItem $item): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_DELIVERED_CUSTOMER, [
            'item_name' => $item->getProductNameSnapshot(),
            'order_item' => $item,
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
     * Generic customer notification for an order-status change that has
     * no dedicated lifecycle template (e.g. an admin override that forces
     * the order to paid / fulfilling / shipped / delivered at the
     * order level). Customer-only.
     *
     * Callers that already have a specific method (orderCancelled,
     * orderRefunded, item* …) should prefer those — this is the
     * catch-all for the admin override endpoint.
     */
    public function orderStatusChanged(Order $order, string $newStatus): void
    {
        $this->sendToCustomer($order, EmailTemplate::ORDER_STATUS_CHANGED_CUSTOMER, [
            'new_status' => $newStatus,
        ]);
    }

    /**
     * Admin/staff notification that an admin manually overrode an order
     * or item status. Modeled on disputeOpened: emails every configured
     * ADMIN_NOTIFICATION_EMAILS recipient (each safeSend writes a
     * status='sent' NotificationLog keyed to that recipient, which is
     * what surfaces in the admin notification bell). Degrades cleanly —
     * empty recipient list logs + persists a single skipped row.
     *
     * @param array{
     *   new_status?: string,
     *   old_status?: string,
     *   scope?: string,
     *   item_name?: string,
     *   reason?: string
     * } $details Extra context forwarded to the renderer.
     * @param ?string $actor Email/identifier of the admin who made the
     *        change, surfaced in the email + bell for accountability.
     */
    public function adminOrderStatusChanged(
        Order $order,
        string $newStatus,
        ?string $actor = null,
        array $details = [],
    ): void {
        $extra = array_merge($details, ['new_status' => $newStatus]);
        if ($actor !== null && $actor !== '') {
            $extra['actor'] = $actor;
        }

        if ($this->adminRecipients === []) {
            $this->logger->info('notification.order_status_changed.no_admin_recipients_configured', [
                'order_id' => $order->getId(),
                'new_status' => $newStatus,
            ]);
            $this->persistSkipped(
                $order,
                EmailTemplate::ORDER_STATUS_CHANGED_ADMIN,
                '',
                self::SKIP_REASON_NO_ADMIN_RECIPIENTS,
            );
            return;
        }
        foreach ($this->adminRecipients as $adminEmail) {
            $this->safeSend(
                to: $adminEmail,
                template: EmailTemplate::ORDER_STATUS_CHANGED_ADMIN,
                order: $order,
                extra: $extra,
            );
        }
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
    // M3.2.X.18-G — Return request flow hooks
    // -----------------------------------------------------------------
    //
    // Each method takes the existing $order (so we can reuse the
    // existing safeSend / locale resolver / log persistence pipeline)
    // PLUS a $details array carrying the return-specific context:
    //
    //   return_reference        — e.g. 'RET-7' for human display
    //   reason                  — OrderReturnRequest reason code
    //   customer_notes          — customer's free text (submitted)
    //   admin_notes             — admin's explanation (approved/denied)
    //   returned_items          — list of product names being returned
    //   refund_amount, refund_method, refund_reference, refund_currency
    //                           — only on the refunded event
    //   vendor_email            — only when sending the vendor copy of
    //                             return_submitted (the caller picks
    //                             which vendor recipient to address)
    //
    // The "submit" event fans out to 3 recipients (customer + per-vendor
    // + admins); the rest target the customer only. This matches the
    // Q-Notifications decision locked in the X.18 plan.

    /**
     * Customer just submitted a new return request.
     * Sends customer + vendor(s) + admin copies.
     *
     * @param array{
     *   return_reference?: string,
     *   reason?: string,
     *   customer_notes?: ?string,
     *   returned_items?: list<string>,
     *   vendor_ids?: list<int>,
     * } $details
     */
    public function returnSubmitted(Order $order, array $details = []): void
    {
        // Customer copy.
        $this->sendToCustomer($order, EmailTemplate::RETURN_SUBMITTED_CUSTOMER, $details);

        // Vendor copies — one per unique vendor whose items are being
        // returned (filtered by the vendor_ids passed in details).
        // We narrow via sendToReturnVendors so a vendor whose products
        // aren't in this return doesn't get spammed.
        $this->sendToReturnVendors(
            $order,
            EmailTemplate::RETURN_SUBMITTED_VENDOR,
            $details,
            $details['vendor_ids'] ?? [],
        );

        // Admin copies — one per configured admin recipient.
        if ($this->adminRecipients === []) {
            $this->logger->info('notification.return.no_admin_recipients_configured', [
                'order_id' => $order->getId(),
                'return_reference' => $details['return_reference'] ?? null,
            ]);
            $this->persistSkipped(
                $order,
                EmailTemplate::RETURN_SUBMITTED_ADMIN,
                '',
                self::SKIP_REASON_NO_ADMIN_RECIPIENTS,
            );
        } else {
            foreach ($this->adminRecipients as $adminEmail) {
                $this->safeSend(
                    to: $adminEmail,
                    template: EmailTemplate::RETURN_SUBMITTED_ADMIN,
                    order: $order,
                    extra: $details,
                );
            }
        }
    }

    /**
     * Admin approved the return. Customer-only.
     *
     * @param array{
     *   return_reference?: string,
     *   admin_notes?: ?string,
     * } $details
     */
    public function returnApproved(Order $order, array $details = []): void
    {
        $this->sendToCustomer($order, EmailTemplate::RETURN_APPROVED_CUSTOMER, $details);
    }

    /**
     * Admin denied the return. Customer-only. admin_notes is required
     * upstream (entity invariant), so the template will always have
     * something to render.
     *
     * @param array{
     *   return_reference?: string,
     *   admin_notes?: string,
     * } $details
     */
    public function returnDenied(Order $order, array $details = []): void
    {
        $this->sendToCustomer($order, EmailTemplate::RETURN_DENIED_CUSTOMER, $details);
    }

    /**
     * Logistics picked up the items from the customer. Customer-only.
     *
     * @param array{ return_reference?: string } $details
     */
    public function returnPickedUp(Order $order, array $details = []): void
    {
        $this->sendToCustomer($order, EmailTemplate::RETURN_PICKED_UP_CUSTOMER, $details);
    }

    /**
     * Vendor confirmed physical receipt of the returned goods.
     * Customer-only (vendor doesn't need a copy — they just
     * triggered this).
     *
     * @param array{ return_reference?: string } $details
     */
    public function returnReceivedByVendor(Order $order, array $details = []): void
    {
        $this->sendToCustomer(
            $order,
            EmailTemplate::RETURN_RECEIVED_BY_VENDOR_CUSTOMER,
            $details,
        );
    }

    /**
     * Admin recorded the refund. Customer-only (terminal event).
     *
     * @param array{
     *   return_reference?: string,
     *   refund_amount?: string,
     *   refund_currency?: string,
     *   refund_method?: string,
     *   refund_reference?: ?string,
     * } $details
     */
    public function returnRefunded(Order $order, array $details = []): void
    {
        $this->sendToCustomer($order, EmailTemplate::RETURN_REFUNDED_CUSTOMER, $details);
    }

    /**
     * Variant of sendToVendors that filters to a specific list of
     * vendor ids — used by return notifications where only the
     * vendors whose items are being returned should be emailed
     * (not all vendors on the order).
     *
     * Falls back to all vendors on the order if $vendorIds is empty,
     * matching the sendToVendors default behavior.
     *
     * @param array<string, mixed> $extra
     * @param list<int> $vendorIds
     */
    private function sendToReturnVendors(
        Order $order,
        EmailTemplate $template,
        array $extra,
        array $vendorIds,
    ): void {
        if ($vendorIds === []) {
            $this->sendToVendors($order, $template, $extra);
            return;
        }
        /** @var array<int, array{vendor: Vendor, items: list<string>}> $byVendor */
        $byVendor = [];
        foreach ($order->getItems() as $item) {
            /** @var OrderItem $item */
            $vendor = $item->getVendor();
            $vid = $vendor->getId() ?? 0;
            if (!in_array($vid, $vendorIds, true)) {
                continue;
            }
            if (!isset($byVendor[$vid])) {
                $byVendor[$vid] = ['vendor' => $vendor, 'items' => []];
            }
            $byVendor[$vid]['items'][] = $item->getProductNameSnapshot();
        }

        foreach ($byVendor as $group) {
            $vendor = $group['vendor'];
            try {
                $email = $vendor->getContactEmail();
            } catch (\Error) {
                $this->logger->warning('notification.return.vendor.contact_email_unset', [
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
                $this->logger->warning('notification.return.vendor.no_email', [
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
        /** @var array<int, array{vendor: Vendor, items: list<string>, orderItems: list<OrderItem>}> $byVendor */
        $byVendor = [];
        foreach ($order->getItems() as $item) {
            /** @var OrderItem $item */
            $vendor = $item->getVendor();
            $vid = $vendor->getId() ?? 0;
            if (!isset($byVendor[$vid])) {
                $byVendor[$vid] = ['vendor' => $vendor, 'items' => [], 'orderItems' => []];
            }
            $byVendor[$vid]['items'][] = $item->getProductNameSnapshot();
            $byVendor[$vid]['orderItems'][] = $item;
        }

        foreach ($byVendor as $group) {
            $vendor = $group['vendor'];
            $email = $this->resolveVendorEmail($vendor);
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
            $vendorExtra = array_merge($extra, [
                'vendor_items' => $group['items'],
                // Rich item objects so the vendor email reuses the full
                // OrderDetailsMessageBuilder snapshot (same content as the
                // order chat). Falls back to the names above if a caller
                // doesn't pass these.
                'vendor_order_items' => $group['orderItems'],
                'vendor_name' => $vendor->getName(),
            ]);
            $this->safeSend($email, $template, $order, $vendorExtra);
        }
    }

    /**
     * Resolve the email to notify a vendor at. Prefers the vendor's dedicated
     * contact email; falls back to the OWNER ACCOUNT's email when no contact
     * email is set. Without this, a vendor with no separate contact address
     * silently received NO new-order email even though the order chat opened
     * fine (the chat keys off the owner user, not contactEmail). The typed
     * contactEmail property may be uninitialized on legacy/placeholder
     * vendors, hence the \Error guard.
     */
    private function resolveVendorEmail(Vendor $vendor): string
    {
        try {
            $email = $vendor->getContactEmail();
        } catch (\Error) {
            $email = '';
        }
        if ($email !== '') {
            return $email;
        }
        $owner = $vendor->getOwnerUser();
        return $owner !== null ? $owner->getEmail() : '';
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
        // M3.2.X.7-B: Resolve recipient's preferred locale before
        // rendering. If no resolver injected (legacy DI / test setup
        // without locale awareness), defaults to English via the
        // renderer's default parameter.
        $locale = $this->localeResolver?->resolveForRecipient(
            recipientEmail: $to,
            order: $order,
            adminRecipients: $this->adminRecipients,
        ) ?? \Bayti\Api\Domain\User\User::LOCALE_EN;

        try {
            $rendered = $this->renderer->render($template, $order, $extra, $locale);
            $this->mailer->send(
                to: $to,
                subject: $rendered->subject,
                textBody: $rendered->textBody,
                htmlBody: $rendered->htmlBody,
                context: [
                    'template' => $template->value,
                    'order_id' => $order->getId(),
                    'order_reference' => $order->getOrderReference(),
                    'locale' => $locale,
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
