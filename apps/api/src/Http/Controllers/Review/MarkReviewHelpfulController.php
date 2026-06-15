<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Review;

use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\ProductReviewRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/reviews/{id}/helpful — mark an approved review as helpful
 * (authenticated). Only approved reviews can be voted on (you can only
 * see approved ones publicly). Returns the new helpful count.
 *
 * Note: dedup is not enforced per-user (no votes table) — this mirrors
 * the legacy behaviour. If abuse becomes an issue, add a
 * review_helpful_votes(user_id, review_id) unique table and gate here.
 */
final class MarkReviewHelpfulController
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
        if (!$review instanceof ProductReview || $review->getStatus() !== ProductReview::STATUS_APPROVED) {
            throw HttpException::notFound('Review not found.');
        }

        $review->incrementHelpful();

        /** @var ProductReviewRepository $repo */
        $repo = $this->em->getRepository(ProductReview::class);
        $repo->save($review);

        return $this->ok(PaginatedEnvelope::single([
            'id'            => $review->getId(),
            'helpful_count' => $review->getHelpfulCount(),
        ]));
    }
}
