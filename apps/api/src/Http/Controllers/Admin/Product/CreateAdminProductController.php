<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Vendor\Product\Dto\VendorProductInput;
use Bayti\Api\Http\Controllers\Vendor\Product\ProductInputApplier;
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
 * POST /v3/admin/products, Create a product on behalf of a vendor. Requires vendor_id in body.
 * Optional label_id must be an active label of that vendor (see ProductLabelValidator).
 * Every other VendorProductInput field is applied exactly as the vendor create
 * does (shared ProductInputApplier), except collection_id (admin-curated separately).
 */
final class CreateAdminProductController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $serializer,
        private readonly ProductLabelValidator $labels,
        private readonly ProductInputApplier $applier,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input    = $this->validator->parse($request, VendorProductInput::class);
        $body     = (array) ($request->getParsedBody() ?? []);
        $vendorId = (int) ($body['vendor_id'] ?? 0);

        // A new product MUST have a real, positive price. The DTO's Positive
        // constraint rejects a supplied 0/negative, but price is nullable (shared
        // with partial-update); an omitted price would fall through to the
        // entity's 0.00 default, so require it here on create (same as the vendor
        // create path).
        if ($input->price === null || (float) $input->price <= 0.0) {
            throw HttpException::validation(['price' => ['Price is required and must be greater than zero.']]);
        }

        /** @var VendorRepository $vRepo */
        $vRepo  = $this->em->getRepository(Vendor::class);
        $vendor = $vRepo->find($vendorId);
        if ($vendor === null) throw HttpException::badRequest('vendor_id is required and must reference a valid vendor.');

        // The store label must be one of the TARGET store's active labels (422
        // otherwise); validated before anything is built so nothing persists.
        $labelId = $this->labels->resolve($input->label_id, $vendor);

        /** @var CategoryRepository $cRepo */
        $cRepo    = $this->em->getRepository(Category::class);
        $category = $input->category_id !== null ? $cRepo->find($input->category_id) : null;

        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $input->name ?? '') ?? ''));
        $slug = ($slug !== '' ? $slug : 'product') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $product = new Product(vendor: $vendor, slug: $slug, name: $input->name ?? '');
        // Same field application as the vendor create (ProductInputApplier):
        // price (guaranteed non-null & > 0 above), sale_price (always, a new
        // product takes the sent value or none), stock + oversell, order limits,
        // cost, merchandising flags, made-to-measure, media, delivery, status,
        // label, category. collection_id is NOT applied: admin storefront
        // collections are curated separately.
        $this->applier->apply($product, $input, $category, $labelId, salePricePresent: true, applyCollection: false);

        /** @var ProductRepository $pRepo */
        $pRepo = $this->em->getRepository(Product::class);
        $pRepo->save($product);

        return $this->created(PaginatedEnvelope::single($this->serializer->detailShape($product)));
    }
}
