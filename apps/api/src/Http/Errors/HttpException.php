<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Errors;

/**
 * HTTP-aware exception thrown by controllers / validators.
 *
 * Carries:
 *   - HTTP status code (400, 401, 403, 404, 409, 422, 429, ...)
 *   - Error code (one of ErrorCodes::*)
 *   - Optional details payload (per-field validation errors,
 *     debugging info safe to send to the client)
 *   - Public message for the user
 *
 * Caught by ApiErrorMiddleware and turned into the standard
 *   { "error": { "code": "...", "message": "...", "details": {...} } }
 * envelope.
 *
 * Use the named factories (validation(), unauthorized(), conflict(),
 * etc.) instead of new HttpException(...) when possible — they
 * encode status-to-code conventions in one place.
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param int $status HTTP status code (e.g. 401)
     * @param string $errorCode One of the ErrorCodes constants
     * @param string $publicMessage Safe-to-show message
     * @param array<string, mixed> $details Extra context for the client
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $publicMessage,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }

    // -------------------------------------------------------------------
    // Named factories — the everyday way to construct these.
    // -------------------------------------------------------------------

    /**
     * 400 Bad Request — body wasn't parseable or couldn't be processed.
     */
    public static function badRequest(string $message = 'Invalid request.'): self
    {
        return new self(400, ErrorCodes::VALIDATION_BAD_REQUEST, $message);
    }

    /**
     * 401 Unauthorized — authentication failure of any kind.
     */
    public static function unauthorized(
        string $errorCode = ErrorCodes::AUTH_INVALID_CREDENTIALS,
        string $message = 'Authentication failed.',
    ): self {
        return new self(401, $errorCode, $message);
    }

    /**
     * 403 Forbidden — caller is authenticated but not allowed.
     */
    public static function forbidden(string $message = 'You do not have permission.'): self
    {
        return new self(403, ErrorCodes::FORBIDDEN, $message);
    }

    /**
     * 404 Not Found.
     */
    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self(404, ErrorCodes::NOT_FOUND, $message);
    }

    /**
     * 409 Conflict — uniqueness violation (email/phone taken etc.).
     */
    public static function conflict(string $errorCode, string $message): self
    {
        return new self(409, $errorCode, $message);
    }

    /**
     * 422 Unprocessable — body parsed OK but failed validation.
     *
     * @param array<string, list<string>> $fieldErrors Map of fieldName → list of messages
     */
    public static function validation(array $fieldErrors): self
    {
        return new self(
            status: 422,
            errorCode: ErrorCodes::VALIDATION_FAILED,
            publicMessage: 'One or more fields failed validation.',
            details: ['fields' => $fieldErrors],
        );
    }

    /**
     * 429 Too Many Requests — rate limit hit.
     */
    public static function rateLimited(
        string $errorCode = ErrorCodes::OTP_RATE_LIMITED,
        string $message = 'Too many requests. Please try again later.',
    ): self {
        return new self(429, $errorCode, $message);
    }

    /**
     * 502 Bad Gateway — an upstream service we depend on (CPaaS,
     * payment gateway) failed. Our service didn't break; theirs did.
     */
    public static function upstreamFailure(
        string $errorCode = ErrorCodes::OTP_PROVIDER_ERROR,
        string $message = 'Upstream service is temporarily unavailable.',
    ): self {
        return new self(502, $errorCode, $message);
    }

    /**
     * 422 Unprocessable — body validation passed, but a business
     * rule says no (e.g. cart-empty at checkout, can't refund a
     * cancelled order). Different from validation(): the input
     * is *shape*-valid but the *state* makes the request
     * impossible.
     */
    public static function businessRuleViolation(
        string $errorCode = ErrorCodes::BUSINESS_RULE_VIOLATION,
        string $message = 'This operation is not permitted in the current state.',
    ): self {
        return new self(422, $errorCode, $message);
    }
}
