<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/me/billing-address
 *
 * Returns the authenticated user's default-billing address, or
 * { "address": null } if none is set.
 *
 * Why this endpoint exists alongside /v3/me/addresses
 * ---------------------------------------------------
 * /v3/me/addresses returns ALL of the user's addresses. To find the
 * billing one, a client would have to scan the list for the entry
 * with is_default_billing=true. That's fine but adds a round trip
 * + client-side filtering for the very common "what's my billing"
 * query (used by checkout flows + account settings page).
 *
 * This endpoint is a convenience accessor on top of the existing
 * addresses table. No new storage; no new entity. Just calls
 * AddressRepository::findDefaultBillingForUser. See
 * docs/runbooks/m3/m3.1.1b-billing-address-decision.md for the ADR.
 *
 * Response shapes
 * ---------------
 *   200 OK with address set:
 *     { "address": { "id": 42, "recipient_name": "...", ..., "is_default_billing": true } }
 *
 *   200 OK with no billing address:
 *     { "address": null }
 *
 *   401 if no/invalid token (AuthMiddleware fires before us)
 *
 * Why not 404 when not set
 * ------------------------
 * Returning 404 would conflate "no resource" with "wrong URL".
 * Billing-address-not-set is an expected state for new users.
 * { "address": null } is the right semantic.
 *
 * The companion PATCH endpoint upserts — clients calling GET to
 * read, then PATCH to write, never have to handle a 404 transition
 * mid-flow.
 */
final class GetBillingAddressController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly AddressSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            // Defensive — AuthMiddleware should have already 401'd
            // before reaching us. Keep the check for completeness.
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var AddressRepository $addresses */
        $addresses = $this->em->getRepository(Address::class);
        $billing = $addresses->findDefaultBillingForUser($user);

        return $this->ok([
            'address' => $billing !== null
                ? $this->serializer->publicShape($billing)
                : null,
        ]);
    }
}
