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
 * GET /v3/me/addresses
 *
 * Return all addresses for the authenticated user, sorted with
 * default-shipping first (so the checkout UI can show it at the top
 * of dropdowns) then by created_at DESC (newest first).
 *
 * Response shape
 * --------------
 *   200 OK
 *   {
 *     "addresses": [
 *       { "id": 42, "recipient_name": "Alice", ... "is_default": true },
 *       { "id": 7,  "recipient_name": "Office", ... "is_default": false }
 *     ]
 *   }
 *
 * No pagination
 * -------------
 * Users typically have 1-5 addresses. Hard cap at 50 (enforced by
 * the create endpoint, M1.7.2 phase A) means the list is always
 * small enough to return in one response. If we hit users with
 * hundreds of addresses (corporate accounts in M4?), we'll add
 * pagination then.
 */
final class ListAddressesController
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

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var AddressRepository $addresses */
        $addresses = $this->em->getRepository(Address::class);
        $rows = $addresses->findAllForUser($user);

        return $this->ok([
            'addresses' => $this->serializer->publicShapeMany($rows),
        ]);
    }
}
