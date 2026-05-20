<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Notification\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for DELETE /v3/me/device-tokens — deactivate a device's push
 * token (logout / opt-out).
 *
 * token is required. The controller deactivates it only if it belongs
 * to the current user; unknown / not-owned tokens are a no-op success
 * (idempotent — the caller's goal "this token should not receive my
 * pushes" is already true).
 */
final class DeleteDeviceTokenInput
{
    #[Assert\NotBlank(message: 'token is required.')]
    #[Assert\Length(max: 4096, maxMessage: 'token is too long.')]
    public readonly ?string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token !== null ? trim($token) : null;
    }
}
