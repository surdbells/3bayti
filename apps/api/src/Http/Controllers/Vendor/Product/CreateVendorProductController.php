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

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendors = $vendorRepo->findByOwnerUser($user);
        if (empty($vendors)) {
            throw HttpException::forbidden('No approved vendor account found.');
        }
        $vendor = $vendors[0];

        $input = $this->validator->parse($request, VendorProductInput::class);

        // label_id must be an active label of THIS vendor's store (422
        // otherwise) — validated before anything is built so nothing persists.
        $labelId = $this->labels->resolve($input->label_id, $vendor);

        // A new product MUST have a real, positive price. The DTO's Positive
        // constraint rejects a supplied 0/negative, but the price field is
        // nullable (shared with partial-update); an omitted price would otherwise
        // fall through to the entity's 0.00 default, so require it here on create.
        if ($input->price === null || (float) $input->price <= 0.0) {
            throw HttpException::validation(['price' => ['Price is required and must be greater than zero.']]);
        }

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

        // Shared with vendor update + admin create/update (ProductInputApplier).
        // A new product always takes the sent sale_price (or none).
        $this->applier->apply($product, $input, $category, $labelId, salePricePresent: true);

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $productRepo->save($product);

        return $this->created(PaginatedEnvelope::single(
            $this->serializer->detailShape($product),
        ));
    }

    private function generateSlug(string $name, int $userId): string
    {
        $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '', '-'));
        $base = $base !== '' ? $base : 'product';
        return $base . '-' . $userId . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
