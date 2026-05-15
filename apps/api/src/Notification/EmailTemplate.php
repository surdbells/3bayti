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
    case ORDER_SHIPPED_CUSTOMER = 'order.shipped.customer';
    case ORDER_DELIVERED_CUSTOMER = 'order.delivered.customer';
    case ORDER_CANCELLED_CUSTOMER = 'order.cancelled.customer';
    case ORDER_REFUNDED_CUSTOMER = 'order.refunded.customer';

    // Vendor-facing (their items moving through lifecycle)
    case ORDER_PLACED_VENDOR = 'order.placed.vendor';
    case ORDER_CANCELLED_VENDOR = 'order.cancelled.vendor';

    // Admin-facing (critical events for ops monitoring)
    case DISPUTE_OPENED_ADMIN = 'dispute.opened.admin';
}
