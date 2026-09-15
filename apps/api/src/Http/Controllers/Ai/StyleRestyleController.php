<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ai;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\Concierge\Style\StyleRestyleService;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\Catalog\StyleRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/ai/styles/restyle — "restyle this look with Ain".
 *
 * Auth required (it produces a new look for the signed-in customer). Takes a
 * seed look (a saved style_slug OR an ad-hoc list of v3 product ids) + a natural-
 * language instruction, and returns a PREVIEW of a rebuilt look — real, in-stock,
 * ranked products + a rationale. The client persists it via the existing
 * POST /v3/me/styles with source='ai', prompt, rationale.
 *
 * The seed is read-only; you can restyle any active look you can see into a new
 * look of your own, so the seed is resolved without an owner gate.
 *
 * Body: { instruction, style_slug?, products?:[v3 ids], locale?, session_id?, channel? }.
 */
final class StyleRestyleController
{
    use Responder;

    private const FEATURE = 'style_restyle';

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly StyleRestyleService $service,
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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $instruction = isset($body['instruction']) && is_scalar($body['instruction']) ? trim((string) $body['instruction']) : '';
        if ($instruction === '') {
            throw HttpException::validation(['instruction' => ['Tell Ain how to restyle the look.']]);
        }
        $instruction = mb_substr($instruction, 0, 500);

        $seed = $this->resolveSeed($body);
        if ($seed === []) {
            throw HttpException::validation(['seed' => ['Provide a style to restyle (style_slug or products).']]);
        }

        $locale = $this->resolveLocale($body['locale'] ?? null, $user);
        $sessionId = isset($body['session_id']) && is_scalar($body['session_id'])
            ? mb_substr((string) $body['session_id'], 0, 64) : null;
        $channel = $this->resolveChannel($body['channel'] ?? null);

        $this->events->recordEvent('ai_query_started', null, $user->getId(), $sessionId, null, null, ['feature' => self::FEATURE]);

        $result = $this->service->restyle($seed, $instruction, $locale);
        $intent = $result->concierge->intent;
        $productIds = $result->productIds();

        $interactionId = $this->events->recordInteraction(
            self::FEATURE,
            $instruction,
            $intent->toArray(),
            $productIds,
            $user->getId(),
            $sessionId,
            $channel,
            $locale,
        );
        $this->events->recordEvent('ai_query_completed', $interactionId, $user->getId(), $sessionId, null, null, ['count' => count($result->concierge->items)]);

        $serializer = $this->productSerializer->configureFromRequest($request);
        $products = [];
        foreach ($result->concierge->items as $item) {
            $card = $serializer->listShape($item->product);
            $card['reason'] = $item->reason;
            $products[] = $card;
        }

        $this->events->recordEvent('ai_product_recommendation_displayed', $interactionId, $user->getId(), $sessionId, null, null, ['product_ids' => $productIds]);

        return $this->ok([
            'interaction_id' => $interactionId,
            'intent' => $intent->toArray(),
            'instruction' => $result->instruction,
            'rationale' => $result->rationale,
            'seed' => ['product_count' => count($seed)],
            'products' => $products,
            'saved' => false,
        ]);
    }

    /**
     * Resolve the seed look — from a style slug or an ad-hoc list of v3 ids —
     * and re-validate every product with isOrderable() && isInStock().
     *
     * @param array<string, mixed> $body
     * @return list<Product>
     */
    private function resolveSeed(array $body): array
    {
        $candidates = [];

        $slug = isset($body['style_slug']) && is_scalar($body['style_slug']) ? trim((string) $body['style_slug']) : '';
        if ($slug !== '') {
            /** @var StyleRepository $styles */
            $styles = $this->em->getRepository(Style::class);
            $style = $styles->findActiveBySlug($slug);
            if ($style !== null) {
                foreach ($style->getProducts() as $p) {
                    $candidates[] = $p;
                }
            }
        }

        if ($candidates === [] && isset($body['products'])) {
            $ids = $this->parseIds($body['products']);
            if ($ids !== []) {
                /** @var list<Product> $found */
                $found = $this->em->getRepository(Product::class)->findBy(['id' => $ids]);
                $candidates = $found;
            }
        }

        $seed = [];
        foreach ($candidates as $p) {
            if ($p->isOrderable() && $p->isInStock()) {
                $seed[] = $p;
            }
        }
        return $seed;
    }

    /**
     * @return list<int>
     */
    private function parseIds(mixed $raw): array
    {
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];
        foreach ($parts as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function resolveLocale(mixed $bodyLocale, User $user): string
    {
        $candidate = is_string($bodyLocale) ? strtolower(substr(trim($bodyLocale), 0, 2)) : '';
        if ($candidate === 'en' || $candidate === 'ar') {
            return $candidate;
        }
        return strtolower(substr($user->getLocale(), 0, 2)) === 'ar' ? 'ar' : 'en';
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
