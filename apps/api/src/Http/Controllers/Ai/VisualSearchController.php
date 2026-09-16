<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ai;

use Bayti\Api\Ai\Analytics\AiEventLogger;
use Bayti\Api\Ai\Vision\VisualSearchService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/ai/visual-search — "find pieces like this photo".
 *
 * OptionalAuthMiddleware: works logged-out, personalises (locale) when signed in.
 * The body carries a query image (a data URL or raw base64 + mime_type); Ain
 * embeds it and returns visually similar, real, in-stock products. The query
 * image is NEVER persisted. Per-IP + per-user throttled (upload + a vision call
 * are costly). Feature-gated: with VISION_ENABLED off the embedder is the null
 * object and this returns an empty result set.
 *
 * Body: { image (data URL or base64), mime_type?, limit?, locale?, session_id?, channel? }.
 */
final class VisualSearchController
{
    use Responder;
    use RequestContext;

    private const FEATURE = 'visual_search';
    private const MAX_BYTES = 6_000_000;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const COOLDOWN_SECONDS = 3;
    private const HOUR_SECONDS = 3600;
    private const PER_IP_HOURLY_CAP = 40;
    private const PER_USER_HOURLY_CAP = 60;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly VisualSearchService $search,
        private readonly ProductSerializer $productSerializer,
        private readonly AiEventLogger $events,
        private readonly KeyValueStore $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        [$bytes, $mime] = $this->decodeImage($body);

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        $userId = $user instanceof User ? $user->getId() : null;
        $this->enforceRateLimits($this->extractIp($request), $userId);

        $limit = $this->parseLimit($body['limit'] ?? null);
        $locale = $this->resolveLocale($body['locale'] ?? null, $user);
        $sessionId = isset($body['session_id']) && is_scalar($body['session_id'])
            ? mb_substr((string) $body['session_id'], 0, 64) : null;
        $channel = $this->resolveChannel($body['channel'] ?? null);

        $this->events->recordEvent('ai_query_started', null, $userId, $sessionId, null, null, ['feature' => self::FEATURE]);

        $result = $this->search->search($bytes, $mime, $limit);
        $productIds = $result->productIds();

        $interactionId = $this->events->recordInteraction(
            self::FEATURE,
            $result->description ?? 'visual search',
            $result->description !== null ? ['description' => $result->description] : null,
            $productIds,
            $userId,
            $sessionId,
            $channel,
            $locale,
        );
        $this->events->recordEvent('ai_query_completed', $interactionId, $userId, $sessionId, null, null, ['count' => count($productIds)]);

        $serializer = $this->productSerializer->configureFromRequest($request);
        $products = array_map(fn ($p): array => $serializer->listShape($p), $result->products);

        $this->events->recordEvent('ai_product_recommendation_displayed', $interactionId, $userId, $sessionId, null, null, ['product_ids' => $productIds]);

        return $this->ok([
            'interaction_id' => $interactionId,
            'description' => $result->description,
            'products' => $products,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string} decoded bytes + mime
     */
    private function decodeImage(array $body): array
    {
        $raw = isset($body['image']) && is_string($body['image']) ? $body['image'] : '';
        if ($raw === '') {
            throw HttpException::badRequest('An image is required (field "image").');
        }

        $mime = isset($body['mime_type']) && is_string($body['mime_type']) ? strtolower(trim($body['mime_type'])) : '';
        $b64 = $raw;
        if (preg_match('#^data:([\w/+.-]+);base64,(.+)$#s', $raw, $m) === 1) {
            $mime = strtolower($m[1]);
            $b64 = $m[2];
        }

        if ($mime !== '' && !in_array($mime, self::ALLOWED_MIMES, true)) {
            throw HttpException::badRequest('Unsupported image type. Use JPEG, PNG or WebP.');
        }

        $bytes = base64_decode($b64, true);
        if ($bytes === false || $bytes === '') {
            throw HttpException::badRequest('The image could not be decoded.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw HttpException::badRequest('The image is too large (max 6 MB).');
        }

        return [$bytes, $mime !== '' ? $mime : 'image/jpeg'];
    }

    private function enforceRateLimits(?string $ip, ?int $userId): void
    {
        if ($userId !== null) {
            $this->enforceHourlyCap('ai:vsearch:rl:user:' . $userId, self::PER_USER_HOURLY_CAP);
        }
        if ($ip !== null && $ip !== '') {
            $this->enforceCooldown('ai:vsearch:cd:ip:' . $ip);
            $this->enforceHourlyCap('ai:vsearch:rl:ip:' . $ip, self::PER_IP_HOURLY_CAP);
        }
    }

    private function enforceCooldown(string $key): void
    {
        try {
            if ($this->cache->setIfAbsent($key, (string) time(), self::COOLDOWN_SECONDS) === false) {
                throw HttpException::rateLimited(message: 'Please wait a moment before searching again.');
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('visual-search cooldown cache failure — allowing', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function enforceHourlyCap(string $key, int $cap): void
    {
        try {
            $count = $this->cache->incr($key);
            if ($count === 1) {
                $this->cache->expire($key, self::HOUR_SECONDS);
            }
            if ($count > $cap) {
                throw HttpException::rateLimited(message: 'Too many searches. Please try again later.');
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('visual-search hourly-cap cache failure — allowing', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function parseLimit(mixed $raw): int
    {
        if ((is_string($raw) || is_int($raw)) && is_numeric((string) $raw)) {
            return (int) $raw;
        }
        return VisualSearchService::DEFAULT_LIMIT;
    }

    private function resolveLocale(mixed $bodyLocale, mixed $user): string
    {
        $candidate = is_string($bodyLocale) ? strtolower(substr(trim($bodyLocale), 0, 2)) : '';
        if ($candidate === 'en' || $candidate === 'ar') {
            return $candidate;
        }
        if ($user instanceof User && strtolower(substr($user->getLocale(), 0, 2)) === 'ar') {
            return 'ar';
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
