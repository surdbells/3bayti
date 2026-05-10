<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Measurement;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\MeasurementRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/me/measurements/default
 * DELETE /v3/me/measurements/category/{id}
 *
 * Delete a measurement set entirely. Hard delete — measurement data
 * is small enough that soft-delete adds query complexity for no
 * benefit.
 *
 * Idempotent — DELETE on a non-existent set returns 204 (the
 * desired end state is achieved either way). Same shape as DELETE
 * on a real set.
 *
 * Why 204 not 404 for missing
 * ---------------------------
 * Like DELETE in S3 / Azure Blob, deleting something that doesn't
 * exist is a successful no-op. The user's intent ("ensure this
 * resource doesn't exist") is satisfied. 404 here would force the
 * frontend to handle 404 as "actually that's fine" which is awkward.
 */
final class DeleteMeasurementsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly AuditEmitter $audit,
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

        $categoryId = null;
        if (isset($args['id'])) {
            $idRaw = (string) $args['id'];
            if (!ctype_digit($idRaw) || (int) $idRaw < 1) {
                // Bad path arg — could be 404, but for DELETE we
                // treat it as a no-op too (idempotency wins).
                return $this->noContent();
            }
            $categoryId = (int) $idRaw;
        }

        /** @var MeasurementRepository $repo */
        $repo = $this->em->getRepository(Measurement::class);
        $existing = $repo->findForUserAndCategory($user, $categoryId);

        if ($existing !== null) {
            // M1.6.1.C — capture pre-delete state. Same caveat as
            // DeleteAddressController: id may be reset by Doctrine
            // after remove(), so we capture it explicitly and
            // restore via reflection if needed.
            $beforeSnapshot = $this->audit->snapshot($existing);
            $deletedId = $existing->getId();

            $repo->remove($existing);

            if ($deletedId !== null) {
                $ref = new \ReflectionProperty(Measurement::class, 'id');
                $ref->setAccessible(true);
                $ref->setValue($existing, $deletedId);
            }

            $this->audit->recordDelete(
                request: $request,
                actor: $user,
                subject: $existing,
                beforeSnapshot: $beforeSnapshot,
            );
        }

        return $this->noContent();
    }
}
