<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/gift-cards/:id/activate
 *
 * Called internally by the payment-finalize webhook after the buyer's
 * payment gateway transaction confirms. Body: { "order_reference": "..." }
 *
 * This endpoint is admin-auth only — not exposed to customers.
 */
final class ActivateGiftCardController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id');
        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $card = $repo->find($id);
        if ($card === null) throw HttpException::notFound('Gift card not found.');

        $body     = (array) ($request->getParsedBody() ?? []);
        $orderRef = trim((string) ($body['order_reference'] ?? ''));
        if ($orderRef === '') throw HttpException::badRequest('order_reference is required.');

        try {
            $card->activate($orderRef);
        } catch (\LogicException $e) {
            throw HttpException::badRequest($e->getMessage());
        }

        $repo->save($card);

        return $this->ok(['data' => GiftCardSerializer::shape($card)]);
    }
}
