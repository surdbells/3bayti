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
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * DELETE /v3/vendor/products/{id}
 *
 * Deletes a product owned by the authenticated vendor. A product that has
 * ever sold is SOFT-deleted (status='soft_deleted') so historical order/return
 * data referencing it stays intact; a product with no sales is FULLY removed
 * (its transient cart rows are cleared first, and CASCADE/SET NULL foreign keys
 * handle wishlist, collections, reviews, etc.).
 */
final class DeleteVendorProductController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
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
        $product     = $productRepo->find($productId);

        if ($product === null) {
            // Idempotent: already gone is success.
            return $this->noContent();
        }

        if (!in_array($product->getVendor()->getId(), $vendorIds, true)) {
            throw HttpException::forbidden('You do not own this product.');
        }

        $conn = $this->em->getConnection();

        // Real sales history → keep the row (order/return records reference it;
        // order_items.product_id RESTRICTs deletion). Never sold → remove fully.
        $soldCount = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM order_items WHERE product_id = :pid',
            ['pid' => $productId],
        );

        if ($soldCount > 0) {
            $product->softDelete();
            $productRepo->save($product);
            return $this->noContent();
        }

        // Never sold — hard delete. Clear the transient cart rows first (a
        // deleted product can't be checked out anyway); CASCADE / SET NULL FKs
        // handle the rest. Raw DBAL in a transaction, so an unexpected reference
        // throws without closing the EntityManager and we fall back to a soft
        // delete instead of 500-ing.
        try {
            $conn->transactional(function ($c) use ($productId): void {
                $c->executeStatement('DELETE FROM cart_items WHERE product_id = :pid', ['pid' => $productId]);
                $c->executeStatement('DELETE FROM products WHERE id = :pid', ['pid' => $productId]);
            });
            $this->em->detach($product);
        } catch (\Throwable $e) {
            $this->logger->warning('vendor product hard-delete fell back to soft-delete', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
            ]);
            $product->softDelete();
            $productRepo->save($product);
        }

        return $this->noContent();
    }
}
