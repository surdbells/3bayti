<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ai;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\Concierge\Gift\GiftBrief;
use Bayti\Api\Ai\Concierge\Gift\GiftConciergeService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/ai/concierge/gift — "Ain, help me find a gift".
 *
 * OptionalAuthMiddleware: works logged-out, personalises (locale) when signed
 * in. A guided brief (recipient / occasion / budget / optional colour-style-size)
 * is turned into real, in-stock, ranked gift ideas via the same retrieval
 * pipeline as the style concierge, plus a gift-card fallback nudge when the gift
 * is risky (unknown size, or too few strong matches).
 *
 * Body: { recipient?, occasion?, colours?[], styles?[], size?, budget_min?,
 * budget_max?, product_type?, category_slug?, locale?, session_id?, channel? }.
 */
final class GiftConciergeController
{
    use Responder;

    private const FEATURE = 'gift_concierge';

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly GiftConciergeService $concierge,
        private readonly ProductSerializer $productSerializer,
        private readonly AiEventLogger $events,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $brief = GiftBrief::fromArray($body);
        if (!$brief->hasEnoughSignal()) {
            throw HttpException::validation([
                'brief' => ['Tell Ain a little about the gift — an occasion, budget, colour or style.'],
            ]);
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $userId = $user instanceof User ? $user->getId() : null;
        $locale = $this->resolveLocale($body['locale'] ?? null, $user);
        $sessionId = isset($body['session_id']) && is_scalar($body['session_id'])
            ? mb_substr((string) $body['session_id'], 0, 64) : null;
        $channel = $this->resolveChannel($body['channel'] ?? null);

        // Server-side funnel start. The CLIENT emits the distinct 'ai_gift_started'
        // on submit, so we do NOT emit it here (avoids double-counting).
        $this->events->recordEvent('ai_query_started', null, $userId, $sessionId, null, null, ['feature' => self::FEATURE]);

        $result = $this->concierge->askGift($brief);
        $intent = $result->concierge->intent;
        $productIds = $result->productIds();
        $query = $brief->toRankerQuery();

        $interactionId = $this->events->recordInteraction(
            self::FEATURE,
            $query,
            $intent->toArray(),
            $productIds,
            $userId,
            $sessionId,
            $channel,
            $locale,
        );
        $this->events->recordEvent(
            'ai_query_completed',
            $interactionId,
            $userId,
            $sessionId,
            null,
            null,
            ['count' => count($result->concierge->items)],
        );

        $serializer = $this->productSerializer->configureFromRequest($request);
        $products = [];
        foreach ($result->concierge->items as $item) {
            $card = $serializer->listShape($item->product);
            $card['reason'] = $item->reason;
            $products[] = $card;
        }

        $this->events->recordEvent(
            'ai_product_recommendation_displayed',
            $interactionId,
            $userId,
            $sessionId,
            null,
            null,
            ['product_ids' => $productIds],
        );

        $payload = [
            'interaction_id' => $interactionId,
            'intent' => $intent->toArray(),
            'products' => $products,
        ];

        if ($result->suggestion !== null) {
            $payload['gift_card_suggestion'] = $result->suggestion->toArray();
            $this->events->recordEvent(
                'ai_gift_card_recommended',
                $interactionId,
                $userId,
                $sessionId,
                null,
                null,
                ['reason' => $result->suggestion->reason, 'denomination' => $result->suggestion->suggestedDenomination],
            );
        }

        return $this->ok($payload);
    }

    private function resolveLocale(mixed $bodyLocale, mixed $user): string
    {
        $candidate = is_string($bodyLocale) ? strtolower(substr(trim($bodyLocale), 0, 2)) : '';
        if ($candidate === 'en' || $candidate === 'ar') {
            return $candidate;
        }
        if ($user instanceof User) {
            $loc = strtolower(substr($user->getLocale(), 0, 2));
            if ($loc === 'ar') {
                return 'ar';
            }
        }
        return 'en';
    }

    private function resolveChannel(mixed $channel): ?string
    {
        if (!is_string($channel)) {
            return null;
        }
        $c = strtoupper(trim($channel));
        return in_array($c, ['MOBILE', 'WEB'], true) ? $c : null;
    }
}
