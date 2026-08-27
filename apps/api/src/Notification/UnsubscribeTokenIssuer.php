<?php

declare(strict_types=1);

namespace Bayti\Api\Notification;

use Bayti\Api\Infrastructure\Auth\JwtSettings;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Issue + verify signed unsubscribe tokens (M3.2.X.11-E).
 *
 * The unsubscribe URL embedded in every marketing email contains
 * a signed JWT. Hitting the unsubscribe endpoint with a valid
 * token sets users.marketing_emails_opt_out = TRUE for that user.
 *
 * Q-UnsubscribeFlow = A locked: signed-token public endpoint, no
 * login required. Requiring login to unsubscribe is arguably non-
 * compliant under UAE PDPL Article 13 (right to withdraw consent
 * must be 'as simple as giving consent').
 *
 * Token claims
 * ============
 *   sub:    user id
 *   action: 'unsubscribe' (audience tag, refuses any token
 *                          issued for a different purpose)
 *   iat:    issued-at
 *   exp:    +30 days (long-lived; recipients may not open the
 *                     email immediately, and we want the link
 *                     to keep working)
 *
 * Token leak risk
 * ===============
 * If the email forwards to someone else, that recipient can
 * unsubscribe the original user. Acceptable per industry norms
 * (Mailchimp, Klaviyo, etc. all use this pattern). The token
 * only sets opt_out=TRUE, no other state mutations possible.
 * User can opt back in via account settings (future feature).
 *
 * Signing reuses JwtSettings::signingSecret to avoid introducing
 * a second crypto key, but uses a distinct 'action' claim so an
 * access token can never accidentally satisfy unsubscribe and
 * vice versa.
 */
final class UnsubscribeTokenIssuer
{
    public const ACTION = 'unsubscribe';
    private const ALGORITHM = 'HS256';
    private const TTL_DAYS = 30;

    public function __construct(
        private readonly JwtSettings $settings,
    ) {
    }

    public function issue(int $userId, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable();
        $payload = [
            'sub' => (string) $userId,
            'action' => self::ACTION,
            'iat' => $now->getTimestamp(),
            'exp' => $now->modify('+' . self::TTL_DAYS . ' days')->getTimestamp(),
        ];
        return JWT::encode($payload, $this->settings->signingSecret, self::ALGORITHM);
    }

    /**
     * Verify a token. Returns the embedded user_id on success,
     * null on any failure (bad signature, expired, wrong action,
     * malformed). Opaque on failure, caller decides the UX
     * response.
     */
    public function verify(string $token): ?int
    {
        try {
            $decoded = JWT::decode(
                $token,
                new Key($this->settings->signingSecret, self::ALGORITHM),
            );
            $action = $decoded->action ?? null;
            if (!is_string($action) || $action !== self::ACTION) {
                return null;
            }
            $sub = $decoded->sub ?? null;
            if (!is_string($sub) || !ctype_digit($sub)) {
                return null;
            }
            return (int) $sub;
        } catch (\Throwable) {
            return null;
        }
    }
}
