<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Setting\OtpProviderSettings;

/**
 * Shapes the OTP-provider config for the admin API.
 *
 * Output: providers listed in PRIORITY order, each with its display label, its
 * enabled flag, whether it is CONFIGURED (has the env credentials the DI
 * factory needs to actually wire it), and its 1-based priority position. The
 * `default_provider` marks the one that validates legacy / unprefixed codes.
 *
 * `configured` is advisory for the UI ("Cequens: credentials not set on the
 * server") — it mirrors the DI factory's credential checks so an admin can see
 * why an enabled provider might not be delivering.
 */
final class OtpProviderSettingsSerializer
{
    public const DEFAULT_PROVIDER = 'messagecentral';

    /** @return array{providers: list<array{key: string, label: string, enabled: bool, configured: bool, position: int}>, default_provider: string} */
    public function shape(OtpProviderSettings $settings): array
    {
        $providers = [];
        $position = 1;
        foreach ($settings->order as $key) {
            $providers[] = [
                'key' => $key,
                'label' => OtpProviderSettings::LABELS[$key] ?? $key,
                'enabled' => $settings->enabled[$key] ?? false,
                'configured' => self::isConfigured($key),
                'position' => $position,
            ];
            $position++;
        }

        return [
            'providers' => $providers,
            'default_provider' => self::DEFAULT_PROVIDER,
        ];
    }

    /**
     * Whether $key has the server credentials the DI factory needs to wire it.
     * Mirrors config/di.php's OtpProvider factory.
     */
    public static function isConfigured(string $key): bool
    {
        return match ($key) {
            'messagecentral' => self::envSet('MESSAGECENTRAL_CUSTOMER_ID')
                && self::envSet('MESSAGECENTRAL_KEY')
                && self::envSet('MESSAGECENTRAL_EMAIL'),
            'cequens' => self::envSet('CEQUENS_API_KEY') && self::envSet('CEQUENS_USERNAME'),
            default => false,
        };
    }

    private static function envSet(string $name): bool
    {
        $v = $_ENV[$name] ?? '';
        return is_string($v) && $v !== '';
    }
}
