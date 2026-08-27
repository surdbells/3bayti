<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for PATCH /v3/me/notification-preferences.
 *
 * Single required field. Unlike the location PATCH (merge-patch with all
 * fields optional), this endpoint exists solely to flip the marketing
 * push opt-out flag, so the flag is required, sending an empty body is
 * a 422 rather than a silent no-op.
 *
 * `marketing_opt_out` maps to User.marketingPushOptOut:
 *   true  = opt OUT of marketing push (cart.abandoned, order.review_prompt,
 *           re_engagement.nudge are suppressed)
 *   false = opt back IN
 *
 * Order-lifecycle pushes are unaffected either way, they are
 * transactional and always send.
 */
final class UpdateNotificationPreferencesInput
{
    /**
     * Required. Whether the user opts OUT of marketing push.
     *
     * #[Assert\NotNull] (not NotBlank, false is a valid, meaningful
     * value and NotBlank would reject it). A missing key hydrates to
     * null and fails this constraint → 422.
     */
    #[Assert\NotNull(message: 'marketing_opt_out is required and must be a boolean.')]
    #[Assert\Type(type: 'bool', message: 'marketing_opt_out must be a boolean.')]
    public readonly ?bool $marketing_opt_out;

    public function __construct(?bool $marketing_opt_out = null)
    {
        $this->marketing_opt_out = $marketing_opt_out;
    }
}
