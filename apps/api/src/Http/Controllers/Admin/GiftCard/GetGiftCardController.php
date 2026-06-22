<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\GiftCard;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\GiftCard\GiftCardSerializer;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/gift-cards/{id}
 *
 * Single gift card with full details, current balance, the complete
 * ordered transaction LEDGER and a delivery-status block. Gated by
 * gift_cards.view. The card + its ledger + buyer/recipient are fetched
 * in ONE query (findByIdForAdmin) — no lazy-load / N+1.
 */
final class GetGiftCardController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly AuditEmitter $audit,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /** @param array<string,string> $args */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $_r, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::notFound('Gift card not found.');
        }

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $card = $repo->findByIdForAdmin($id);
        if ($card === null) {
            throw HttpException::notFound('Gift card not found.');
        }

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $card,
            context: ['context' => 'admin_gift_card_detail'],
        );

        return $this->ok(PaginatedEnvelope::single(GiftCardSerializer::adminDetailShape($card)));
    }
}
