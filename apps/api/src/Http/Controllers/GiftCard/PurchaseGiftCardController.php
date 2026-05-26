<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\GiftCard;

use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/gift-cards/purchase
 *
 * Creates a gift card in pending_payment status. The card is activated
 * when the buyer pays via the normal checkout flow (POST /v3/checkout/initiate
 * with { "gift_card_purchase_id": <id> }). Until then the card cannot be spent.
 *
 * Body:
 * {
 *   "denomination":          "500.00",
 *   "theme":                 "birthday",
 *   "recipient_name":        "Sara",
 *   "recipient_message":     "Happy Birthday! 🎉",
 *   "recipient_photo_url":   null,         ← luxury theme only
 *   "scheduled_delivery_at": null          ← ISO-8601 or null = now
 * }
 */
final class PurchaseGiftCardController
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

        // Denomination
        $denomination = trim((string) ($body['denomination'] ?? ''));
        if ($denomination === '') throw HttpException::badRequest('denomination is required.');
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $denomination)) {
            throw HttpException::badRequest('denomination must be a decimal number (e.g. "500.00").');
        }
        if (bccomp($denomination, GiftCard::MIN_DENOMINATION, 2) < 0) {
            throw HttpException::badRequest('Minimum denomination is ' . GiftCard::MIN_DENOMINATION . ' AED.');
        }
        if (bccomp($denomination, GiftCard::MAX_DENOMINATION, 2) > 0) {
            throw HttpException::badRequest('Maximum denomination is ' . GiftCard::MAX_DENOMINATION . ' AED.');
        }

        // Theme
        $theme = trim((string) ($body['theme'] ?? ''));
        if ($theme === '' || !in_array($theme, GiftCard::THEMES, true)) {
            throw HttpException::badRequest('theme must be one of: ' . implode(', ', GiftCard::THEMES) . '.');
        }

        // Personalisation
        $recipientName    = isset($body['recipient_name'])    ? trim((string) $body['recipient_name'])    : null;
        $recipientMessage = isset($body['recipient_message']) ? trim((string) $body['recipient_message']) : null;
        $recipientPhotoUrl= isset($body['recipient_photo_url']) && $body['recipient_photo_url'] !== ''
            ? (string) $body['recipient_photo_url'] : null;

        if ($recipientPhotoUrl !== null && $theme !== GiftCard::THEME_LUXURY) {
            throw HttpException::badRequest("recipient_photo_url is only supported for the 'luxury' theme.");
        }

        // Scheduled delivery
        $scheduledDeliveryAt = null;
        if (!empty($body['scheduled_delivery_at'])) {
            try {
                $scheduledDeliveryAt = new DateTimeImmutable((string) $body['scheduled_delivery_at']);
            } catch (\Throwable) {
                throw HttpException::badRequest('scheduled_delivery_at must be a valid ISO-8601 date string.');
            }
            if ($scheduledDeliveryAt <= new DateTimeImmutable()) {
                throw HttpException::badRequest('scheduled_delivery_at must be in the future.');
            }
        }

        try {
            $card = new GiftCard(
                buyerUser: $user,
                denomination: number_format((float) $denomination, 2, '.', ''),
                theme: $theme,
                recipientName: $recipientName ?: null,
                recipientMessage: $recipientMessage ?: null,
                recipientPhotoUrl: $recipientPhotoUrl,
                scheduledDeliveryAt: $scheduledDeliveryAt,
            );
        } catch (\InvalidArgumentException $e) {
            throw HttpException::badRequest($e->getMessage());
        }

        /** @var GiftCardRepository $repo */
        $repo = $this->em->getRepository(GiftCard::class);
        $repo->save($card);

        return $this->created(['data' => GiftCardSerializer::shape($card)]);
    }
}
