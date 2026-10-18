<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

/**
 * Decides whether an OTP send failure is an ACCOUNT problem — something on the
 * SMS provider account that a human has to fix (top up, enable pricing, fix
 * credentials) and that will keep failing EVERY send until they do — or an
 * ordinary, usually transient error.
 *
 * Why this exists
 * ---------------
 * Twice in 2026 the MessageCentral account ran OUT OF CREDIT and every SMS OTP
 * failed for days before anyone noticed — both times HTTP 400
 * {"responseCode":508,"message":"insufficient credits",...} (Sentry):
 *   - Sept 18-20: ~520+ failures (212 / 173 / 134 per day).
 *   - Oct 14-18:  ~650 failures, 120+ customers blocked at sign-up / phone
 *     login / phone verification / password reset.
 * Both looked like any other 502 in Sentry. The classifier lets the
 * OtpProviderHealthMonitor alert a human ONLY for this account class.
 *
 * "Pricing not found" (HTTP 400, responseCode 400) is different: in production
 * it was PER CUSTOMER — 7 events from 2 customers (Sept 30, Oct 7) while other
 * sends worked — because MessageCentral prices SMS per destination country.
 * It is still an account problem (someone must ask MessageCentral to price
 * that country), but the monitor scopes it to the destination country.
 *
 * Reasons returned
 * ----------------
 *   insufficient_credit     the account balance is empty
 *   pricing_not_configured  the channel/route we send on has no price / isn't enabled
 *   credentials_rejected    the provider refused our credentials (or the account is suspended)
 *   no_provider             the router had no wired+enabled provider at all
 *   null                    anything else — network, timeout, 5xx, rate limited,
 *                           invalid phone number, malformed response. NOT an
 *                           account problem: no alert (those stay visible in
 *                           Sentry as ordinary errors).
 *
 * Evidence first: an explicit "insufficient credits" / "Pricing not found" in
 * the provider's reply wins over the HTTP status, so a provider that reports a
 * credit problem with an odd status is still caught. Status-only rules come
 * after: 5xx / 429 → transient (null); 401 / 403 or the provider's auth-step
 * 'unauthorized' kind → credentials_rejected; 402 Payment Required → credit.
 *
 * Text patterns are matched with '_' read as a space, because MessageCentral
 * answers in SCREAMING_SNAKE_CASE ("INVALID_CUSTOMER_ID", "REQUEST_ALREADY_EXISTS").
 *
 * Pure and side-effect free; safe to call on the hot path.
 */
final class OtpProviderFailureClassifier
{
    public const INSUFFICIENT_CREDIT = 'insufficient_credit';
    public const PRICING_NOT_CONFIGURED = 'pricing_not_configured';
    public const CREDENTIALS_REJECTED = 'credentials_rejected';
    public const NO_PROVIDER = 'no_provider';

    /** Every reason classify() can return, in a stable order. */
    public const REASONS = [
        self::INSUFFICIENT_CREDIT,
        self::PRICING_NOT_CONFIGURED,
        self::CREDENTIALS_REJECTED,
        self::NO_PROVIDER,
    ];

    /**
     * Exception kinds whose message carries the provider's REPLY (status and/or
     * body). Network / transport / unsupported_country / email_transport never
     * do — they can't be an account problem, so we don't text-match them (a
     * cURL error string must never be mistaken for a provider verdict).
     */
    private const REPLY_KINDS = ['upstream', 'unauthorized', 'missing_id', 'malformed'];

    /**
     * MessageCentral VerifyNow responseCode for "insufficient credits"
     * (observed in prod, Oct 2026). Provider-specific, so only applied to
     * MessageCentral (or when the provider is unknown).
     */
    private const MESSAGECENTRAL_INSUFFICIENT_CREDIT_CODE = 508;

    /**
     * MessageCentral VerifyNow responseCode 501 = INVALID_CUSTOMER_ID (our
     * account id is wrong / no longer valid). Same scoping as 508.
     */
    private const MESSAGECENTRAL_INVALID_CUSTOMER_ID_CODE = 501;

    /** Case-insensitive patterns for "the balance is empty". */
    private const CREDIT_PATTERNS = [
        '/\b(?:insufficient|low|not\s+enough)\s+(?:credits?|balance|funds)\b/i',
        '/\bout\s+of\s+(?:credits?|balance|funds)\b/i',
        '/\bbalance\s+(?:is\s+)?(?:too\s+low|insufficient|not\s+enough|exhausted|empty)\b/i',
        '/\bcredits?\s+(?:exhausted|depleted|finished)\b/i',
    ];

    /** Case-insensitive patterns for "the channel/route isn't priced/enabled". */
    private const PRICING_PATTERNS = [
        '/\bpricing\s+not\s+(?:found|configured|set|available)\b/i',
        '/\bno\s+pricing\b/i',
        '/\bnot\s+priced\b/i',
        '/\b(?:channel|route|routing)\s+(?:is\s+)?not\s+(?:enabled|configured|activated|allowed|priced|set\s*up)\b/i',
    ];

