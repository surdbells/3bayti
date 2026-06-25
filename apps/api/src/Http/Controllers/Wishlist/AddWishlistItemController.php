<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\Wishlist\Wishlist;
use Bayti\Api\Domain\Wishlist\WishlistLabel;
use Bayti\Api\Domain\Wishlist\WishlistLabelRepository;
use Bayti\Api\Domain\Wishlist\WishlistRepository;
use Bayti\Api\Http\Controllers\Wishlist\Dto\AddWishlistItemInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/me/wishlist  body { product_id } — save a product.
 *
 * Idempotent (Q6.3): if the product is already saved, this is a no-op
 * success — NOT a 409. New save → 201; already-saved → 200. Both
 * return the saved product in the same { data: <product listShape> }
 * shape, so the client can update its UI uniformly.
 *
 * The product must exist and be active (404 PRODUCT_NOT_FOUND
 * otherwise) — you can't wishlist a delisted/nonexistent product.
 */
final class AddWishlistItemController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $serializer,
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

        $input = $this->validator->parse($request, AddWishlistItemInput::class);

        // Mobile cards carry the LEGACY product id (legacy_product_id ??
        // v3 id), so resolve in that same precedence — a straight
        // EntityManager::find by v3 PK 404s every migrated product.
        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->findByIdOrLegacyId($input->product_id);
        if (!$product instanceof Product || !$product->isActive()) {
            throw HttpException::notFound('Product not found.');
        }

        // Optional label (Q-Z3=B): must belong to the user.
        $label = null;
        if ($input->label_id !== null) {
            /** @var WishlistLabelRepository $labelRepo */
            $labelRepo = $this->em->getRepository(WishlistLabel::class);
            $label = $labelRepo->findOneForUser($user, $input->label_id);
            if ($label === null) {
                throw HttpException::notFound('Label not found.');
            }
        }

        /** @var WishlistRepository $wishlistRepo */
        $wishlistRepo = $this->em->getRepository(Wishlist::class);

        // Idempotent: if it's already saved, no-op (but (re)assign the
        // label when one was supplied) and return 200.
        $existing = $wishlistRepo->findOneForUserAndProduct($user, $product);
        if ($existing !== null) {
            if ($input->label_id !== null) {
                $existing->setLabel($label);
                $wishlistRepo->save($existing);
            }
            return $this->ok(PaginatedEnvelope::single(
                $this->serializer->configureFromRequest($request)->listShape($product),
            ));
        }

        $entry = new Wishlist($user, $product);
        if ($label !== null) {
            $entry->setLabel($label);
        }
        $wishlistRepo->save($entry);

        return $this->created(PaginatedEnvelope::single(
            $this->serializer->configureFromRequest($request)->listShape($product),
        ));
    }
}
