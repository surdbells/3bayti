<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Ai;

use Bayti\Api\Ai\TryOn\VirtualTryOnProviderInterface;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\TryOn\TryOnJob;
use Bayti\Api\Domain\TryOn\TryOnJobRepository;
use Bayti\Api\Domain\TryOn\TryOnPhotoStorage;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/ai/try-on — start an AI virtual try-on.
 *
 * AuthMiddleware (signed-in only): a person's photo is processed, so try-on is
 * never anonymous. The body carries the customer photo (data URL or base64 +
 * mime_type) and the garment `product_id` (v3 id — never a legacy id). The
 * request only ENQUEUES a {@see TryOnJob}; the multi-second image-model call
 * runs in the `ai:process-tryon-jobs` worker and the client polls
 * GET /v3/ai/try-on/{reference}.
 *
 * Guards: TRYON_ENABLED (else 422 TRYON_DISABLED); explicit photo consent (else
 * 422 TRYON_CONSENT_REQUIRED — pass `consent: true` to grant it inline on first
 * use); the product must be try-on-enabled + orderable + in stock; per-user
 * rate limits. The raw photo is stored PRIVATELY and deleted as soon as
 * generation finishes.
 *
 * Body: { image (data URL or base64), mime_type?, product_id, consent? }.
 */
final class StartTryOnController
{
    use Responder;
    use RequestContext;

    private const MAX_BYTES = 8_000_000;
    private const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const COOLDOWN_SECONDS = 5;
    private const HOUR_SECONDS = 3600;
    private const PER_USER_HOURLY_CAP = 15;
    private const PER_USER_DAILY_CAP = 40;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly VirtualTryOnProviderInterface $provider,
        private readonly TryOnPhotoStorage $photos,
        private readonly EntityManagerInterface $em,
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
        if (!$this->provider->isEnabled()) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::TRYON_DISABLED,
                'Virtual try-on is not available right now.',
            );
        }

        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User || $user->getId() === null) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }
        $userId = $user->getId();

        $body = (array) ($request->getParsedBody() ?? []);

        $product = $this->resolveProduct($body['product_id'] ?? null);

        /* Explicit consent: a third-party image model processes the person's
           photo, so require an affirmative opt-in, granted inline on first use. */
        if (!$user->hasTryOnConsent()) {
            if ($this->boolField($body['consent'] ?? null)) {
                $user->grantTryOnConsent();
            } else {
                throw HttpException::businessRuleViolation(
                    ErrorCodes::TRYON_CONSENT_REQUIRED,
                    'Please agree to virtual try-on photo processing to continue.',
                );
            }
        }

        $this->enforceRateLimits($userId);

        [$bytes, $mime] = $this->decodeImage($body);

        $sourcePath = sprintf('try-on/%d/in/%s.%s', $userId, bin2hex(random_bytes(16)), $this->extension($mime));
        $this->photos->storeInput($bytes, $sourcePath);

        $job = new TryOnJob($userId, (int) $product->getId(), $sourcePath);
        $this->em->persist($job);
        $this->em->flush();

        return $this->created([
            'job_reference' => $job->getJobReference(),
            'status' => $job->getStatus(),
        ]);
    }

    private function resolveProduct(mixed $rawId): Product
    {
        $id = (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) ? (int) $rawId : 0;
        if ($id <= 0) {
            throw HttpException::badRequest('A valid product_id is required.');
        }

        $product = $this->em->find(Product::class, $id);
        if (!$product instanceof Product || !$product->isTryOnEnabled()) {
            throw HttpException::notFound('This product is not available for try-on.');
        }
        if (!$product->isOrderable() || !$product->isInStock()) {
            throw HttpException::businessRuleViolation(
                ErrorCodes::BUSINESS_RULE_VIOLATION,
                'This product is not currently available.',
            );
        }

        return $product;
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string} decoded bytes + mime
     */
    private function decodeImage(array $body): array
    {
        $raw = isset($body['image']) && is_string($body['image']) ? $body['image'] : '';
        if ($raw === '') {
            throw HttpException::badRequest('A photo is required (field "image").');
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
            throw HttpException::badRequest('The photo could not be decoded.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw HttpException::badRequest('The photo is too large (max 8 MB).');
        }

        return [$bytes, $mime !== '' ? $mime : 'image/jpeg'];
    }

    private function enforceRateLimits(int $userId): void
    {
        /* Rolling-24h cap first (durable, survives cache flushes). */
        $repo = $this->em->getRepository(TryOnJob::class);
        if ($repo instanceof TryOnJobRepository) {
            $recent = $repo->countForUserSince($userId, new \DateTimeImmutable('-24 hours'));
            if ($recent >= self::PER_USER_DAILY_CAP) {
                throw HttpException::rateLimited(message: 'Daily try-on limit reached. Please try again tomorrow.');
            }
        }

        $this->enforceCooldown('ai:tryon:cd:user:' . $userId);
        $this->enforceHourlyCap('ai:tryon:rl:user:' . $userId, self::PER_USER_HOURLY_CAP);
    }

    private function enforceCooldown(string $key): void
    {
        try {
            if ($this->cache->setIfAbsent($key, (string) time(), self::COOLDOWN_SECONDS) === false) {
                throw HttpException::rateLimited(message: 'Please wait a moment before trying on another look.');
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('try-on cooldown cache failure — allowing', ['key' => $key, 'error' => $e->getMessage()]);
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
                throw HttpException::rateLimited(message: 'Too many try-ons. Please try again later.');
            }
        } catch (KeyValueStoreException $e) {
            $this->logger->warning('try-on hourly-cap cache failure — allowing', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function boolField(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function extension(string $mime): string
    {
        return match (strtolower($mime)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
