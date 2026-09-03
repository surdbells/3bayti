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
use Bayti\Api\Http\Responder;
use Bayti\Api\Notification\GiftCardDeliveryService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/gift-cards/{id}/send
 *
 * Manually (re)send a gift card to its recipient over whichever channel(s)
 * have a contact on file — email and/or SMS. Used by the admin gift-card
 * detail page so support can push a card to the recipient on demand (e.g. the
 * original email bounced, or we're reaching out after enabling SMS).
 *
 * Force-send: unlike the automatic delivery hooks (activation / cron), this
 * bypasses the "already delivered" idempotency guard so a card can be re-sent.
 * The response reports a per-channel outcome (sent / failed / not_configured /
 * no_recipient) so the UI can tell the operator exactly what happened.
 *
 * Guards:
 *   - 404 if the card doesn't exist
 *   - 422 if the card isn't spendable (voided / expired / exhausted /
 *     pending_payment) — we never hand out a code that can't be redeemed
 *   - 422 if there's no recipient email or phone to send to
 *
 * Gated by gift_cards.edit (super admins pass via is_admin).
 */
final class SendGiftCardController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly GiftCardDeliveryService $delivery,
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

        // Never send a code that can't be redeemed.
        if (!$card->isSpendable()) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::BUSINESS_RULE_VIOLATION,
                'This gift card cannot be sent (status: ' . $card->getStatus() . ').',
            );
        }

        // Need somewhere to send it. Uses the EFFECTIVE contact (stored
        // delivery target, or the claimed recipient account's email/phone as a
        // fallback) so a card bought with only a phone can still be emailed to
        // the recipient once they've claimed it.
        $hasEmail = $card->effectiveRecipientEmail() !== null && $card->effectiveRecipientEmail() !== '';
        $hasPhone = $card->effectiveRecipientPhone() !== null && $card->effectiveRecipientPhone() !== '';
        if (!$hasEmail && !$hasPhone) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::BUSINESS_RULE_VIOLATION,
                'This gift card has no recipient email or phone to send to.',
            );
        }

        $result = $this->delivery->resend($card);

        $this->audit->recordOverride(
            request: $request,
            actor: $user,
            subject: $card,
            changes: [
                'action' => 'send',
                'result' => $result,
            ],
        );

        return $this->ok([
            'data' => [
                'result' => $result,
                'card'   => GiftCardSerializer::adminDetailShape($card),
            ],
        ]);
    }
}
