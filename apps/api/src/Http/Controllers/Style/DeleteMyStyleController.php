<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Style;

use Bayti\Api\Domain\Catalog\Style;
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
 * DELETE /v3/me/styles/{id}, remove one of the authenticated user's own
 * saved looks.
 *
 * Soft delete: sets is_active=false rather than hard-deleting the row, so
 * the action is reversible and the style simply stops resolving through
 * findActiveBySlug / the owner-scoped list. 404 if it doesn't exist OR
 * isn't the caller's (no existence leak). Idempotent: an already-removed
 * (inactive) owned style returns 204, "already gone".
 */
final class DeleteMyStyleController
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

        $id = (int) ($args['id'] ?? 0);
        $style = $id > 0 ? $this->em->find(Style::class, $id) : null;
        if (!$style instanceof Style || $style->getCreatedByUser()?->getId() !== $user->getId()) {
            throw HttpException::notFound('Style not found.');
        }

        if ($style->isActive()) {
            $style->setActive(false);
            $this->em->flush();
        }

        return $this->noContent();
    }
}
