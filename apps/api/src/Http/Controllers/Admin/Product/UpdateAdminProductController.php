<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Controllers\Vendor\Product\Dto\VendorProductInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
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
 * PUT /v3/admin/products/{id}
 * Optional label_id must be an active label of the product's store (see ProductLabelValidator).
 */
final class UpdateAdminProductController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $serializer,
        private readonly ProductLabelValidator $labels,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id');
        /** @var ProductRepository $repo */
        $repo    = $this->em->getRepository(Product::class);
        $product = $repo->find($id);
        if ($product === null) throw HttpException::notFound('Product not found.');

        $input = $this->validator->parse($request, VendorProductInput::class);
        $body  = (array) ($request->getParsedBody() ?? []);

        // The store label must be an active label of the PRODUCT's own store
        // (a product's store can't be reassigned on edit). Validated before any
        // field is touched so a 422 leaves the product unchanged.
        $labelId = $this->labels->resolve($input->label_id, $product->getVendor(), $product->getLabelId());

        /** @var CategoryRepository $cRepo */
        $cRepo = $this->em->getRepository(Category::class);
        $cat   = $input->category_id !== null ? $cRepo->find($input->category_id) : null;

        if ($input->name !== null)            $product->setName($input->name);
        if ($input->description !== null)     $product->setDescription($input->description);
        if ($input->price !== null)           $product->setPrice(number_format((float) $input->price, 2, '.', ''));
        // Only touch sale_price when the key is present (a partial update that
        // omits it preserves the stored discount; an explicit null clears it).
        if (array_key_exists('sale_price', $body)) {
            $product->setSalePrice($input->sale_price !== null ? number_format((float) $input->sale_price, 2, '.', '') : null);
        }
        if ($input->status !== null)          $product->setStatus($input->status);
        if ($input->primary_image_url !== null) $product->setPrimaryImageUrl($input->primary_image_url);
        if ($input->image_urls !== null)      $product->setImages($input->image_urls);
        if ($input->sizes !== null)           $product->setAvailableSizes($input->sizes);
        if ($input->colors !== null)          $product->setAvailableColors($input->colors);
        if ($cat !== null)                    $product->setCategory($cat);
        if ($input->delivery_info !== null)   $product->setDeliveryInfo($input->normalizedDeliveryInfo());
        // Absent/null label_id (or the unchanged current one) keeps the stored label.
        if ($labelId !== null)                $product->setLabelId($labelId);

        $repo->save($product);
        return $this->ok(PaginatedEnvelope::single($this->serializer->detailShape($product)));
    }
}
