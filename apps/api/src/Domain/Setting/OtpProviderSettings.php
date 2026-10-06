<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Setting;

/**
 * The admin-editable OTP provider config: which providers are enabled and in
 * what PRIORITY order the router tries them (first success wins, failover on
 * error). Stored under SettingsService key 'otp.providers' as
 *   { "order": ["messagecentral","cequens"], "enabled": {"messagecentral":true,"cequens":true} }
 *
 * fromArray() is defensive: unknown provider keys are dropped, a KNOWN provider
 * missing from the stored order is appended (last) so newly-added providers
 * still appear, and a provider with no explicit enabled flag defaults to true.
 */
final class OtpProviderSettings
{
    /** SettingsService key this config lives under. */
    public const KEY = 'otp.providers';

    /**
     * Every provider the system supports. This order is also the default
     * priority when nothing is configured.
     *
     * @var list<string>
     */
    public const KNOWN = ['messagecentral', 'cequens'];

    /**
     * Human display names for each KNOWN provider, for the admin UI. Single
     * source of truth so controllers / serializers don't re-hardcode them.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'messagecentral' => 'MessageCentral',
        'cequens' => 'Cequens',
    ];

    /**
     * @param list<string> $order
     * @param array<string, bool> $enabled
     */
    private function __construct(
        public readonly array $order,
        public readonly array $enabled,
    ) {
    }

    public static function default(): self
    {
        return new self(self::KNOWN, array_fill_keys(self::KNOWN, true));
    }

    /** @param array<string, mixed>|null $raw */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return self::default();
        }

        $rawOrder = is_array($raw['order'] ?? null) ? $raw['order'] : [];
        $order = [];
        foreach ($rawOrder as $k) {
            if (is_string($k) && in_array($k, self::KNOWN, true) && !in_array($k, $order, true)) {
                $order[] = $k;
            }
        }
        // Append any KNOWN provider the stored order forgot, keeping it usable.
        foreach (self::KNOWN as $k) {
            if (!in_array($k, $order, true)) {
                $order[] = $k;
            }
        }

        $rawEnabled = is_array($raw['enabled'] ?? null) ? $raw['enabled'] : [];
        $enabled = [];
        foreach (self::KNOWN as $k) {
            $enabled[$k] = array_key_exists($k, $rawEnabled) ? (bool) $rawEnabled[$k] : true;
        }

        return new self($order, $enabled);
    }

    /** @return array{order: list<string>, enabled: array<string, bool>} */
    public function toArray(): array
    {
        return ['order' => $this->order, 'enabled' => $this->enabled];
    }

    /**
     * Enabled providers in priority order — what the router tries, top to bottom.
     *
     * @return list<string>
     */
    public function enabledInOrder(): array
    {
        return array_values(array_filter(
            $this->order,
            fn (string $k): bool => $this->enabled[$k] ?? false,
        ));
    }
}
