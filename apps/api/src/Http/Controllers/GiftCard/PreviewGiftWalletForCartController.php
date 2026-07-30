<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\GiftCard\GiftCardWalletService;
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
 * GET /v3/cart/gift-wallet
 *
 * Preview applying the customer's whole gift-card WALLET to their current
 * cart — the one-tap alternative to typing a single card code. Pure read,
 * no debit (that happens at POST /v3/checkout/initiate with
 * use_gift_wallet=true).
 *
 * The cart total here is the item subtotal (delivery/discount are computed
 * at checkout) — the exact split is reconciled at initiate, mirroring the
 * single-card preview (POST /v3/cart/gift-card).
 *
 * Response:
 * {
 *   "data": {
 *     "wallet_balance": "300.00",
 *     "applied":        "200.00",   ← drawn from the wallet for this cart
 *     "gateway_amount": "0.00",     ← remainder to pay via Noon
 *     "cart_total":     "200.00",
 *     "fully_covered":  true,
 *     "currency":       "AED",
 *     "cards_used": [ { "code": "XXXX-…", "amount": "150.00" } ]
 *   }
 * }
 */
final class PreviewGiftWalletForCartController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var CartRepository $cartRepo */
        $cartRepo = $this->em->getRepository(Cart::class);
        $cart     = $cartRepo->findActiveForUser($user);
        if ($cart === null || $cart->getItems()->isEmpty()) {
            throw HttpException::badRequest('Your cart is empty.');
        }

        $cartTotal = '0.00';
        /** @var CartItem $item */
        foreach ($cart->getItems() as $item) {
            $line      = bcmul($item->getUnitPriceSnapshot(), (string) $item->getQuantity(), 2);
            $cartTotal = bcadd($cartTotal, $line, 2);
        }

        $wallet  = new GiftCardWalletService($this->em);
        $cards   = $wallet->spendableCards($user);
        $balance = GiftCardWalletService::sumBalances($cards);
        $plan    = $wallet->planApply($cards, $cartTotal);
        $applied = GiftCardWalletService::planTotal($plan);
        $gateway = bcsub($cartTotal, $applied, 2);
        $gateway = bccomp($gateway, '0.00', 2) < 0 ? '0.00' : $gateway;

        return $this->ok(['data' => [
            'wallet_balance' => $balance,
            'applied'        => $applied,
            'gateway_amount' => $gateway,
            'cart_total'     => $cartTotal,
            'fully_covered'  => bccomp($gateway, '0.00', 2) === 0 && bccomp($applied, '0.00', 2) > 0,
            'currency'       => 'AED',
            'cards_used'     => array_map(
                static fn (array $row): array => [
                    'code'   => $row['card']->formattedCode(),
                    'theme'  => $row['card']->getTheme(),
                    'amount' => $row['amount'],
                ],
                $plan,
            ),
        ]]);
    }
}
