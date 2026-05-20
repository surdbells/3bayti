<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\Wishlist\WishlistLabel;
use Bayti\Api\Domain\Wishlist\WishlistLabelRepository;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /v3/me/wishlist/labels/{id} — delete a label.
 *
 * Idempotent: 204 whether or not the label existed. Saved products
 * filed under it are NOT removed — the FK is ON DELETE SET NULL, so
 * they fall back to uncategorized. (We must clear the in-memory
 * relation on any loaded Wishlist rows too; but since we don't load
 * them here, the DB-level SET NULL handles it on the next read.)
 */
final class DeleteWishlistLabelController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
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

        /** @var WishlistLabelRepository $labelRepo */
        $labelRepo = $this->em->getRepository(WishlistLabel::class);

        $label = $labelId > 0 ? $labelRepo->findOneForUser($user, $labelId) : null;
        if ($label !== null) {
            $labelRepo->remove($label);
        }

        return $this->noContent();
    }
}
