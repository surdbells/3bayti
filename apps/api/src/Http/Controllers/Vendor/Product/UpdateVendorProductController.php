<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Product;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Vendor\Product\Dto\VendorProductInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Bayti\Api\Http\Validator\ProductLabelValidator;
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/vendor/products/{id}
 *
 * Update an existing product owned by the authenticated vendor.
 * Only non-null fields in the request body are applied (partial update
 * semantics despite using PUT, matching the portal's usage pattern).
 */
final class UpdateVendorProductController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $serializer,
        private readonly \Bayti\Api\Domain\Media\ImageStorageService $imageStorage,
        private readonly ProductLabelValidator $labels,
        private readonly ProductInputApplier $applier,
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

        $productId = (int) $request->getAttribute('id');

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendorIds  = $vendorRepo->findIdsByOwnerUser($user);
        if (empty($vendorIds)) {
            throw HttpException::forbidden('No approved vendor account found.');
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->find($productId);

        if ($product === null) {
            throw HttpException::notFound('Product not found.');
        }

        // Ownership check, product's vendor must be owned by this user.
        if (!in_array($product->getVendor()->getId(), $vendorIds, true)) {
            throw HttpException::forbidden('You do not own this product.');
        }

        $input = $this->validator->parse($request, VendorProductInput::class);

        // label_id must be an active label of THIS product's store (422
        // otherwise; re-sending the current label is a no-op). Validated before
        // ANY mutation, incl. the orphaned-image cleanup below, so a rejected
        // label changes nothing.
        $labelId = $this->labels->resolve($input->label_id, $product->getVendor(), $product->getLabelId());

        /** @var CategoryRepository $catRepo */
        $catRepo  = $this->em->getRepository(Category::class);
        $category = null;
        if ($input->category_id !== null) {
            $category = $catRepo->find($input->category_id);
        }

        // Snapshot the product's image URLs before mutation so we can
        // delete any files orphaned by this update (bug 2: replaced images
        // must be removed from storage). Only files we host are deleted;
        // external/legacy URLs are left untouched.
        $oldImageUrls = $this->collectImageUrls($product);

        // Shared with vendor create + admin create/update (ProductInputApplier).
        $this->applier->apply(
            $product,
            $input,
            $category,
            $labelId,
            salePricePresent: array_key_exists('sale_price', (array) ($request->getParsedBody() ?? [])),
        );
        $productRepo->save($product);

        $this->deleteOrphanedImages($oldImageUrls, $this->collectImageUrls($product));

        return $this->ok(PaginatedEnvelope::single(
            $this->serializer->detailShape($product),
        ));
    }

    /** @return list<string> */
    private function collectImageUrls(Product $product): array
    {
        $urls = $product->getImages();
        $primary = $product->getPrimaryImageUrl();
        if ($primary !== null && $primary !== '') {
            $urls[] = $primary;
        }
        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * @param list<string> $oldUrls
     * @param list<string> $newUrls
     */
    private function deleteOrphanedImages(array $oldUrls, array $newUrls): void
    {
        foreach (array_diff($oldUrls, $newUrls) as $url) {
            $path = \Bayti\Api\Domain\Media\ImageStorageService::storagePathFromUrl($url);
            if ($path !== null) {
                $this->imageStorage->delete($path);
            }
        }
    }
}
