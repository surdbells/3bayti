<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Product;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/products/{id}
 *
 * A single product owned by the authenticated vendor — ANY status (draft,
 * inactive, out-of-stock), unlike the storefront GET /v3/products/{slug}
 * which 404s on non-active products. Backs the vendor product preview
 * drawer (which previously called GET /v3/products/by-legacy-id/{id} with
 * the v3 id and 404'd for v3-native products).
 *
 * Returns the vendor DETAIL shape (management fields + description, gallery,
 * sizes, colors). Scoped to the vendor's own product; foreign/unknown ids
 * 404.
 */
final class GetVendorOwnProductController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $ownedIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($ownedIds === []) {
            throw HttpException::forbidden('No approved vendor account.');
        }

        $id = (int) ($args['id'] ?? 0);

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);

        // Find the product among the user's owned stores.
        $product = null;
        foreach ($ownedIds as $vendorId) {
            $product = $productRepo->findOneByIdForVendor($id, $vendorId);
            if ($product !== null) {
                break;
            }
        }
        if ($product === null) {
            throw HttpException::notFound('Product not found.');
        }

        return $this->ok(['data' => $this->serializer->vendorDetailShape($product)]);
    }
}
