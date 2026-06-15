<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Review;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\ProductReviewRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReviewSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/products/{productId}/reviews — leave a review (authenticated).
 *
 * Upsert (one review per user+product): re-reviewing edits the existing
 * row and resets it to PENDING re-moderation, rather than spawning
 * duplicates. New review → 201; edited → 200. Always lands as PENDING —
 * a moderator approves before it shows publicly. The vendor + a product-
 * name snapshot are captured so the review survives product changes.
 */
final class CreateReviewController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReviewSerializer $serializer,
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

        $productId = (int) ($args['productId'] ?? 0);
        $product   = $productId > 0 ? $this->em->find(Product::class, $productId) : null;
        if (!$product instanceof Product || !$product->isActive()) {
            throw HttpException::notFound('Product not found.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $star = (int) ($body['star'] ?? $body['rating'] ?? 0);
        if ($star < 1 || $star > 5) {
            throw HttpException::badRequest('star must be between 1 and 5.');
        }
        $title   = isset($body['title']) ? trim((string) $body['title']) : null;
        $comment = trim((string) ($body['comment'] ?? $body['review'] ?? $body['message'] ?? ''));

        /** @var ProductReviewRepository $repo */
        $repo     = $this->em->getRepository(ProductReview::class);
        $existing = $repo->findOneForUserAndProduct($user, $product);

        $review = $existing ?? new ProductReview((string) $star);
        if ($existing !== null) {
            $review->setStar((string) $star);
        }
        $review->setProduct($product);
        $review->setVendor($product->getVendor());
        $review->setUser($user);
        $review->setProductNameSnapshot($product->getName());
        $review->setReviewerName($this->reviewerName($user));
        $review->setTitle($title !== '' ? $title : null);
        $review->setComment($comment !== '' ? $comment : null);
        $review->setStatus(ProductReview::STATUS_PENDING);

        $repo->save($review);

        $shape = PaginatedEnvelope::single($this->serializer->mineShape($review));

        return $existing !== null ? $this->ok($shape) : $this->created($shape);
    }

    private function reviewerName(User $user): ?string
    {
        $name = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));

        return $name !== '' ? $name : null;
    }
}
