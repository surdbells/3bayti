<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me;

use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\RecommendationsSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/recommendations?limit=10
 *
 * Authenticated personalized recommendations endpoint
 * (M3.2.X.12-G).
 *
 * Q-PersonalizedScope = C locked: requires authenticated user.
 * Authenticated callers get personalized recommendations based
 * on their order history (their most-purchased category over the
 * last 180 days → top popular products in that category they
 * haven't bought yet). Anonymous callers would get popular
 * fallback BUT the route is gated by AuthMiddleware so anonymous
 * requests get 401 before reaching this controller.
 *
 * No audit emission, own-data read is non-auditable.
 */
final class GetMeRecommendationsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RecommendationsService $recommendations,
        private readonly RecommendationsSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $userId = $user->getId();
        if ($userId === null) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $limit = $this->parseLimit($query['limit'] ?? null);

        $recs = $this->recommendations->getRecommendationsForUser($userId, $limit);

        return $this->ok($this->serializer->shape($recs, $limit));
    }

    private function parseLimit(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return RecommendationsService::DEFAULT_LIMIT;
        }
        $rawStr = (string) $raw;
        if (!is_numeric($rawStr)) {
            return RecommendationsService::DEFAULT_LIMIT;
        }
        return (int) $rawStr;
    }
}
