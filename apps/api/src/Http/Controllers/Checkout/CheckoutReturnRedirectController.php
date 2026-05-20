<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Checkout;

use Bayti\Api\Http\Errors\HttpException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/checkout/return/{order_reference}
 *
 * Browser landing point after Noon's hosted checkout finishes. Noon
 * was told this exact URL at initiate time (see
 * InitiateCheckoutController::buildReturnUrl) and redirects the
 * customer's browser here when they finish, cancel, or fail payment.
 *
 * This controller does ONE thing: 302-redirect the WEB browser over
 * to the Angular storefront's polling page:
 *
 *   {WEB_APP_URL}/checkout/return?ref={order_reference}
 *
 * The web page then polls GET /v3/checkout/status/{ref} (authenticated)
 * to learn the authoritative payment outcome.
 *
 * Why a redirect and not a JSON/HTML response
 * -------------------------------------------
 * The return URL lives on the API host because:
 *   1. Noon needs ONE stable return URL set at initiate time.
 *   2. The mobile webview detects this exact API path to know the
 *      hosted checkout finished — it must NOT change to a web URL,
 *      or mobile breaks.
 * But web shoppers need to land in the Angular app (different host)
 * where the polling UI lives. A 302 bridges the two cleanly: mobile
 * keeps seeing the API path (its webview intercepts before the
 * redirect resolves), web browsers follow the 302 to the storefront.
 *
 * Why UNAUTHENTICATED
 * -------------------
 * Noon redirects the raw browser. Depending on cookie/session timing
 * and the hosted-checkout round trip, we cannot rely on a valid auth
 * token being present on THIS request. So this endpoint is mounted
 * outside the AuthMiddleware group. It leaks nothing: it only echoes
 * back the reference the caller already supplied into a redirect.
 * The authoritative, ownership-enforcing check happens when the web
 * app polls GET /v3/checkout/status/{ref} (which IS authenticated and
 * returns 404 for cross-user or unknown references).
 *
 * Why we redirect even for malformed/unknown references
 * -----------------------------------------------------
 * To avoid leaking which references exist, we do NOT look up the
 * order here. Any syntactically-plausible reference is reflected into
 * the redirect; the web app's authenticated poll then surfaces a
 * generic "couldn't confirm" state for bad refs. We DO reject
 * obviously-bogus input (empty or over-length) with a 404 to avoid
 * building absurd redirect URLs and as a cheap abuse guard.
 */
final class CheckoutReturnRedirectController
{
    /** Mirror of GetCheckoutStatusController's reference length cap. */
    private const MAX_REFERENCE_LENGTH = 32;

    private const WEB_RETURN_PATH = '/checkout/return';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $reference = (string) ($args['order_reference'] ?? '');
        if ($reference === '' || strlen($reference) > self::MAX_REFERENCE_LENGTH) {
            throw HttpException::notFound('Order not found.');
        }

        $location = $this->buildWebReturnUrl($reference);

        return $this->responseFactory
            ->createResponse(302)
            ->withHeader('Location', $location)
            // Payment-return URLs must never be cached by intermediaries;
            // a cached 302 could send a later, unrelated shopper to a
            // stale reference.
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withHeader('Pragma', 'no-cache');
    }

    private function buildWebReturnUrl(string $orderReference): string
    {
        $base = rtrim((string) ($_ENV['WEB_APP_URL'] ?? 'https://3bayti.ae'), '/');

        return $base
            . self::WEB_RETURN_PATH
            . '?ref=' . rawurlencode($orderReference);
    }
}
