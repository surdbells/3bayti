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
 * PATCH /v3/me/wishlist/labels/{id}  body { name }, rename a label.
 *
 * 404 if the label isn't found / not owned by the user. 409 if the new
 * name collides with another of the user's labels.
 */
final class RenameWishlistLabelController
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

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $labelId = (int) ($args['id'] ?? 0);

        $input = $this->validator->parse($request, WishlistLabelInput::class);
        /** @var string $name */
        $name = $input->name;

        /** @var WishlistLabelRepository $labelRepo */
        $labelRepo = $this->em->getRepository(WishlistLabel::class);

        $label = $labelId > 0 ? $labelRepo->findOneForUser($user, $labelId) : null;
        if ($label === null) {
            throw HttpException::notFound('Label not found.');
        }

        // Name collision with a DIFFERENT label → 409.
        $clash = $labelRepo->findOneForUserByName($user, $name);
        if ($clash !== null && $clash->getId() !== $label->getId()) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_DUPLICATE,
                'A label with that name already exists.',
            );
        }

        $label->rename($name);
        $labelRepo->save($label);

        return $this->ok(PaginatedEnvelope::single($this->serializer->publicShape($label)));
    }
}
