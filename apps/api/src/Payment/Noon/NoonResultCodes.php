<?php

declare(strict_types=1);

namespace Bayti\Api\Payment\Noon;

/**
 * Noon Payments API result codes captured during M3.1.6 recon
 * (docs.noonpayments.com, specifically the resultCode field
 * returned in API responses).
 *
 * Only codes the v3 layer needs to recognise live here. Unknown
 * codes are surfaced as PaymentGatewayException::upstream so we
 * don't silently swallow them.
 *
 * Noon's full code dictionary is gated behind their merchant
 * portal; this list grows as we encounter new codes in
 * production. Each addition should cite the docs page or
 * sandbox transaction that surfaced it.
 */
final class NoonResultCodes
{
    /**
     * Operation succeeded. Returned for all non-error responses.
     */
    public const SUCCESS = 0;

    /**
     * Duplicate merchant order reference. Source:
     * docs.noonpayments.com (multiple payment-method pages -
     * "In case an order with the same reference is already
     * present, the system will return resultCode: 19012 and in
     * the result block there will be the details of the existing
     * order").
     *
     * Native idempotency requires Noon support to enable
     * "uniqueness of the merchant order reference field" per
     * merchant. Even when not enabled by Noon, we enforce
     * uniqueness via our orders.order_reference UNIQUE constraint.
     */
    public const DUPLICATE_REFERENCE = 19012;

    /**
     * Business not configured for the requested feature. Source:
     * docs.noonpayments.com Samsung Pay direct page, "the system
     * will return resultCode: 19100 if the business is not
     * enabled to submit card details. Please contact our support
     * team for further information".
     */
    public const NOT_ENABLED_FOR_FEATURE = 19100;

    /**
     * True if the given code represents a duplicate-reference
     * rejection, which v3 handles specially: instead of treating
     * it as a hard error, we look up the existing order via
     * retrieveOrderByReference() and surface its current status
     * to the caller.
     */
    public static function isDuplicateReference(int $code): bool
    {
        return $code === self::DUPLICATE_REFERENCE;
    }

    /**
     * True if the given code is a success indicator.
     */
    public static function isSuccess(int $code): bool
    {
        return $code === self::SUCCESS;
    }
}
