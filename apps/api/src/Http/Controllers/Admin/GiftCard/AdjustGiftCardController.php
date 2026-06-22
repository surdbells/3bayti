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
 * POST /v3/admin/gift-cards/{id}/adjust
 *
 * Manually credit or debit a gift card balance, writing a
 * GiftCardTransaction ledger row and updating the balance atomically.
 * Gated by gift_cards.adjust_balance.
 *
 * Body — two accepted forms (pick one):
 *   { "amount": "50.00",  "reason": "..." }   ← signed: + credit, - debit
 *   { "amount": "-25.00", "reason": "..." }
 *   { "type": "credit"|"debit", "amount": "50.00", "reason": "..." }
 *
 * Rules:
 *   - amount magnitude must be >= 0.01 (money math via bcmath)
 *   - a debit may NOT exceed the current balance (overdraw rejected, 422)
 *   - balance never goes below 0
 *   - a voided card cannot be adjusted (409)
 *
 * Returns the new balance + the newly-written ledger row.
 */
final class AdjustGiftCardController
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

        [$type, $magnitude] = $this->resolveTypeAndAmount($body);

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $card = $repo->findByIdForAdmin($id);
        if ($card === null) {
            throw HttpException::notFound('Gift card not found.');
        }

        if ($card->getStatus() === GiftCard::STATUS_VOIDED) {
            throw HttpException::conflict('gift_card_voided', 'Cannot adjust a voided gift card.');
        }

        $balanceBefore = $card->getBalance();
        $actorId = $user->getId();

        // Mutate + persist atomically: the entity method writes the
        // ledger row + updates the balance in one consistent step, and
        // wrapInTransaction commits both together (or rolls both back).
        $tx = $this->em->wrapInTransaction(function () use ($card, $type, $magnitude, $reason, $actorId): GiftCardTransaction {
            try {
                $tx = $type === GiftCardTransaction::TYPE_CREDIT
                    ? $card->adminCredit($magnitude, $reason, $actorId)
                    : $card->adminDebit($magnitude, $reason, $actorId);
            } catch (\DomainException $e) {
                // Overdraw (debit > balance).
                throw new HttpException(
                    status: 422,
                    errorCode: 'gift_card_overdraw',
                    publicMessage: $e->getMessage(),
                    details: ['balance' => $card->getBalance()],
                );
            } catch (\InvalidArgumentException $e) {
                throw HttpException::badRequest($e->getMessage());
            }

            $this->em->persist($tx);
            $this->em->flush();
            return $tx;
        });

        $this->audit->recordOverride(
            request: $request,
            actor: $user,
            subject: $card,
            changes: [
                'before' => ['balance' => $balanceBefore],
                'after'  => ['balance' => $card->getBalance()],
                'type'   => $type,
                'amount' => $magnitude,
                'reason' => $reason,
            ],
        );

        return $this->ok([
            'data' => [
                'id'          => $card->getId(),
                'balance'     => $card->getBalance(),
                'status'      => $card->getStatus(),
                'transaction' => GiftCardSerializer::ledgerRow($tx),
            ],
        ]);
    }

    /**
     * Resolve the request into a (type, positive-magnitude) pair.
     * Accepts the signed-amount form OR the explicit type+amount form.
     *
     * @param array<string,mixed> $body
     * @return array{0: string, 1: string}
     */
    private function resolveTypeAndAmount(array $body): array
    {
        $rawAmount = trim((string) ($body['amount'] ?? ''));
        if ($rawAmount === '') {
            throw HttpException::validation(['amount' => ['amount is required.']]);
        }

        $explicitType = isset($body['type']) ? trim((string) $body['type']) : null;

        if ($explicitType !== null && $explicitType !== '') {
            if (!in_array($explicitType, [GiftCardTransaction::TYPE_CREDIT, GiftCardTransaction::TYPE_DEBIT], true)) {
                throw HttpException::validation(['type' => ["type must be 'credit' or 'debit'."]]);
            }
            // With an explicit type the amount must be a positive magnitude.
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $rawAmount)) {
                throw HttpException::validation(['amount' => ['amount must be a positive decimal (e.g. 50.00).']]);
            }
            return [$explicitType, bcadd($rawAmount, '0.00', 2)];
        }

        // Signed form: leading +/- selects credit/debit.
        if (!preg_match('/^([+-]?)(\d+(\.\d{1,2})?)$/', $rawAmount, $m)) {
            throw HttpException::validation(['amount' => ['amount must be a signed decimal (e.g. 50.00 or -25.00).']]);
        }
        $sign = $m[1];
        $magnitude = bcadd($m[2], '0.00', 2);

        if (bccomp($magnitude, '0.00', 2) === 0) {
            throw HttpException::validation(['amount' => ['amount must not be zero.']]);
        }

        $type = $sign === '-' ? GiftCardTransaction::TYPE_DEBIT : GiftCardTransaction::TYPE_CREDIT;
        return [$type, $magnitude];
    }
}
