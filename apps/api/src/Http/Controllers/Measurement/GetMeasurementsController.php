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
 * GET /v3/me/measurements/default
 * GET /v3/me/measurements/category/{id}
 *
 * Return one specific measurement set:
 *   /default              → categoryId IS NULL (the default set)
 *   /category/{id}        → that specific category's set
 *
 * If no measurement set exists for the requested key, returns 200
 * with `{"measurements": null}`. We deliberately don't 404 here -
 * the URL "your default measurements" always exists conceptually,
 * even if no row has been written yet. The frontend uses null to
 * decide whether to show an empty form vs a populated one, which
 * is cleaner than catching 404s.
 *
 * The same controller handles both routes; the route definition
 * sets the {id} arg or omits it depending on which URL hit.
 */
final class GetMeasurementsController
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

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        // Resolve which measurement set: /default → null, /category/{id} → int.
        // The route definition determines whether $args['id'] is set
        // or absent. We use absence to mean "the /default route fired."
        $categoryId = null;
        if (isset($args['id'])) {
            $idRaw = (string) $args['id'];
            if (!ctype_digit($idRaw) || (int) $idRaw < 1) {
                // Bad path arg → 404. We don't 422 here because the
                // URL itself is malformed, not the body.
                throw HttpException::notFound('Measurement set not found.');
            }
            $categoryId = (int) $idRaw;
        }

        /** @var MeasurementRepository $repo */
        $repo = $this->em->getRepository(Measurement::class);
        $measurement = $repo->findForUserAndCategory($user, $categoryId);

        return $this->ok([
            'measurements' => $measurement !== null
                ? $this->serializer->publicShape($measurement)
                : null,
        ]);
    }
}
