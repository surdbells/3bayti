<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Measurement;

use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\MeasurementRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\MeasurementSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/measurements
 *
 * List all measurement sets for the authenticated user (default +
 * any category-specific sets).
 *
 * Response shape
 * --------------
 *   200 OK
 *   {
 *     "measurements": [
 *       {
 *         "id": 1,
 *         "category_id": null,
 *         "values": { "arm": 60.0, "bust": 92.0 },
 *         "notes": "Default measurements",
 *         "updated_at": "2026-05-09T14:00:00+00:00"
 *       },
 *       {
 *         "id": 2,
 *         "category_id": 42,
 *         "values": { "foot_length": 26.5 },
 *         "notes": null,
 *         "updated_at": "2026-05-09T14:01:00+00:00"
 *       }
 *     ]
 *   }
 *
 * Empty list (no sets) returns 200 with `{"measurements": []}`.
 */
final class ListMeasurementsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly MeasurementSerializer $serializer,
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
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var MeasurementRepository $repo */
        $repo = $this->em->getRepository(Measurement::class);
        $rows = $repo->findAllForUser($user);

        return $this->ok([
            'measurements' => $this->serializer->publicShapeMany($rows),
        ]);
    }
}
