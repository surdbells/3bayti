<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
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
 * GET /v3/admin/products/{id}
 *
 * A single product by v3 id, in ANY status (draft, active, out-of-stock) —
 * for the admin edit form. The admin editor previously loaded via the public
 * GET /v3/products/{slug}, which 404s on drafts, leaving the form blank. Any
 * vendor's product is editable by admin, so there is no ownership scope
 * (unlike the vendor GetVendorOwnProductController). Returns the same
 * vendorDetailShape the product form already consumes.
 *
 * Authorization: admin-only (group middleware). products.view.
 */
final class GetAdminProductController
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

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::notFound('Product not found.');
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $product = $productRepo->find($id);
        if ($product === null) {
            throw HttpException::notFound('Product not found.');
        }

        return $this->ok(['data' => $this->serializer->vendorDetailShape($product)]);
    }
}
