<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserLocation;
use Bayti\Api\Domain\User\UserLocationRepository;
use Bayti\Api\Http\Controllers\Profile\Dto\UpdateLocationInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserLocationSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/me/location
 *
 * Upsert the authenticated user's current location.
 *
 * Upsert semantics
 * ----------------
 * If the user has an existing UserLocation row (1-to-1 via the unique
 * index on user_id), update it. Otherwise create one. Either way the
 * response shape is identical and the HTTP status is 200.
 *
 * Why 200 in both create and update cases
 * ----------------------------------------
 * From the client's perspective the request was identical (it didn't
 * know which path the server would take). 200 in both cases means
 * clients don't have to handle two success codes for the same
 * operation. Same convention as UpsertBillingAddressController (1c).
 *
 * Request body
 * ------------
 * All fields are optional (Merge Patch semantics):
 *
 *   {
 *     "latitude": 25.276987,           optional; must pair with longitude
 *     "longitude": 55.296249,          optional; must pair with latitude
 *     "city": "Dubai",                 optional; '' clears
 *     "country_code": "AE",            optional; '' clears; case-insensitive
 *     "permission_granted": true        optional
 *   }
 *
 * Three legitimate body shapes:
 *
 *   - {permission_granted: false} after OS-permission denial
 *   - {latitude, longitude, permission_granted: true} after successful
 *     capture
 *   - {city, country_code} after manual entry
 *
 * Empty body is valid and a no-op (returns current location state).
 *
 * Response shape
 * --------------
 * 200 OK with the full location after the update:
 *
 *   {
 *     "location": {
 *       "latitude": 25.276987 | null,
 *       "longitude": 55.296249 | null,
 *       "city": "Dubai" | null,
 *       "country_code": "AE" | null,
 *       "permission_granted": true,
 *       "last_captured_at": "ISO-8601" | null,
 *       "updated_at": "ISO-8601"
 *     }
 *   }
 *
 * Deviation from 0e.2 contract
 * ----------------------------
 * The 0e.2 contract specified the response as { user: <full profile> }.
 * Implementation revised to { location: <location data> } because:
 *
 *   1. UserSerializer::publicProfile does NOT include location fields
 *      (verified in M3.1.1a audit). Wrapping the location response
 *      in 'user' would either be misleading (location values not
 *      visible inside user shape) or force changes to UserSerializer.
 *   2. Returning { location: ... } is honest: this endpoint mutates
 *      a distinct resource (UserLocation), not the User entity.
 *   3. If the client also needs the user profile, they can fetch
 *      /v3/me/profile separately. Two round trips, but rare.
 *
 * The deviation is documented here; M3.1.1h will reconcile the 0e.2
 * contract text.
 *
 * Error responses
 * ---------------
 *   401 AUTH_MISSING_TOKEN / AUTH_INVALID_TOKEN — handled by middleware
 *   422 VALIDATION_FAILED — invalid lat/lng range, partial coords,
 *                          country_code not 2 alpha chars, etc.
 *
 * Audit log
 * ---------
 * Per M1.6.1.C, location updates emit an audit_log row. Snapshot
 * before/after for diff. CREATE path emits 'created' event; UPDATE
 * path emits 'updated'.
 *
 * Note: location data may be considered PII in some jurisdictions.
 * The audit row stores lat/lng which makes deletion subject to
 * future GDPR-style purge logic. M5 hardening will revisit; for now
 * the audit row is the same as for any other entity update.
 */
final class UpdateLocationController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly UserLocationSerializer $serializer,
        private readonly AuditEmitter $audit,
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

        $input = $this->validator->parse($request, UpdateLocationInput::class);

        /** @var UserLocationRepository $locations */
        $locations = $this->em->getRepository(UserLocation::class);
        $existing = $locations->findForUser($user);

        if ($existing !== null) {
            return $this->updatePath($request, $user, $existing, $input);
        }

        return $this->createPath($request, $user, $locations, $input);
    }

    /**
     * UPDATE path — existing UserLocation row gets selected fields
     * rewritten via the entity's update() method.
     */
    private function updatePath(
        ServerRequestInterface $request,
        User $user,
        UserLocation $location,
        UpdateLocationInput $input,
    ): ResponseInterface {
        $beforeSnapshot = $this->audit->snapshot($location);

        $location->update(
            latitude: $input->latitude,
            longitude: $input->longitude,
            city: $input->city,
            countryCode: $input->country_code,
            permissionGranted: $input->permission_granted,
        );

        $this->em->flush();

        $afterSnapshot = $this->audit->snapshot($location);
        // Only emit audit if something actually changed. AuditEmitter
        // computes the diff; we skip the call entirely when before==after
        // for performance + log hygiene.
        if ($beforeSnapshot !== $afterSnapshot) {
            $this->audit->recordUpdate(
                request: $request,
                actor: $user,
                subject: $location,
                beforeSnapshot: $beforeSnapshot,
                afterSnapshot: $afterSnapshot,
            );
        }

        return $this->ok([
            'location' => $this->serializer->publicShape($location),
        ]);
    }

    /**
     * CREATE path — no UserLocation row yet. Create one, apply the
     * input, persist. The DB-level UNIQUE on user_id prevents the
     * race where two concurrent first-PATCH calls both try to create.
     */
    private function createPath(
        ServerRequestInterface $request,
        User $user,
        UserLocationRepository $locations,
        UpdateLocationInput $input,
    ): ResponseInterface {
        $location = new UserLocation($user);

        $location->update(
            latitude: $input->latitude,
            longitude: $input->longitude,
            city: $input->city,
            countryCode: $input->country_code,
            permissionGranted: $input->permission_granted,
        );

        $locations->save($location);

        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $location,
            afterSnapshot: $this->audit->snapshot($location),
        );

        return $this->ok([
            'location' => $this->serializer->publicShape($location),
        ]);
    }
}
