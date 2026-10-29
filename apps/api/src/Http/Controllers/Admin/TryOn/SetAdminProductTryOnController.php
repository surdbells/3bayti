<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\TryOn;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\TryOn\TryOnGarmentEligibility;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\AdminTryOnProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v3/admin/products/{id}/try-on   body: { enabled: bool }
 *
 * Turns virtual try-on on/off for one product ({id} = v3 product id, never a
 * legacy id). Works while TRYON_ENABLED is still off, so admins can prepare
 * products before go-live (customers only see "Try it on" once the provider
 * is live, see ProductSerializer::isTryOnActive).
 *
 *   - enabling a product whose garment (primary) image the worker cannot use
 *     → 422 TRYON_IMAGE_INELIGIBLE, details.reason = no_image |
 *       external_image | unsupported_format (TryOnGarmentEligibility)
 *   - disabling is always allowed
 *   - unknown id → 404
 *
 * Returns {data: <AdminTryOnProductSerializer row>}. The change is audited by
 * the Doctrine EntityAuditListener like every other admin product write
 * (Product 'updated', tryOnEnabled before/after); a no-op write records nothing.
 *
 * Authorization: admin-only (group middleware). products.edit.
 */
final class SetAdminProductTryOnController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly TryOnGarmentEligibility $eligibility,
        private readonly AdminTryOnProductSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            throw HttpException::notFound('Product not found.');
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $enabled = $body['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw HttpException::validation(['enabled' => ['Must be true or false.']]);
        }

        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $product = $repo->find($id);
        // Soft-deleted products are hidden from the try-on list; treat them
        // as not found here too.
        if (!$product instanceof Product || $product->getStatus() === Product::STATUS_SOFT_DELETED) {
            throw HttpException::notFound('Product not found.');
        }

        if ($enabled) {
            $reason = $this->eligibility->reasonFor($product);
            if ($reason !== null) {
                throw new HttpException(
                    422,
                    ErrorCodes::TRYON_IMAGE_INELIGIBLE,
                    TryOnGarmentEligibility::reasonMessage($reason),
                    ['reason' => $reason],
                );
            }
        }

        if ($product->isTryOnEnabled() !== $enabled) {
            $product->setTryOnEnabled($enabled);
            $repo->save($product);
        }

        return $this->ok(PaginatedEnvelope::single($this->serializer->shape($product)));
    }
}
