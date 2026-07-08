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
use Bayti\Api\Http\Validator\RequestValidator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/vendor/products
 *
 * Create a new product for the authenticated vendor. The product is
 * created with status=draft so it does not appear in the public
 * catalog until the vendor explicitly activates it.
 *
 * Requires VendorAuthMiddleware (vendor must be approved).
 */
final class CreateVendorProductController
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
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors = $vendorRepo->findByOwnerUser($user);
        if (empty($vendors)) {
            throw HttpException::forbidden('No approved vendor account found.');
        }
        $vendor = $vendors[0];

        $input = $this->validator->parse($request, VendorProductInput::class);

        /** @var CategoryRepository $catRepo */
        $catRepo = $this->em->getRepository(Category::class);
        $category = null;
        if ($input->category_id !== null) {
            $category = $catRepo->find($input->category_id);
        }

        $slug = $this->generateSlug($input->name ?? '', $user->getId() ?? 0);

        $product = new Product(
            vendor: $vendor,
            slug: $slug,
            name: $input->name ?? '',
        );

        $this->applyInput($product, $input, $category);

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $productRepo->save($product);

        return $this->created(PaginatedEnvelope::single(
            $this->serializer->detailShape($product),
        ));
    }

    private function applyInput(Product $product, VendorProductInput $input, ?Category $category): void
    {
        if ($input->name !== null) {
            $product->setName($input->name);
        }
        if ($input->description !== null) {
            $product->setDescription($input->description);
        }
        if ($input->price !== null) {
            $product->setPrice(number_format((float) $input->price, 2, '.', ''));
        }
        // Always apply sale_price so a blank value clears any existing discount
        // (the portal form always sends the key; null = not on sale).
        $product->setSalePrice(
            $input->sale_price !== null
                ? number_format((float) $input->sale_price, 2, '.', '')
                : null,
        );
        if ($input->cost_per_item !== null) {
            $product->setCostPerItem(number_format((float) $input->cost_per_item, 2, '.', ''));
        }
        if ($input->stock_quantity !== null) {
            $product->setStockQuantity($input->stock_quantity);
        }
        if ($input->stock_status !== null) {
            $product->setStockStatus($input->stock_status);
        }
        if ($input->allow_oversell !== null) {
            $product->setAllowOversell($input->allow_oversell);
        }
        if ($input->min_order_qty !== null) {
            $product->setMinOrderQty($input->min_order_qty);
        }
        if ($input->max_order_qty !== null) {
            $product->setMaxOrderQty($input->max_order_qty);
        }
        if ($input->primary_image_url !== null) {
            $product->setPrimaryImageUrl($input->primary_image_url);
        }
        if ($input->image_urls !== null) {
            $product->setImages($input->image_urls);
        }
        if ($input->sizes !== null) {
            $product->setAvailableSizes($input->sizes);
        }
        if ($input->colors !== null) {
            $product->setAvailableColors($input->colors);
        }
        if ($input->is_featured !== null) {
            $product->setIsFeatured($input->is_featured);
        }
        if ($input->is_new !== null) {
            $product->setIsNew($input->is_new);
        }
        if ($input->is_hot !== null) {
            $product->setIsHot($input->is_hot);
        }
        if ($input->is_sale !== null) {
            $product->setIsSale($input->is_sale);
        }
        if ($input->requires_extra_msmt !== null) {
            $product->setRequiresExtraMsmt($input->requires_extra_msmt);
        }
        if ($input->extra_msmt !== null) {
            $product->setExtraMsmt($input->extra_msmt);
        }
        if ($input->status !== null) {
            $product->setStatus($input->status);
        }
        if ($input->collection_id !== null) {
            $product->setCollectionId($input->collection_id);
        }
        if ($input->label_id !== null) {
            $product->setLabelId($input->label_id);
        }
        if ($category !== null) {
            $product->setCategory($category);
        }
    }

    private function generateSlug(string $name, int $userId): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        $base = $base !== '' ? $base : 'product';
        return $base . '-' . $userId . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
