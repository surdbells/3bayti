<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\User\User;

/**
 * Locale resolver for email notifications (M3.2.X.7).
 *
 * Maps a recipient email address back to a User/Vendor/admin
 * context and returns the appropriate short locale tag ('en' or
 * 'ar') for the OrderEmailTemplateRenderer.
 *
 * Source of truth per recipient kind (Q-Unification locked in -D)
 * ================================================================
 *
 *   Customer recipient: read User.locale (existing M1.7.0 field;
 *                       BCP-47, may include region like 'en-AE').
 *                       Normalized to short tag via
 *                       normalizeToShortTag().
 *
 *   Vendor recipient:   read Vendor.preferredLocale (added in
 *                       M3.2.X.7-A migration). Already in short-tag
 *                       form because the entity setter validates
 *                       against SUPPORTED_LOCALES.
 *
 *   Admin recipient:    always DEFAULT_LOCALE ('en'). Per
 *                       Q-VendorAdminLocale = A locked.
 *
 *   Unknown recipient:  DEFAULT_LOCALE ('en'). Fail safe.
 *
 * Why customer uses User.locale (not a new preferred_locale field)
 * -----------------------------------------------------------------
 * User.locale has existed since M1.7.0 with explicit docblock intent
 * "Used to: Localise transactional emails (M3)". The original
 * M3.2.X.7 plan's pre-flight inspection missed this and proposed
 * adding a second preferred_locale field. Sub-phase D's pre-flight
 * caught the duplication and the architectural decision was made to
 * unify on the existing field (Q-Unification path).
 *
 * Why vendor uses a new preferred_locale field (not Vendor.locale)
 * -----------------------------------------------------------------
 * Vendor has NO existing locale field. Added in M3.2.X.7-A migration.
 *
 * Normalization
 * =============
 * User.locale is BCP-47 and may include region variants ('en-AE',
 * 'ar-AE'). The renderer only handles short tags ('en', 'ar'). The
 * normalizeToShortTag() helper:
 *
 *   'en'    → 'en'
 *   'en-AE' → 'en'
 *   'ar'    → 'ar'
 *   'ar-AE' → 'ar'
 *   'fr'    → 'en' (unsupported language; fail safe)
 *   ''      → 'en' (empty; fail safe)
 *
 * Region tags are stripped because there's no per-region content to
 * localize (Q-LocaleValues = A locked).
 *
 * Why not snapshot the locale per order
 * =====================================
 * Locale is resolved at SEND time, not at order placement time.
 * Rationale: customers may change preferences between lifecycle
 * events. Acceptable behavior; snapshotting per order is over-
 * engineering for current needs.
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
     * normalized short locale tag if so.
     *
     * Reads the EXISTING User.locale field (BCP-47, M1.7.0) rather
     * than a M3.2.X.7-specific field. See class docblock for
     * unification rationale.
     */
    private function resolveForCustomer(string $recipientEmail, Order $order): ?string
    {
        $customer = $order->getUser();
        if ($customer->getEmail() === '' || $customer->getEmail() !== $recipientEmail) {
            return null;
        }
        return self::normalizeToShortTag($customer->getLocale());
    }

    /**
     * Check if the recipient matches any vendor's contact_email in
     * the order's items; return that vendor's short locale tag if so.
     *
     * Multi-vendor orders may have multiple distinct vendors, but
     * only ONE of them will match the recipient (the email goes to
     * a single vendor at a time). The first matching vendor wins
     * by iteration order — this is deterministic because OrderItem
     * preserves insertion order.
     *
     * Reads the NEW Vendor.preferredLocale field. Already in short-
     * tag form because the entity setter validates against
     * SUPPORTED_LOCALES.
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

    /**
     * Normalize a BCP-47 locale to a supported short tag.
     *
     *   'en'    → 'en'
     *   'en-AE' → 'en'
     *   'ar'    → 'ar'
     *   'ar-AE' → 'ar'
     *   'fr'    → 'en' (unsupported language → default)
     *   ''      → 'en' (empty → default)
     *   null    → 'en' (null → default)
     *
     * Region tags are stripped because there's no per-region content
     * to localize (Q-LocaleValues = A locked).
     *
     * Public so unit tests can exercise the normalization edge cases
     * directly, and so future callers that hold a BCP-47 string but
     * need a short tag can use this helper rather than re-implementing.
     */
    public static function normalizeToShortTag(?string $locale): string
    {
        if ($locale === null || $locale === '') {
            return self::DEFAULT_LOCALE;
        }
        // Strip region: 'en-AE' → 'en'
        $primary = strtolower(explode('-', $locale)[0]);
        if (in_array($primary, User::SUPPORTED_LOCALES, true)) {
            return $primary;
        }
        return self::DEFAULT_LOCALE;
    }
}
