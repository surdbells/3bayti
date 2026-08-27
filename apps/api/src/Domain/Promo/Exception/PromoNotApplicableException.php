<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Promo\Exception;

use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;

/**
 * Typed exception thrown by PromoCodeResolverService when a promo
 * code can't be applied. Each instance carries one of the
 * ErrorCodes::PROMO_* error codes plus a structured details payload
 * the consumer endpoint surfaces in the 422 response body.
 *
 * Mapping to HttpException
 * ------------------------
 * The exception is a domain-level concern; the HTTP-mapping is the
 * controller's responsibility. The controller catches this and
 * calls toHttpException() which produces the matching HttpException
 * with status 422 + the PROMO_* error code + details. Keeping the
 * domain layer free of HttpException keeps the resolver service
 * unit-testable without HTTP plumbing.
 *
 * Details payload by error code
 * ------------------------------
 *   PROMO_MIN_SUBTOTAL_NOT_MET: { min_subtotal: "100.00", currency: "AED" }
 *    , client UX can show "Spend X more to unlock"
 *   PROMO_NOT_YET_VALID:        { valid_from: "2026-06-01T00:00:00Z" }
 *    , client UX can show "Comes back live on X"
 *   PROMO_EXPIRED:              { valid_until: "2026-04-30T23:59:59Z" }
 *    , client UX can show "Expired on X"
 *   PROMO_CURRENCY_MISMATCH:    { promo_currency: "USD", cart_currency: "AED" }
 *   All others:                  { }, code alone is enough to inform UX
 *
 * Public message
 * --------------
 * The constructor takes an English public message; client surfaces
 * are expected to localize from the error code. The message is
 * useful for logs and for non-localized clients (e.g. internal
 * dashboards).
 */
final class PromoNotApplicableException extends \RuntimeException
{
    /**
     * @param string $errorCode One of ErrorCodes::PROMO_*
     * @param string $publicMessage English description
     * @param array<string, mixed> $details Structured payload for client UX
     */
    public function __construct(
        public readonly string $errorCode,
        string $publicMessage,
        public readonly array $details = [],
    ) {
        parent::__construct($publicMessage);
    }

    /**
     * Map to the HTTP-layer exception the controller throws. Status
     * is always 422, promo failures are business-rule violations,
     * not validation errors (the code STRING was valid; the rule
     * said the code doesn't apply).
     */
    public function toHttpException(): HttpException
    {
        return new HttpException(
            status: 422,
            errorCode: $this->errorCode,
            publicMessage: $this->getMessage(),
            details: $this->details,
        );
    }

    // -----------------------------------------------------------------
    // Named factories, one per validation-chain failure
    // -----------------------------------------------------------------

    public static function notFound(string $rawCode): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_NOT_FOUND,
            publicMessage: "Promo code '{$rawCode}' was not found.",
        );
    }

    public static function inactive(string $code): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_INACTIVE,
            publicMessage: "Promo code '{$code}' is no longer available.",
        );
    }

    public static function notYetValid(string $code, \DateTimeImmutable $validFrom): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_NOT_YET_VALID,
            publicMessage: "Promo code '{$code}' is not yet valid.",
            details: ['valid_from' => $validFrom->format(\DateTimeInterface::ATOM)],
        );
    }

    public static function expired(string $code, \DateTimeImmutable $validUntil): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_EXPIRED,
            publicMessage: "Promo code '{$code}' has expired.",
            details: ['valid_until' => $validUntil->format(\DateTimeInterface::ATOM)],
        );
    }

    public static function currencyMismatch(string $code, string $promoCurrency, string $cartCurrency): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_CURRENCY_MISMATCH,
            publicMessage: "Promo code '{$code}' is denominated in {$promoCurrency} but the cart is in {$cartCurrency}.",
            details: [
                'promo_currency' => $promoCurrency,
                'cart_currency' => $cartCurrency,
            ],
        );
    }

    public static function minSubtotalNotMet(string $code, string $minSubtotal, string $currency): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_MIN_SUBTOTAL_NOT_MET,
            publicMessage: "Promo code '{$code}' requires a minimum order subtotal of {$minSubtotal} {$currency}.",
            details: [
                'min_subtotal' => $minSubtotal,
                'currency' => $currency,
            ],
        );
    }

    public static function globalLimitReached(string $code): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_GLOBAL_LIMIT_REACHED,
            publicMessage: "Promo code '{$code}' has reached its redemption limit.",
        );
    }

    public static function userLimitReached(string $code): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_USER_LIMIT_REACHED,
            publicMessage: "Promo code '{$code}' has already been used.",
        );
    }

    /**
     * A vendor-scoped coupon was applied to a cart with no items from that
     * vendor. It would discount nothing, so it's rejected up front.
     */
    public static function notApplicableToCart(string $code): self
    {
        return new self(
            errorCode: ErrorCodes::PROMO_NOT_APPLICABLE_TO_CART,
            publicMessage: "Promo code '{$code}' doesn't apply to any items in your cart.",
        );
    }
}
