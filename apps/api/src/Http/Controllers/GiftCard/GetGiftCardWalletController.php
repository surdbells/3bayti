<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;

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
 * GET /v3/gift-cards/wallet
 *
 * The authenticated customer's gift-card WALLET: the aggregate spendable
 * balance across every card they own or have redeemed, plus the cards
 * themselves (soonest-expiry first, the order they'll be spent in).
 *
 * Response:
 * {
 *   "data": {
 *     "balance":    "300.00",
 *     "currency":   "AED",
 *     "card_count": 2,
 *     "cards": [ { …GiftCardSerializer::shape… } ]
 *   }
 * }
 */
final class GetGiftCardWalletController
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

        $wallet = new GiftCardWalletService($this->em);
        $cards  = $wallet->spendableCards($user);

        return $this->ok(['data' => [
            'balance'    => GiftCardWalletService::sumBalances($cards),
            'currency'   => 'AED',
            'card_count' => count($cards),
            'cards'      => array_map(
                static fn ($c) => GiftCardSerializer::shape($c),
                $cards,
            ),
        ]]);
    }
}
