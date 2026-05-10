<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Measurement;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\MeasurementRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Measurement\Dto\UpsertMeasurementsInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\MeasurementSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/me/measurements/default
 * PUT /v3/me/measurements/category/{id}
 *
 * Upsert (create-or-replace) a measurement set.
 *
 * Why upsert vs separate POST + PUT
 * ----------------------------------
 * The tuple (user_id, category_id) uniquely identifies a measurement
 * set. There's no scenario where the user creates "another default
 * measurement" — they have one default OR none. Same for any given
 * category. So PUT is genuinely idempotent:
 *
 *   - First PUT: creates the row
 *   - Subsequent PUTs: replace values + notes on the same row
 *
 * No POST endpoint because there's nothing to POST — every
 * "creation" is keyed by a category, which is in the URL.
 *
 * Why full replace not partial
 * ----------------------------
 * Measurements are tightly correlated — changing 'bust' often means
 * 'arm' and 'hip' need updating too. PATCH semantics ("update only
 * the fields you send") would let users leave inconsistent partial
 * data. PUT forces them to send the complete current set.
 *
 * If a user wants to clear all measurements, they PUT with empty
 * `values: {}` (which is valid — see UpsertMeasurementsInput).
 *
 * Response
 * --------
 *   200 OK with the (newly created or updated) measurement set.
 *   We don't return 201 on first-create because PUT semantics treat
 *   create-or-replace as the same operation; the caller doesn't
 *   need to distinguish.
 */
final class UpsertMeasurementsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly MeasurementSerializer $serializer,
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

        // Same logic as GetMeasurementsController: /default → null,
        // /category/{id} → int.
        $categoryId = null;
        if (isset($args['id'])) {
            $idRaw = (string) $args['id'];
            if (!ctype_digit($idRaw) || (int) $idRaw < 1) {
                throw HttpException::notFound('Measurement set not found.');
            }
            $categoryId = (int) $idRaw;
        }

        $input = $this->validator->parse($request, UpsertMeasurementsInput::class);

        /** @var MeasurementRepository $repo */
        $repo = $this->em->getRepository(Measurement::class);
        $existing = $repo->findForUserAndCategory($user, $categoryId);

        if ($existing !== null) {
            // M1.6.1.C — capture pre-mutation state for the diff.
            $beforeSnapshot = $this->audit->snapshot($existing);

            // Update path — full replace of values + notes (PUT
            // semantics, not partial). Use setValues/setNotes
            // directly rather than update() because update() treats
            // null as 'no change' which conflicts with PUT 'authoritative
            // body' semantics.
            $existing->setValues($input->valuesAsFloats());
            $existing->setNotes($input->notes);
            $this->em->flush();
            $measurement = $existing;

            $this->audit->recordUpdate(
                request: $request,
                actor: $user,
                subject: $measurement,
                beforeSnapshot: $beforeSnapshot,
                afterSnapshot: $this->audit->snapshot($measurement),
            );
        } else {
            // Create path — new row.
            $measurement = new Measurement(
                user: $user,
                values: $input->valuesAsFloats(),
                categoryId: $categoryId,
                notes: $input->notes,
            );
            $repo->save($measurement);

            $this->audit->recordCreate(
                request: $request,
                actor: $user,
                subject: $measurement,
                afterSnapshot: $this->audit->snapshot($measurement),
            );
        }

        return $this->ok([
            'measurements' => $this->serializer->publicShape($measurement),
        ]);
    }
}