    /** Case-insensitive patterns for rejected credentials / suspended account. */
    private const CREDENTIAL_PATTERNS = [
        '/\b(?:invalid|wrong|incorrect|bad)\s+(?:api\s*key|key|credentials?|customer\s*id|username|user\s*name|password)\b/i',
        '/\bauthentication\s+failed\b/i',
        '/\baccount\s+(?:is\s+)?(?:suspended|blocked|disabled|deactivated|inactive)\b/i',
    ];

    /**
     * @param string $provider the router key of the provider that raised it
     *                         ('messagecentral', 'cequens', ...); '' if unknown
     */
    public function classify(OtpProviderException $e, string $provider = ''): ?string
    {
        if ($e->kind === 'no_provider') {
            return self::NO_PROVIDER;
        }
        if (!in_array($e->kind, self::REPLY_KINDS, true)) {
            return null;
        }

        $message = $e->getMessage();
        // For the text patterns only: "INVALID_CUSTOMER_ID" → "INVALID CUSTOMER ID".
        $text = str_replace('_', ' ', $message);

        // 1. Explicit evidence in the provider's reply (wins over status).
        if ($this->isInsufficientCredit($message, $text, $provider)) {
            return self::INSUFFICIENT_CREDIT;
        }
        if (self::matchesAny(self::PRICING_PATTERNS, $text)) {
            return self::PRICING_NOT_CONFIGURED;
        }

        // 2. Status-only rules.
        $status = self::httpStatus($message);
        if ($status !== null && ($status >= 500 || $status === 429)) {
            // Provider outage / throttling: transient, not the account.
            return null;
        }
        if ($status === 402) {
            return self::INSUFFICIENT_CREDIT;
        }
        if ($e->kind === 'unauthorized' || $status === 401 || $status === 403) {
            // 'unauthorized' is what both providers raise when their AUTH step
            // returns a 4xx (bad key / username / customer id, suspended account).
            return self::CREDENTIALS_REJECTED;
        }
        if (self::matchesAny(self::CREDENTIAL_PATTERNS, $text)) {
            return self::CREDENTIALS_REJECTED;
        }
        if (self::isMessageCentral($provider)
            && in_array(self::MESSAGECENTRAL_INVALID_CUSTOMER_ID_CODE, self::responseCodes($message), true)
        ) {
            return self::CREDENTIALS_REJECTED;
        }

        // Invalid number, bad request, malformed body, missing id, ... → not ours to alert on.
        return null;
    }

    private function isInsufficientCredit(string $message, string $text, string $provider): bool
    {
        if (self::matchesAny(self::CREDIT_PATTERNS, $text)) {
            return true;
        }

        if (self::isMessageCentral($provider)) {
            return in_array(self::MESSAGECENTRAL_INSUFFICIENT_CREDIT_CODE, self::responseCodes($message), true);
        }

        return false;
    }

    /** MessageCentral-specific response codes apply to it, or when the provider is unknown. */
    private static function isMessageCentral(string $provider): bool
    {
        return $provider === '' || $provider === 'messagecentral';
    }

    /**
     * The first "HTTP <nnn>" status embedded in the message (the providers
     * format upstream failures as "HTTP 400: <body>" / "auth HTTP 401: <body>").
     */
    private static function httpStatus(string $message): ?int
    {
        if (preg_match('/\bHTTP\s+(\d{3})\b/', $message, $m) === 1) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Every MessageCentral-style "responseCode" in the reply body embedded in
     * the message, top level and nested (data.responseCode), int or numeric
     * string. Decodes the JSON when it's intact; falls back to a regex scan so
     * a body the provider adapter truncated (it caps at 200 chars + "...") or a
     * slightly malformed one still yields its codes.
     *
     * @return list<int>
     */
    private static function responseCodes(string $message): array
    {
        $codes = [];

        $start = strpos($message, '{');
        if ($start !== false) {
            $json = substr($message, $start);
            $decoded = json_decode($json, true);
            if (!is_array($decoded) && str_ends_with($json, '...')) {
                $decoded = json_decode(substr($json, 0, -3), true);
            }
            if (is_array($decoded)) {
                self::collectResponseCodes($decoded, $codes, 0);
            }
        }

        if (preg_match_all('/"responseCode"\s*:\s*"?(\d{3})"?/i', $message, $m) > 0) {
            foreach ($m[1] as $code) {
                $codes[] = (int) $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param array<mixed> $node
     * @param list<int> $codes
     */
    private static function collectResponseCodes(array $node, array &$codes, int $depth): void
    {
        if ($depth > 3) {
            return;
        }
        foreach ($node as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'responseCode') === 0
                && (is_int($value) || (is_string($value) && ctype_digit($value)))
            ) {
                $codes[] = (int) $value;
            } elseif (is_array($value)) {
                self::collectResponseCodes($value, $codes, $depth + 1);
            }
        }
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(array $patterns, string $subject): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $subject) === 1) {
                return true;
            }
        }
        return false;
    }
}
