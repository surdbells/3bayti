<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartPriceRefresher;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Cart\DeliveryFeeCalculator;
use Bayti\Api\Domain\Cashback\CashbackResult;
use Bayti\Api\Domain\Cashback\CashbackService;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\GiftCard\GiftCardWalletService;
use Bayti\Api\Domain\Promo\PromoCodeResolverService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/cart/cashback   (auth)
 *
 * Estimated cashback for the customer's current cart: "You'll earn
 * ≈ AED X cashback". Read-only; the real award is computed when the order
 * is paid and released after delivery + hold.
 *
 * Query (all optional, mirror what checkout will send):
 *   channel=web|mobile          rules can be channel-specific
 *   promo_code=CODE             a promo changes the paid amount and may
 *                               exclude rules (allow_with_promo=false);
 *                               an invalid code is ignored here
 *   gift_card_code=XXXX-…       paying with a cashback card → earns nothing
 *   use_gift_wallet=true|false  same, when the wallet draw includes one
 *
 * → { "data": { "estimated_amount": "12.50", "currency": "AED",
 *       "eligible": true, "reason": null, "hold_days": 14,
 *       "uses_cashback_credit": false, "wallet_has_cashback_credit": true } }
 * reason (when eligible=false): program_disabled | empty_cart |
 *   paid_with_cashback | no_matching_rule | below_minimum |
 *   budget_exhausted | ineligible_order | unavailable
 */
final class GetCartCashbackController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CashbackService $cashback,
        private readonly DeliveryFeeCalculator $delivery,
        private readonly CartPriceRefresher $prices,
        private readonly PromoCodeResolverService $promoResolver,
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
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }
        $q = $request->getQueryParams();

        /** @var CartRepository $carts */
        $carts = $this->em->getRepository(Cart::class);
        $cart = $carts->findActiveForUser($user);
        if ($cart === null || $cart->getItems()->isEmpty()) {
            return $this->respond(CashbackResult::none(CashbackResult::REASON_EMPTY_CART), false, false);
        }

        $channel = isset($q['channel']) && is_string($q['channel']) && $q['channel'] !== '' ? strtoupper(trim($q['channel'])) : null;

        $discount = '0.00';
        $hasPromo = false;
        $promoCode = isset($q['promo_code']) && is_string($q['promo_code']) ? trim($q['promo_code']) : '';
        if ($promoCode !== '') {
            try {
                $resolution = $this->promoResolver->resolveForCart($cart, $user, $promoCode);
                $discount = $resolution->discountAmount;
                $hasPromo = true;
            } catch (\Throwable) {
                // Invalid / inapplicable code: checkout would reject it too.
            }
        }

        // Which gift cards would pay? Only matters for the cashback guard.
        $walletHasCashback = false;
        $usesCashback = false;
        try {
            $wallet = new GiftCardWalletService($this->em);
            $cards = $wallet->spendableCards($user);
            foreach ($cards as $c) {
                $walletHasCashback = $walletHasCashback || $c->isCashback();
            }
            $code = isset($q['gift_card_code']) && is_string($q['gift_card_code']) ? trim($q['gift_card_code']) : '';
            if ($code !== '') {
                /** @var GiftCardRepository $gcRepo */
                $gcRepo = $this->em->getRepository(GiftCard::class);
                $card = $gcRepo->findByCode(strtoupper(str_replace('-', '', $code)));
                $usesCashback = $card !== null && $card->isCashback() && $card->isSpendableBy($user) && $card->isSpendable();
            } elseif (in_array($q['use_gift_wallet'] ?? null, ['1', 'true'], true) && $walletHasCashback) {
                $this->prices->refresh($cart);
                $total = bcsub(bcadd($cart->computeSubtotal(), $this->delivery->forCart($cart), 2), $discount, 2);
                foreach ($wallet->planApply($cards, bccomp($total, '0.00', 2) > 0 ? $total : '0.00') as $row) {
                    $usesCashback = $usesCashback || $row['card']->isCashback();
                }
            }
        } catch (\Throwable) {
            // Best effort: the estimate still renders without the wallet hint.
        }

        $result = $this->cashback->previewForCart($cart, $user, $channel, $discount, $hasPromo, $usesCashback);
        return $this->respond($result, $usesCashback, $walletHasCashback);
    }

    private function respond(CashbackResult $result, bool $usesCashback, bool $walletHasCashback): ResponseInterface
    {
        return $this->ok(['data' => [
            'estimated_amount'           => $result->total,
            'currency'                   => 'AED',
            'eligible'                   => $result->isPositive(),
            'reason'                     => $result->isPositive() ? null : $result->reason,
            'hold_days'                  => $result->isPositive() ? $result->holdDays : null,
            'uses_cashback_credit'       => $usesCashback,
            'wallet_has_cashback_credit' => $walletHasCashback,
        ]]);
    }
}
