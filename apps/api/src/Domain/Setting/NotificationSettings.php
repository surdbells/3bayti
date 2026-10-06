<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Setting;

/**
 * Admin-editable customer-notification toggles, stored under SettingsService
 * key 'notifications.customer' as e.g.
 *   { "suppress_vendor_item_status": true }
 *
 * First (and only) toggle: suppress the per-ITEM status updates that a VENDOR
 * triggers to the CUSTOMER (accepted / preparing / shipped / delivered /
 * rejected). An order with 20 items across many vendors would otherwise spray
 * the customer with ~20 emails + pushes; this switch stops those per-item
 * customer notifications. It does NOT affect order-level customer
 * notifications, vendor-facing notifications, or admin-driven overrides.
 *
 * fromArray() is defensive (a missing/garbled value reads as the safe default:
 * NOT suppressed — notifications keep flowing until an admin opts in). A bucket
 * (not a dedicated key per toggle) so future customer-notification switches
 * live in the same row.
 */
final class NotificationSettings
{
    /** SettingsService key this config lives under. */
    public const KEY = 'notifications.customer';

    private function __construct(
        public readonly bool $suppressVendorItemStatus,
    ) {
    }

    public static function default(): self
    {
        return new self(suppressVendorItemStatus: false);
    }

    /** @param array<string, mixed>|null $raw */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return self::default();
        }

        return new self(
            suppressVendorItemStatus: (bool) ($raw['suppress_vendor_item_status'] ?? false),
        );
    }

    /** @return array{suppress_vendor_item_status: bool} */
    public function toArray(): array
    {
        return ['suppress_vendor_item_status' => $this->suppressVendorItemStatus];
    }
}
