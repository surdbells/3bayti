<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
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
 * GET /v3/me/addresses/{id}
 *
 * Return a single address by id. Must belong to the authenticated user.
 *
 * Authorization vs identification
 * --------------------------------
 * If the address exists but belongs to a different user, we return
 * 404, NOT 403. Returning 403 leaks the existence of the resource
 * ("yes, address 47 exists, but you can't see it"). 404 is "no such
 * address that you can access," which is true from your perspective.
 *
 * This is a deliberate IDOR-prevention pattern. Same applies to PUT,
 * DELETE, and PATCH /default in phase B.
 *
 * Response shape
 * --------------
 *   200 OK
 *   { "address": { "id": 42, "recipient_name": "Alice", ... } }
 *
 *   404 if id doesn't exist OR isn't yours.
 */
final class GetAddressController
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
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        // Slim's route placeholder gives us the id as a string.
        // Cast carefully, non-numeric ids should 404, not 500.
        $idRaw = $args['id'] ?? '';
        if (!ctype_digit((string) $idRaw)) {
            throw HttpException::notFound('Address not found.');
        }
        $id = (int) $idRaw;

        $address = $this->em->getRepository(Address::class)->find($id);

        // Combined "doesn't exist" + "doesn't belong to you" → 404.
        // See class docblock for IDOR prevention rationale.
        if ($address === null || $address->getUser()->getId() !== $user->getId()) {
            throw HttpException::notFound('Address not found.');
        }

        return $this->ok([
            'address' => $this->serializer->publicShape($address),
        ]);
    }
}
