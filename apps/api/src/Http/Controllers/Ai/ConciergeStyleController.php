<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ai;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\Concierge\ConciergeService;
use Bayti\Api\Ai\Concierge\ConversationHistory;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/ai/concierge/style — "Ask Ain".
 *
 * OptionalAuthMiddleware: works logged-out, personalises (locale) when signed
 * in. Returns real, in-stock, ranked product cards with a one-line reason each;
 * degrades to keyword/filter results when AI is off. Records the interaction +
 * analytics so revenue can be attributed to Ain later.
 *
 * Body: { query, locale?, session_id?, channel?, history? }. history is the
 * conversation BEFORE this query, oldest first: [{ role: 'user'|'ain', text }];
 * only the last 8 entries are kept, each text capped at 500 chars, malformed
 * entries dropped silently (never a 422 — fully backward compatible).
 *
 * Response: { interaction_id, intent, products, reply, follow_ups,
 * needs_clarification } — reply is Ain's short message in the locale,
 * follow_ups 0-3 tappable suggestions; a clarifying question may carry no
 * products.
 */
final class ConciergeStyleController
{
    use Responder;

    private const FEATURE = 'style_concierge';

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly ConciergeService $concierge,
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

        $query = isset($body['query']) && is_scalar($body['query']) ? trim((string) $body['query']) : '';
        if ($query === '') {
            throw HttpException::validation(['query' => ['Tell Ain what you are looking for.']]);
        }
        if (mb_strlen($query) > 500) {
            $query = mb_substr($query, 0, 500);
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $userId = $user instanceof User ? $user->getId() : null;
        $locale = $this->resolveLocale($body['locale'] ?? null, $user);
        $sessionId = isset($body['session_id']) && is_scalar($body['session_id'])
            ? mb_substr((string) $body['session_id'], 0, 64) : null;
        $channel = $this->resolveChannel($body['channel'] ?? null);

        $history = ConversationHistory::fromMixed($body['history'] ?? null);
        $historyLength = $history->count();

        $this->events->recordEvent('ai_query_started', null, $userId, $sessionId, null, null, [
            'feature' => self::FEATURE,
            'history_length' => $historyLength,
        ]);

        $result = $this->concierge->ask($query, $locale, ConciergeService::DEFAULT_LIMIT, $history);
        $productIds = $result->productIds();

        $interactionId = $this->events->recordInteraction(
            self::FEATURE,
            $query,
            $result->intent->toArray(),
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
            [
                'count' => count($result->items),
                'history_length' => $historyLength,
                'needs_clarification' => $result->needsClarification,
            ],
        );

        $serializer = $this->productSerializer->configureFromRequest($request);
        $products = [];
        foreach ($result->items as $item) {
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

        return $this->ok([
            'interaction_id' => $interactionId,
            'intent' => $result->intent->toArray(),
            'products' => $products,
            'reply' => $result->reply,
            'follow_ups' => $result->followUps,
            'needs_clarification' => $result->needsClarification,
        ]);
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
