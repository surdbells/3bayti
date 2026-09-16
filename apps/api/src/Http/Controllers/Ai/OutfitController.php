<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ai;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\Concierge\Outfit\OutfitBrief;
use Bayti\Api\Ai\Concierge\Outfit\OutfitComposerService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/ai/outfit — "Ain, put together a look".
 *
 * OptionalAuthMiddleware: works logged-out, personalises (locale) when signed in.
 * A guided brief (occasion / style / colour / budget / optional hero garment) is
 * turned into a coordinated multi-piece outfit — a hero garment plus
 * complementary pieces across distinct categories — built ONLY from real,
 * in-stock catalogue products via the same retrieval pipeline as the style/gift
 * concierge. A gift-card fallback is suggested when a coherent look can't be
 * assembled. The client persists a chosen outfit via POST /v3/me/styles.
 *
 * Body: { occasion?, styles?[], colours?[], budget_min?, budget_max?,
 * product_type?, category_slug?, locale?, session_id?, channel? }.
 */
final class OutfitController
{
    use Responder;

    private const FEATURE = 'outfit';

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly OutfitComposerService $composer,
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

        $brief = OutfitBrief::fromArray($body);
        if (!$brief->hasEnoughSignal()) {
            throw HttpException::validation([
                'brief' => ['Tell Ain a little about the look — an occasion, style, colour or budget.'],
            ]);
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $userId = $user instanceof User ? $user->getId() : null;
        $locale = $this->resolveLocale($body['locale'] ?? null, $user);
        $sessionId = isset($body['session_id']) && is_scalar($body['session_id'])
            ? mb_substr((string) $body['session_id'], 0, 64) : null;
        $channel = $this->resolveChannel($body['channel'] ?? null);

        // Server-side funnel start. The CLIENT emits the distinct 'ai_outfit_started'
        // on submit, so we do NOT emit it here (avoids double-counting).
        $this->events->recordEvent('ai_query_started', null, $userId, $sessionId, null, null, ['feature' => self::FEATURE]);

        $result = $this->composer->compose($brief, $locale);
        $intent = $result->intent;
        $productIds = $result->productIds();

        $interactionId = $this->events->recordInteraction(
            self::FEATURE,
            $brief->toRankerQuery(),
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
            ['count' => count($result->pieces)],
        );

        $serializer = $this->productSerializer->configureFromRequest($request);
        $items = [];
        $total = 0.0;
        $currency = 'AED';
        foreach ($result->pieces as $piece) {
            $card = $serializer->listShape($piece->product);
            $money = is_array($card['sale_price'] ?? null) ? $card['sale_price'] : ($card['price'] ?? null);
            if (is_array($money) && isset($money['amount']) && is_numeric($money['amount'])) {
                $total += (float) $money['amount'];
                if (isset($money['currency']) && is_string($money['currency'])) {
                    $currency = $money['currency'];
                }
            }
            $items[] = [
                'role' => $piece->role,
                'category_slug' => $piece->categorySlug,
                'reason' => $piece->reason,
                'product' => $card,
            ];
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
            'occasion' => $brief->occasion,
            'styles' => $brief->styles,
            'colours' => $brief->colours,
            'rationale' => $result->rationale,
            'items' => $items,
            'total_price' => ['amount' => round($total, 2), 'currency' => $currency],
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
