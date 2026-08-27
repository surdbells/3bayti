<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Promo\Exception\PromoNotApplicableException;
use Bayti\Api\Domain\Promo\PromoCodeResolverService;
use Bayti\Api\Domain\Promo\PromoResolution;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Cart\Dto\QuoteCartInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CartQuoteSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/cart/quote
 *
 * Returns a server-authoritative price breakdown for the authenticated
 * user's active cart, optionally with a promo code applied.
 *
 * Request body (all fields optional):
 *   { "promo_code": "WELCOME10" }  , promo applied
 *   { }                             , no promo, just the cart preview
 *
 * Response (200):
 *   {
 *     "data": {
 *       "currency": "AED",
 *       "subtotal": "120.00",
 *       "delivery_fee": "0.00",
 *       "discount": "12.00",
 *       "total": "108.00",
 *       "applied_promo": {
 *         "code": "WELCOME10",
 *         "discount_type": "percentage",
 *         "discount_value": "10.00",
 *         "discount_amount": "12.00"
 *       }
 *     }
 *   }
 *
 * Failure modes
 * -------------
 *   401  No / invalid bearer token
 *   422  Cart is empty (BUSINESS_RULE_VIOLATION). Quoting an empty
 *        cart isn't meaningful and matches the existing initiate
 *        endpoint's behavior, fail fast at the same shape.
 *   422  Promo failure (PROMO_NOT_FOUND, PROMO_EXPIRED, ...). The
 *        cart contents are valid; the supplied code didn't apply.
 *        Error code + structured details payload tell the client UX
 *        exactly which rule fired so it can render the right message.
 *
 * Why this is idempotent (per plan §2.3)
 * ---------------------------------------
 * No DB writes. The resolver counts redemptions read-only; the
 * resolution itself only happens on a successful match. Calling
 * /v3/cart/quote a hundred times in a row produces the same response
 * each time (modulo a redemption count crossing a limit boundary,
 * but that's not a write the quote endpoint performs).
 *
 * Server-authoritative
 * ---------------------
 * The discount and total in the response are computed entirely
 * server-side. The client must NOT cache these and pass them back to
 * /v3/checkout/initiate; the initiate endpoint re-resolves the promo
 * inside its transaction (M3.2.X.8-D). This avoids the security gap
 * the legacy "client passes discount amount" path has.
 *
 * Empty body handling
 * --------------------
 * Per RequestValidator::decodeBody, an empty body parses to []; the
 * DTO constructor then defaults promo_code to null. So:
 *
 *   POST /v3/cart/quote (no body)         → same as { } → no promo
 *   POST /v3/cart/quote { }                → no promo
 *   POST /v3/cart/quote { "promo_code": "" } → promo_code normalizes
 *                                              to null in the DTO →
 *                                              no promo (the
 *                                              resolver isn't called)
 *   POST /v3/cart/quote { "promo_code": "X" } → resolver runs
 */
final class QuoteCartController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly PromoCodeResolverService $promoResolver,
        private readonly CartQuoteSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $input = $this->validator->parse($request, QuoteCartInput::class);

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        $cart = $carts->findActiveForUser($user);

        if ($cart === null || $cart->itemCount() === 0) {
            // Quoting an empty cart is a business-rule violation
            // (mirrors the initiate endpoint's empty-cart 422).
            throw HttpException::businessRuleViolation(
                ErrorCodes::BUSINESS_RULE_VIOLATION,
                'Cart is empty — add items before requesting a quote.',
            );
        }

        $resolution = $this->resolvePromoOrThrow($cart, $user, $input);

        return $this->ok([
            'data' => $this->serializer->shape($cart, $resolution),
        ]);
    }

    /**
     * Resolve the supplied promo code (if any) or return null when
     * the customer didn't pass one. Promo failures map to 422 via
     * PromoNotApplicableException::toHttpException, same shape as
     * the rest of the API's structured 422 envelope.
     */
    private function resolvePromoOrThrow(
        Cart $cart,
        User $user,
        QuoteCartInput $input,
    ): ?PromoResolution {
        if ($input->promo_code === null) {
            return null;
        }
        try {
            return $this->promoResolver->resolveForCart($cart, $user, $input->promo_code);
        } catch (PromoNotApplicableException $e) {
            throw $e->toHttpException();
        }
    }
}
