<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
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

/** POST /v3/admin/products — Create a product on behalf of a vendor. Requires vendor_id in body. */
final class CreateAdminProductController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $serializer,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input    = $this->validator->parse($request, VendorProductInput::class);
        $body     = (array) ($request->getParsedBody() ?? []);
        $vendorId = (int) ($body['vendor_id'] ?? 0);

        /** @var VendorRepository $vRepo */
        $vRepo  = $this->em->getRepository(Vendor::class);
        $vendor = $vRepo->find($vendorId);
        if ($vendor === null) throw HttpException::badRequest('vendor_id is required and must reference a valid vendor.');

        /** @var CategoryRepository $cRepo */
        $cRepo    = $this->em->getRepository(Category::class);
        $category = $input->category_id !== null ? $cRepo->find($input->category_id) : null;

        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $input->name ?? '') ?? ''));
        $slug = ($slug !== '' ? $slug : 'product') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $product = new Product(vendor: $vendor, slug: $slug, name: $input->name ?? '');
        if ($input->price !== null)        $product->setPrice(number_format((float) $input->price, 2, '.', ''));
        $product->setSalePrice($input->sale_price !== null ? number_format((float) $input->sale_price, 2, '.', '') : null);
        if ($input->description !== null)  $product->setDescription($input->description);
        if ($input->status !== null)       $product->setStatus($input->status);
        if ($input->primary_image_url !== null) $product->setPrimaryImageUrl($input->primary_image_url);
        if ($input->image_urls !== null)   $product->setImages($input->image_urls);
        if ($input->sizes !== null)        $product->setAvailableSizes($input->sizes);
        if ($input->colors !== null)       $product->setAvailableColors($input->colors);
        if ($category !== null)            $product->setCategory($category);

        /** @var ProductRepository $pRepo */
        $pRepo = $this->em->getRepository(Product::class);
        $pRepo->save($product);

        return $this->created(PaginatedEnvelope::single($this->serializer->detailShape($product)));
    }
}
