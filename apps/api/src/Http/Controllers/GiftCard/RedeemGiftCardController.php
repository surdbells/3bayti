<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;

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
 * POST /v3/gift-cards/redeem
 *
 * A recipient claims a gift card by entering its code. This links the
 * card to the recipient's account so it appears in their wallet.
 * Body: { "code": "XXXX-XXXX-XXXX-XXXX" }
 *
 * A buyer can also redeem their own card (if they want to use it
 * themselves). Cards are redeemable as long as they are active or
 * partially_used — idempotent for the same user.
 */
final class RedeemGiftCardController
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

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $card = $repo->findByCode($code);

        if ($card === null) throw HttpException::notFound('Gift card not found.');

        if (!in_array($card->getStatus(), [GiftCard::STATUS_ACTIVE, GiftCard::STATUS_PARTIALLY_USED], true)) {
            throw HttpException::badRequest('This gift card is not redeemable (status: ' . $card->getStatus() . ').');
        }

        try {
            $card->assignRecipient($user);
        } catch (\LogicException $e) {
            throw HttpException::badRequest($e->getMessage());
        }

        $repo->save($card);

        return $this->ok(['data' => GiftCardSerializer::shape($card, includeTransactions: true)]);
    }
}
