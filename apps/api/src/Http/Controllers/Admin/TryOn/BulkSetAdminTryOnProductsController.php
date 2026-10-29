<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\TryOn;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\TryOn\TryOnGarmentEligibility;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\IdListParser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/admin/try-on/products/bulk   body: { product_ids: int[], enabled: bool }
 *
 * Turns virtual try-on on/off for up to 200 products at once. product_ids are
 * v3 ids (never legacy ids), 1..200 after de-duplication (repeats collapse);
 * anything else is a 422 keyed on the field.
 *
 * Response: {data: {updated: int[], skipped: [{id, reason}]}}
 *   - updated  ids now in the requested state (in request order), including
 *              ones that already were (no write, no audit row for those)
 *   - skipped  not_found, or, when ENABLING only, the garment-image reason
 *              (no_image | external_image | unsupported_format) from the same
 *              TryOnGarmentEligibility the try-on worker uses. Disabling never
 *              skips for image reasons.
 *
 * One flush for the batch; each changed product is audited by the Doctrine
 * EntityAuditListener like any other admin product write.
 *
 * Authorization: admin-only (group middleware). products.edit.
 */
final class BulkSetAdminTryOnProductsController
{
    use Responder;

    public const MAX_IDS = 200;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly TryOnGarmentEligibility $eligibility,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);

        $ids = IdListParser::fromArray($body['product_ids'] ?? null, 'product_ids');
        if ($ids === []) {
            throw HttpException::validation(['product_ids' => ['Provide at least one product id.']]);
        }
        if (count($ids) > self::MAX_IDS) {
            throw HttpException::validation(['product_ids' => ['At most ' . self::MAX_IDS . ' ids per request.']]);
        }

        $enabled = $body['enabled'] ?? null;
        if (!is_bool($enabled)) {
            throw HttpException::validation(['enabled' => ['Must be true or false.']]);
        }

        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $byId = [];
        foreach ($repo->findByIds($ids) as $product) {
            $byId[(int) $product->getId()] = $product;
        }

        $updated = [];
        $skipped = [];
        $changed = false;
        foreach ($ids as $id) {
            $product = $byId[$id] ?? null;
            if ($product === null) {
                $skipped[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }

            if ($enabled) {
                $reason = $this->eligibility->reasonFor($product);
                if ($reason !== null) {
                    $skipped[] = ['id' => $id, 'reason' => $reason];
                    continue;
                }
            }

            if ($product->isTryOnEnabled() !== $enabled) {
                $product->setTryOnEnabled($enabled);
                $changed = true;
            }
            $updated[] = $id;
        }

        if ($changed) {
            $this->em->flush();
        }

        return $this->ok(['data' => [
            'updated' => $updated,
            'skipped' => $skipped,
        ]]);
    }
}
