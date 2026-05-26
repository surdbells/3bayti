<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;



use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
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
 * POST /v3/cart/gift-card
 *
 * Preview the impact of applying a gift card to the current cart.
 * Pure read — no DB writes, no balance deducted (debit happens at
 * POST /v3/checkout/initiate with gift_card_code in the body).
 *
 * Body: { "code": "XXXX-XXXX-XXXX-XXXX" }
 *
 * Response:
 * {
 *   "data": {
 *     "code": "XXXX-XXXX-XXXX-XXXX",
 *     "gift_card_amount":  "150.00",   ← applied from card
 *     "gateway_amount":    "50.00",    ← remainder to pay via gateway
 *     "balance_remaining": "0.00",     ← card balance after this order
 *     "cart_total":        "200.00"
 *   }
 * }
 */
final class ApplyGiftCardToCartController
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
        if (!$user instanceof User) throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');

        $body = (array) ($request->getParsedBody() ?? []);
        $code = trim((string) ($body['code'] ?? ''));
        if ($code === '') throw HttpException::badRequest('code is required.');

        // Load gift card
        /** @var GiftCardRepository $gcRepo */
        $gcRepo = $this->em->getRepository(GiftCard::class);
        $card   = $gcRepo->findByCode($code);
        if ($card === null) throw HttpException::notFound('Gift card not found.');
        if (!$card->isSpendable()) {
            throw HttpException::badRequest('This gift card is not spendable (status: ' . $card->getStatus() . ').');
        }

        // Must belong to user (buyer or assigned recipient)
        $buyerId    = $card->getBuyerUser()->getId();
        $recipientId = $card->getRecipientUser()?->getId();
        $userId     = $user->getId();
        if ($buyerId !== $userId && $recipientId !== $userId) {
            throw HttpException::forbidden('This gift card is not associated with your account.');
        }

        // Load active cart total
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

        $applied          = $card->applyableAmount($cartTotal);
        $gatewayAmount    = bcsub($cartTotal, $applied, 2);
        $balanceRemaining = bcsub($card->getBalance(), $applied, 2);

        return $this->ok(['data' => [
            'code'              => $card->formattedCode(),
            'theme'             => $card->getTheme(),
            'gift_card_amount'  => $applied,
            'gateway_amount'    => $gatewayAmount,
            'balance_remaining' => $balanceRemaining,
            'cart_total'        => $cartTotal,
            'currency'          => $card->getCurrency(),
        ]]);
    }
}
