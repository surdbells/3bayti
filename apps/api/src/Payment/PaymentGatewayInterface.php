<?php

declare(strict_types=1);

namespace Bayti\Api\Payment;

use Bayti\Api\Domain\Order\Order;

/**
 * Provider-agnostic payment gateway contract.
 *
 * Honors C11 (pluggable gateway architecture): the application
 * layer depends on this interface, never on a concrete provider.
 * Tests inject a fake implementation; production injects Noon
 * (M3.1.6c); future providers (Stripe, Tabby, etc.) drop in by
 * implementing this interface.
 *
 * Why no constructor-side configuration here:
 * Provider-specific config (credentials, base URL, channel) belongs
 * to the concrete implementation. The interface is purely a
 * behavioural contract.
 *
 * Methods are sized to match real Noon API operations even when
 * other providers might not need them. A provider that doesn't
 * support, say, REVERSE can throw PaymentGatewayException::upstream
 * with a 'NOT_SUPPORTED' provider code; callers handle it the same
 * way they handle any other upstream rejection.
 *
 * All amount params are string DECIMAL (e.g. "99.50") — never float.
 * Currency is ISO 4217 (e.g. "AED").
 */
interface PaymentGatewayInterface
{
    /**
     * Start a new checkout for the given order.
     *
     * The implementation MUST:
     *   - Send order.reference = $order->getOrderReference() so the
     *     gateway can deduplicate via merchant reference (Noon-style)
     *   - Pass the path-based returnUrl (no query string per Noon
     *     recon constraint)
     *   - Pass channel='MOBILE' or 'WEB' based on $channel param
     *
     * Returns the checkout URL the caller should redirect the user to.
     *
     * @param string $channel 'MOBILE' (webview) or 'WEB' (browser)
     *
     * @throws PaymentGatewayException
     */
    public function initiateCheckout(
        Order $order,
        string $returnUrl,
        string $channel,
    ): CheckoutInitiation;

    /**
     * Look up order status by the gateway's order ref (returned
     * from initiateCheckout). PRIMARY use: webhook arrives saying
     * "order X is paid" — call this to authoritatively verify
     * before transitioning v3 order state.
     *
     * Caller MUST NOT poll this for status — Noon's docs explicitly
     * warn against repetitive polling (rate-limit ban risk). Use
     * once per state transition, not as a polling loop.
     *
     * @throws PaymentGatewayException
     */
    public function retrieveOrder(string $providerOrderRef): OrderStatusResponse;

    /**
     * Look up order status by the merchant's reference (our
     * orders.order_reference). Used when we don't know the
     * provider order ref — e.g. handling a duplicate-reference
     * rejection where we need to learn the existing order's
     * provider ref.
     *
     * @throws PaymentGatewayException
     */
    public function retrieveOrderByReference(string $merchantReference): OrderStatusResponse;

    /**
     * Issue a refund against an existing paid order.
     *
     * $amount is a string DECIMAL; partial refunds are allowed
     * (must be <= the original paid amount; gateway enforces).
     *
     * Used by M3.1.7's refund flow (vendor / admin tooling).
     * Present on the M3.1.6 interface to keep the contract
     * complete from day 1 — implementations can throw 'NOT_SUPPORTED'
     * if they don't need refund support yet.
     *
     * @throws PaymentGatewayException
     */
    public function refund(
        string $providerOrderRef,
        string $amount,
        string $currency,
        string $reason,
    ): OrderStatusResponse;

    /**
     * Cancel an order that hasn't been captured yet (i.e. the
     * gateway has authorised but not yet taken funds). Different
     * from refund — no money moves.
     *
     * @throws PaymentGatewayException
     */
    public function cancel(string $providerOrderRef, string $reason): OrderStatusResponse;
}
