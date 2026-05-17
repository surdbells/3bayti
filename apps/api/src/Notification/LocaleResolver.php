<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;

/**
 * Locale resolver for email notifications (M3.2.X.7).
 *
 * Maps a recipient email address back to a User/Vendor/admin
 * context and returns the appropriate locale string for the
 * OrderEmailTemplateRenderer.
 *
 * Decision tree (Q-FallbackBehavior + Q-VendorAdminLocale = A locked)
 * ===================================================================
 *
 *   1. If recipient matches Order.user.email (the customer's email)
 *      → return Order.user.preferredLocale (or DEFAULT_LOCALE if null)
 *
 *   2. If recipient matches any Vendor.contact_email in the order's
 *      items (vendor-facing emails)
 *      → return that Vendor.preferredLocale (or DEFAULT_LOCALE if null)
 *
 *   3. If recipient matches any of the configured admin recipient
 *      email addresses
 *      → always return DEFAULT_LOCALE (admin emails are always
 *        English; Q-VendorAdminLocale = A locked)
 *
 *   4. Otherwise (unknown recipient, e.g. operator-CC'd email,
 *      legacy recipients)
 *      → return DEFAULT_LOCALE (fail safe to English)
 *
 * Why not snapshot the locale per order
 * =====================================
 * Locale is resolved at SEND time, not at order placement time.
 * Rationale: customers may change preferences between lifecycle
 * events (e.g. order placed in English, customer switches to
 * Arabic, then ships → wants Arabic). Acceptable behavior;
 * snapshotting per order is over-engineering for current needs.
 *
 * Why an explicit service vs inline logic
 * ========================================
 * The decision tree has 4 branches with distinct semantics; a
 * tested unit covers each branch independently. Inline logic in
 * OrderNotificationService would muddy the responsibility boundary
 * + make the routing harder to reason about and modify.
 */
final class LocaleResolver
{
    /**
     * Default locale used when no preference is found anywhere.
     * Q-FallbackBehavior = A locked: English preserves current
     * behavior for users who haven't opted into Arabic.
     */
    public const DEFAULT_LOCALE = User::LOCALE_EN;

    /**
     * Resolve the locale for a given recipient + order context.
     *
     * @param string $recipientEmail The email address the
     *        notification is being sent to.
     * @param Order $order The order being notified about. Provides
     *        the customer (Order.user) and vendor context (Order.items
     *        → Vendor entities).
     * @param list<string> $adminRecipients The configured admin
     *        recipient list (empty list means no admin emails).
     *
     * @return string One of User::SUPPORTED_LOCALES (currently 'en'
     *        or 'ar').
     */
    public function resolveForRecipient(
        string $recipientEmail,
        Order $order,
        array $adminRecipients = [],
    ): string {
        // Step 1: Customer email match
        $customerLocale = $this->resolveForCustomer($recipientEmail, $order);
        if ($customerLocale !== null) {
            return $customerLocale;
        }

        // Step 2: Vendor email match
        $vendorLocale = $this->resolveForVendor($recipientEmail, $order);
        if ($vendorLocale !== null) {
            return $vendorLocale;
        }

        // Step 3: Admin recipient match — locked to English
        if (in_array($recipientEmail, $adminRecipients, true)) {
            return self::DEFAULT_LOCALE;
        }

        // Step 4: Unknown recipient — fail safe to English
        return self::DEFAULT_LOCALE;
    }

    /**
     * Check if the recipient is the order's customer; return their
     * preferred locale if so.
     */
    private function resolveForCustomer(string $recipientEmail, Order $order): ?string
    {
        $customer = $order->getUser();
        if ($customer->getEmail() === '' || $customer->getEmail() !== $recipientEmail) {
            return null;
        }
        return $customer->getPreferredLocale() ?? self::DEFAULT_LOCALE;
    }

    /**
     * Check if the recipient matches any vendor's contact_email in
     * the order's items; return that vendor's preferred locale if so.
     *
     * Multi-vendor orders may have multiple distinct vendors, but
     * only ONE of them will match the recipient (the email goes to
     * a single vendor at a time). The first matching vendor wins
     * by iteration order — this is deterministic because OrderItem
     * preserves insertion order.
     */
    private function resolveForVendor(string $recipientEmail, Order $order): ?string
    {
        foreach ($order->getItems() as $item) {
            $vendor = $item->getVendor();
            // Vendor.contactEmail is a non-nullable typed property; an
            // uninitialized state can theoretically be reached via
            // reflection-based test construction. Defensive try/catch
            // mirrors the pattern in OrderNotificationService::sendToVendors.
            try {
                $vendorEmail = $vendor->getContactEmail();
            } catch (\Error $e) {
                continue;
            }
            if ($vendorEmail === $recipientEmail) {
                return $vendor->getPreferredLocale() ?? self::DEFAULT_LOCALE;
            }
        }
        return null;
    }
}
