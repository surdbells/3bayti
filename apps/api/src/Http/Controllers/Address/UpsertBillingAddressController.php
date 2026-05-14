<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Address\Dto\UpsertBillingAddressInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v3/me/billing-address
 *
 * Upsert the authenticated user's default-billing address.
 *
 * Upsert semantics
 * ----------------
 * If the user has an existing default-billing address: UPDATE its
 * fields in place. The is_default_billing flag stays true.
 *
 * If the user has NO default-billing address: CREATE a new Address
 * row with is_default_billing=true. No other default-billing rows
 * exist (per the partial unique index on the addresses table) so
 * no demotion is needed.
 *
 * Deviation from M3.1.1b ADR
 * --------------------------
 * The ADR originally proposed returning 404 BILLING_ADDRESS_NOT_SET
 * when no billing address exists. Implementation revised this to
 * upsert because:
 *
 *   1. Legacy mobile's updateBilling behaves as upsert (the customer
 *      flow doesn't pre-check; first call creates).
 *   2. A 404 from PATCH would force clients to handle "first call
 *      vs subsequent calls" differently, which is brittle UX.
 *   3. Upsert is also REST-idiomatic for PATCH on a singleton resource
 *      (think /me/preferences) where the resource implicitly exists
 *      for every authenticated user.
 *
 * The ADR file is preserved; this docblock notes the revision and
 * M3.1.1h will reconcile the two.
 *
 * Why this endpoint instead of /addresses + flag-via-default
 * ----------------------------------------------------------
 * The existing PATCH /v3/me/addresses/{id}/default already supports
 * { "billing": true } to flag an EXISTING address as default-billing.
 * That covers the "set billing == shipping" workflow (Mode 1 of the
 * ADR).
 *
 * This endpoint covers Mode 2: managing the billing address as a
 * standalone resource without first needing to know which Address
 * row backs it. Useful when:
 *   - Customer's billing details (name, tax info) differ from
 *     shipping recipient (gift orders, business buyers).
 *   - Customer wants to update the billing address without touching
 *     their shipping list.
 *
 * Both endpoints coexist by design.
 *
 * Request body
 * ------------
 *   {
 *     "recipient_name": "Alice",            REQUIRED
 *     "recipient_phone": "+971501234567",   REQUIRED (E.164)
 *     "emirate": "Dubai",                   REQUIRED
 *     "area": "Jumeirah",                   REQUIRED
 *     "street_address": "Beach Road",       optional
 *     "building_details": "Villa 12",       optional
 *     "postal_code": "12345",               optional
 *     "label": "Office"                     optional
 *   }
 *
 * Response
 * --------
 *   200 OK (whether created or updated)
 *   { "address": { "id": ..., "recipient_name": "...", ..., "is_default_billing": true } }
 *
 *   422 if validation fails
 *   401 if no/invalid token (AuthMiddleware fires first)
 *
 * Why 200 in both create and update cases
 * ---------------------------------------
 * A spec-pedantic reading would return 201 on create and 200 on
 * update. From the client's perspective the request was identical
 * (it didn't know which path it would take) and the response is
 * the same shape. 200 in both cases means clients don't have to
 * handle two success codes for the same operation.
 *
 * Address count limit
 * -------------------
 * Same 50-per-user cap as CreateAddressController. If the user is
 * at the cap and has no existing billing address, we 422 — same
 * error code as CreateAddressController for consistency.
 */
final class UpsertBillingAddressController
{
    use Responder;

    /** Mirrors CreateAddressController::MAX_ADDRESSES_PER_USER. */
    private const MAX_ADDRESSES_PER_USER = 50;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly AddressSerializer $serializer,
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

        $input = $this->validator->parse($request, UpsertBillingAddressInput::class);

        /** @var AddressRepository $addresses */
        $addresses = $this->em->getRepository(Address::class);
        $existing = $addresses->findDefaultBillingForUser($user);

        if ($existing !== null) {
            return $this->updatePath($request, $user, $existing, $input);
        }

        return $this->createPath($request, $user, $addresses, $input);
    }

    /**
     * UPDATE path — existing default-billing address gets its fields
     * rewritten. The is_default_billing flag stays true.
     */
    private function updatePath(
        ServerRequestInterface $request,
        User $user,
        Address $address,
        UpsertBillingAddressInput $input,
    ): ResponseInterface {
        $beforeSnapshot = $this->audit->snapshot($address);

        $address->update(
            label: $input->label,
            recipientName: $input->recipient_name,
            recipientPhone: $input->recipient_phone,
            emirate: $input->emirate,
            area: $input->area,
            streetAddress: $input->street_address,
            buildingDetails: $input->building_details,
            postalCode: $input->postal_code,
        );

        $this->em->flush();

        $this->audit->recordUpdate(
            request: $request,
            actor: $user,
            subject: $address,
            beforeSnapshot: $beforeSnapshot,
            afterSnapshot: $this->audit->snapshot($address),
        );

        return $this->ok([
            'address' => $this->serializer->publicShape($address),
        ]);
    }

    /**
     * CREATE path — no default-billing exists yet. Create one. Since
     * the user has no other default-billing row (per partial unique
     * index), no demotion of others needed; just set the flag and
     * persist.
     */
    private function createPath(
        ServerRequestInterface $request,
        User $user,
        AddressRepository $addresses,
        UpsertBillingAddressInput $input,
    ): ResponseInterface {
        // Enforce 50-address cap. We need to count the user's total
        // addresses, not just billing-flagged ones, because we're
        // about to add one row.
        $total = $addresses->findAllForUser($user);
        if (count($total) >= self::MAX_ADDRESSES_PER_USER) {
            throw HttpException::validation([
                '_root' => [
                    sprintf(
                        'Cannot create more than %d addresses per account.',
                        self::MAX_ADDRESSES_PER_USER,
                    ),
                ],
            ]);
        }

        $address = new Address(
            user: $user,
            recipientName: $input->recipient_name,
            recipientPhone: $input->recipient_phone,
            emirate: $input->emirate,
            area: $input->area,
            streetAddress: $input->street_address,
            buildingDetails: $input->building_details,
            postalCode: $input->postal_code,
            label: $input->label,
        );

        $address->setDefaultBilling(true);

        // If this is the user's very first address, also make it
        // their default shipping. Same UX safeguard as
        // CreateAddressController: a user with one address should
        // not have "no default" elsewhere. Without this, a user who
        // starts by setting billing-only ends up with shipping
        // checkout prompting "pick a default" needlessly.
        if (count($total) === 0) {
            $address->setDefaultShipping(true);
        }

        $addresses->save($address);

        $this->audit->recordCreate(
            request: $request,
            actor: $user,
            subject: $address,
            afterSnapshot: $this->audit->snapshot($address),
        );

        return $this->ok([
            'address' => $this->serializer->publicShape($address),
        ]);
    }
}
