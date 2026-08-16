<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

/**
 * Enumeration of all email templates the system can render.
 *
 * Adding a new template:
 *   1. Add a case here
 *   2. Add a method in OrderEmailTemplateRenderer for it
 *   3. Call mailer->send(...) from the appropriate notification
 *      service hook
 *
 * Naming convention: <domain>.<event_kind>.<recipient_role>
 * Examples:
 *   order.placed.customer
 *   order.placed.vendor
 *   order.refunded.customer
 *   order.shipped.customer
 *
 * The string value is included in mailer context for log
 * correlation and in InMemoryMailer's countByTemplate() for tests.
 */
enum EmailTemplate: string
{
    // Customer-facing (their own order moving through lifecycle)
    case ORDER_PLACED_CUSTOMER = 'order.placed.customer';
    case ORDER_PAID_CUSTOMER = 'order.paid.customer';
    case ORDER_PAYMENT_FAILED_CUSTOMER = 'order.payment_failed.customer';
    // Scheduled nudge for an order still awaiting payment (pending_payment)
    // or whose payment failed and can be retried. Sent by the
    // orders:send-payment-reminders cron, not a lifecycle transition.
    // The _2 variant is the follow-up nudge sent ~24h after the first,
    // with more urgent "final reminder" copy; a distinct template string is
    // required so each stage has its own idempotency guard.
    case ORDER_PAYMENT_REMINDER_CUSTOMER = 'order.payment_reminder.customer';
    case ORDER_PAYMENT_REMINDER_2_CUSTOMER = 'order.payment_reminder2.customer';
    case ORDER_ACCEPTED_CUSTOMER = 'order.accepted.customer';
    case ORDER_PREPARING_CUSTOMER = 'order.preparing.customer';
    case ORDER_REJECTED_CUSTOMER = 'order.rejected.customer';
    case ORDER_SHIPPED_CUSTOMER = 'order.shipped.customer';
    case ORDER_DELIVERED_CUSTOMER = 'order.delivered.customer';
    case ORDER_CANCELLED_CUSTOMER = 'order.cancelled.customer';
    case ORDER_REFUNDED_CUSTOMER = 'order.refunded.customer';

    // Generic order status-change (admin override to a status with no
    // dedicated customer template — e.g. paid, fulfilling, shipped,
    // delivered at the order level). EN + AR.
    case ORDER_STATUS_CHANGED_CUSTOMER = 'order.status_changed.customer';

    // Vendor-facing (their items moving through lifecycle)
    case ORDER_PLACED_VENDOR = 'order.placed.vendor';
    case ORDER_CANCELLED_VENDOR = 'order.cancelled.vendor';

    // Admin-facing (critical events for ops monitoring)
    case DISPUTE_OPENED_ADMIN = 'dispute.opened.admin';

    // Admin-facing — an admin manually overrode an order/item status.
    // Surfaces in the admin notification bell + emails ops. English only
    // (Q-VendorAdminLocale = A locked).
    case ORDER_STATUS_CHANGED_ADMIN = 'order.status_changed.admin';

    // M3.2.X.18-G — Return request flow
    // Customer: 6 lifecycle events. Each has EN + AR variants
    //   dispatched by the renderer based on User.locale.
    case RETURN_SUBMITTED_CUSTOMER = 'return.submitted.customer';
    case RETURN_APPROVED_CUSTOMER = 'return.approved.customer';
    case RETURN_DENIED_CUSTOMER = 'return.denied.customer';
    case RETURN_PICKED_UP_CUSTOMER = 'return.picked_up.customer';
    case RETURN_RECEIVED_BY_VENDOR_CUSTOMER = 'return.received_by_vendor.customer';
    case RETURN_REFUNDED_CUSTOMER = 'return.refunded.customer';

    // Vendor: 1 event — heads-up that goods will be returned.
    //   Uses vendor's preferred locale (EN/AR per Q-VendorAdminLocale).
    case RETURN_SUBMITTED_VENDOR = 'return.submitted.vendor';

    // Admin: 1 event — copy on every new submission for queue oversight.
    //   ALWAYS English per Q-VendorAdminLocale = A locked.
    case RETURN_SUBMITTED_ADMIN = 'return.submitted.admin';

    // M3.2.X.11 — Cart abandonment recovery (marketing class)
    // Sent to customers whose active cart has been idle past the
    // configured threshold (default 24h). Gated by the user's
    // marketing_emails_opt_out flag — transactional templates above
    // ignore that flag, marketing templates honour it.
    case CART_ABANDONED_CUSTOMER = 'cart.abandoned.customer';

    /**
     * Marketing-class templates are gated by User::isMarketingEmailsOptedOut().
     * Transactional templates ignore that flag (they're required for
     * the service to function under PDPL).
     *
     * Adding a new marketing template:
     *   1. Add the case above
     *   2. Add it to this MARKETING_TEMPLATES set
     *   3. The notification dispatch layer automatically respects
     *      the opt-out flag for any template in this set
     */
    private const MARKETING_TEMPLATES = [
        'cart.abandoned.customer',
    ];

    public function isMarketing(): bool
    {
        return in_array($this->value, self::MARKETING_TEMPLATES, true);
    }
}
