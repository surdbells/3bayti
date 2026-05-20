<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Notification\Dto;

use Bayti\Api\Domain\Notification\DeviceToken;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/device-tokens — register (upsert) a device's
 * push token.
 *
 * token is the FCM registration token (required, non-empty). platform
 * is required and must be one of the supported values (ios | android).
 * The controller upserts by token: a re-registration refreshes the
 * row, reactivates it, and re-owns it to the current user.
 */
final class RegisterDeviceTokenInput
{
    #[Assert\NotBlank(message: 'token is required.')]
    #[Assert\Length(max: 4096, maxMessage: 'token is too long.')]
    public readonly ?string $token;

    #[Assert\NotBlank(message: 'platform is required.')]
    #[Assert\Choice(choices: DeviceToken::PLATFORMS, message: 'platform must be ios or android.')]
    public readonly ?string $platform;

    public function __construct(?string $token = null, ?string $platform = null)
    {
        $this->token = $token !== null ? trim($token) : null;
        $this->platform = $platform !== null ? strtolower(trim($platform)) : null;
    }
}
