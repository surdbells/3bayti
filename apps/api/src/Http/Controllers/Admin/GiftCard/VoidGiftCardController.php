<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\GiftCard;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\GiftCard\GiftCardTransaction;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\GiftCard\GiftCardSerializer;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/gift-cards/{id}/void
 *
 * Void a gift card: status -> voided, spendable balance zeroed, plus a
 * terminal VOID ledger row carrying the reason + actor. Gated by
 * gift_cards.delete (the catalog's "Void gift cards" permission).
 *
 * Body: { "reason": "..." } (required)
 *
 * Idempotent-safe: voiding an already-voided card is a no-op that
 * returns 200 with already_voided=true (no second ledger row, no audit
 * override) rather than an error, re-issuing the same request is safe.
 */
final class VoidGiftCardController
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

        $body = (array) ($request->getParsedBody() ?? []);
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw HttpException::validation(['reason' => ['reason is required.']]);
        }
        if (mb_strlen($reason) > 255) {
            throw HttpException::validation(['reason' => ['reason must be at most 255 characters.']]);
        }

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $card = $repo->findByIdForAdmin($id);
        if ($card === null) {
            throw HttpException::notFound('Gift card not found.');
        }

        // Idempotent no-op when already voided.
        if ($card->getStatus() === GiftCard::STATUS_VOIDED) {
            return $this->ok([
                'data' => [
                    'id'             => $card->getId(),
                    'status'         => $card->getStatus(),
                    'balance'        => $card->getBalance(),
                    'already_voided' => true,
                    'transaction'    => null,
                ],
            ]);
        }

        $balanceBefore = $card->getBalance();
        $actorId = $user->getId();

        $tx = $this->em->wrapInTransaction(function () use ($card, $reason, $actorId): ?GiftCardTransaction {
            $tx = $card->voidWithLedger($reason, $actorId);
            if ($tx !== null) {
                $this->em->persist($tx);
            }
            $this->em->flush();
            return $tx;
        });

        $this->audit->recordOverride(
            request: $request,
            actor: $user,
            subject: $card,
            changes: [
                'before' => ['status' => 'active_or_used', 'balance' => $balanceBefore],
                'after'  => ['status' => $card->getStatus(), 'balance' => $card->getBalance()],
                'reason' => $reason,
            ],
        );

        return $this->ok([
            'data' => [
                'id'             => $card->getId(),
                'status'         => $card->getStatus(),
                'balance'        => $card->getBalance(),
                'already_voided' => false,
                'transaction'    => $tx !== null ? GiftCardSerializer::ledgerRow($tx) : null,
            ],
        ]);
    }
}
