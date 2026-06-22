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
 * POST /v3/admin/gift-cards
 *
 * Manually issue / comp a gift card. The card is born ACTIVE (no payment
 * collected) and funded by an opening CREDIT ledger row. The unique code
 * is generated the same way as the storefront purchase path (in the
 * GiftCard constructor via random_bytes). Gated by gift_cards.create.
 *
 * Body:
 *   {
 *     "value":           "500.00",   (required; within denom bounds)
 *     "currency":        "AED",      (optional; AED only today)
 *     "recipient_email": "...",      (optional)
 *     "recipient_name":  "...",      (optional)
 *     "note":            "..."       (optional; recorded on the ledger)
 *   }
 *
 * Returns 201 with the full admin detail shape (incl. the opening ledger
 * row).
 */
final class IssueGiftCardController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $value = trim((string) ($body['value'] ?? ''));
        if ($value === '') {
            throw HttpException::validation(['value' => ['value is required.']]);
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
            throw HttpException::validation(['value' => ['value must be a decimal number (e.g. 500.00).']]);
        }
        $value = bcadd($value, '0.00', 2);
        if (bccomp($value, GiftCard::MIN_DENOMINATION, 2) < 0) {
            throw HttpException::validation(['value' => ['Minimum value is ' . GiftCard::MIN_DENOMINATION . ' AED.']]);
        }
        if (bccomp($value, GiftCard::MAX_DENOMINATION, 2) > 0) {
            throw HttpException::validation(['value' => ['Maximum value is ' . GiftCard::MAX_DENOMINATION . ' AED.']]);
        }

        $currency = isset($body['currency']) && trim((string) $body['currency']) !== ''
            ? strtoupper(trim((string) $body['currency'])) : 'AED';
        if ($currency !== 'AED') {
            throw HttpException::validation(['currency' => ['Only AED is supported.']]);
        }

        $recipientEmail = isset($body['recipient_email']) && trim((string) $body['recipient_email']) !== ''
            ? trim((string) $body['recipient_email']) : null;
        if ($recipientEmail !== null && !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw HttpException::validation(['recipient_email' => ['recipient_email must be a valid email address.']]);
        }

        $recipientName = isset($body['recipient_name']) && trim((string) $body['recipient_name']) !== ''
            ? trim((string) $body['recipient_name']) : null;
        $note = isset($body['note']) && trim((string) $body['note']) !== ''
            ? trim((string) $body['note']) : null;

        try {
            $card = GiftCard::issueByAdmin(
                buyerUser: $user,
                value: $value,
                recipientName: $recipientName,
                recipientEmail: $recipientEmail,
                note: $note,
                actorUserId: $user->getId(),
            );
        } catch (\InvalidArgumentException $e) {
            throw HttpException::badRequest($e->getMessage());
        }

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $repo->save($card);

        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $card,
            afterSnapshot: [
                'code'            => $card->formattedCode(),
                'value'          => $value,
                'currency'        => $currency,
                'recipient_email' => $recipientEmail,
                'recipient_name'  => $recipientName,
                'note'            => $note,
            ],
        );

        return $this->created(PaginatedEnvelope::single(GiftCardSerializer::adminDetailShape($card)));
    }
}
