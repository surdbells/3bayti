<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Review;

use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\ProductReviewRepository;
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
 * DELETE /v3/me/reviews/{id} — remove one of the authenticated user's
 * own reviews. 404 if it doesn't exist OR isn't theirs (no existence
 * leak); idempotent in spirit (a missing review is "already gone").
 */
final class DeleteMyReviewController
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

        $id     = (int) ($args['id'] ?? 0);
        $review = $id > 0 ? $this->em->find(ProductReview::class, $id) : null;
        if (!$review instanceof ProductReview || $review->getUser()?->getId() !== $user->getId()) {
            throw HttpException::notFound('Review not found.');
        }

        /** @var ProductReviewRepository $repo */
        $repo = $this->em->getRepository(ProductReview::class);
        $repo->remove($review);

        return $this->noContent();
    }
}
