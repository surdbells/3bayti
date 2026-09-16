<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Me\Ai;

use Bayti\Api\Ai\Personalization\ForYouRailsService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ForYouSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/ai/for-you?limit=12
 *
 * Ain Personal Style Profile — personalised "For You / Your Style" rails for an
 * authenticated customer, built from their pre-computed style profile + live
 * catalogue retrieval. Every product is re-validated against inventory; new
 * users with no profile yet get a `popular` cold-start rail (profile_ready=false).
 * Own-data read, no audit emission. Gated by AuthMiddleware via the /v3/me group.
 */
final class GetForYouRailsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly ForYouRailsService $rails,
        private readonly ForYouSerializer $serializer,
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

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $perRail = $this->parsePerRail($query['limit'] ?? null);

        $set = $this->rails->build($user, $perRail);

        return $this->ok($this->serializer->shape($set));
    }

    private function parsePerRail(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return ForYouRailsService::DEFAULT_PER_RAIL;
        }
        $rawStr = (string) $raw;
        if (!is_numeric($rawStr)) {
            return ForYouRailsService::DEFAULT_PER_RAIL;
        }
        return (int) $rawStr;
    }
}
