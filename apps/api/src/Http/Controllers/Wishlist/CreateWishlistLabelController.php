<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\Wishlist\WishlistLabel;
use Bayti\Api\Domain\Wishlist\WishlistLabelRepository;
use Bayti\Api\Http\Controllers\Wishlist\Dto\WishlistLabelInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\WishlistLabelSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/wishlist/labels  body { name }, create a label.
 *
 * Idempotent on name: if the user already has a label with that name,
 * returns the existing one (200) instead of erroring; new → 201.
 */
final class CreateWishlistLabelController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly WishlistLabelSerializer $serializer,
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
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $input = $this->validator->parse($request, WishlistLabelInput::class);
        /** @var string $name */
        $name = $input->name;

        /** @var WishlistLabelRepository $labelRepo */
        $labelRepo = $this->em->getRepository(WishlistLabel::class);

        $existing = $labelRepo->findOneForUserByName($user, $name);
        if ($existing !== null) {
            return $this->ok(PaginatedEnvelope::single($this->serializer->publicShape($existing)));
        }

        $label = new WishlistLabel($user, $name);
        $labelRepo->save($label);

        return $this->created(PaginatedEnvelope::single($this->serializer->publicShape($label)));
    }
}
