<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Catalog\VendorSizeChart;
use Bayti\Api\Domain\Catalog\VendorSizeChartRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Vendor size-chart CRUD, the store's published size guide
 * (one entry per size, with garment dimensions).
 *
 *   GET    /v3/vendor/measurements        list
 *   POST   /v3/vendor/measurements        create
 *   PUT    /v3/vendor/measurements/{id}   update
 *   DELETE /v3/vendor/measurements/{id}   delete
 *
 * Named "measurements" for portal/legacy continuity, but this is the
 * vendor's size chart (VendorSizeChart), NOT a customer's body
 * measurements (/v3/me/measurements). Scoped to the authenticated
 * vendor via VendorAuthMiddleware + owner resolution.
 *
 * Request/response value shape mirrors the portal:
 *   { "size": "M", "values": { "bust": 92, "waist": 76, ... } }
 */
final class VendorSizeChartController
{
    use Responder;

    /** Recognised dimension keys (legacy parity). Extra keys are kept too. */
    private const DIMENSION_KEYS = ['bust', 'waist', 'hip', 'length', 'neck', 'arm', 'armhole', 'shoulder'];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $vendor = $this->resolveVendor($request);
        /** @var VendorSizeChartRepository $repo */
        $repo = $this->em->getRepository(VendorSizeChart::class);
        $rows = $repo->findForVendor((int) $vendor->getId());

        return $this->ok([
            'data' => array_map([$this, 'shape'], $rows),
            'meta' => ['total' => count($rows)],
        ]);
    }

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $vendor = $this->resolveVendor($request);
        $body = (array) ($request->getParsedBody() ?? []);

        [$size, $values] = $this->parseInput($body);

        /** @var VendorSizeChartRepository $repo */
        $repo = $this->em->getRepository(VendorSizeChart::class);
        if ($repo->sizeExistsForVendor((int) $vendor->getId(), $size)) {
            throw HttpException::conflict(ErrorCodes::CONFLICT_DUPLICATE, "A measurement for size '{$size}' already exists for your store.");
        }

        $chart = new VendorSizeChart($vendor, $size, $values);
        $this->em->persist($chart);
        $this->em->flush();

        return $this->created(['data' => $this->shape($chart)]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vendor = $this->resolveVendor($request);
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);

        /** @var VendorSizeChartRepository $repo */
        $repo = $this->em->getRepository(VendorSizeChart::class);
        $chart = $repo->findOneForVendor($id, (int) $vendor->getId());
        if ($chart === null) {
            throw HttpException::notFound('Measurement not found.');
        }

        [$size, $values] = $this->parseInput($body);
        if ($repo->sizeExistsForVendor((int) $vendor->getId(), $size, $id)) {
            throw HttpException::conflict(ErrorCodes::CONFLICT_DUPLICATE, "A measurement for size '{$size}' already exists for your store.");
        }

        $chart->setSize($size);
        $chart->setValues($values);
        $this->em->flush();

        return $this->ok(['data' => $this->shape($chart)]);
    }

    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $vendor = $this->resolveVendor($request);
        $id = (int) ($args['id'] ?? 0);

        /** @var VendorSizeChartRepository $repo */
        $repo = $this->em->getRepository(VendorSizeChart::class);
        $chart = $repo->findOneForVendor($id, (int) $vendor->getId());
        if ($chart === null) {
            throw HttpException::notFound('Measurement not found.');
        }
        $this->em->remove($chart);
        $this->em->flush();

        return $this->ok(['data' => ['id' => $id, 'deleted' => true]]);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array{0:string, 1:array<string,float>}
     */
    private function parseInput(array $body): array
    {
        // Accept either {size, values:{...}} or a flat {size, bust, waist...}.
        $values = isset($body['values']) && is_array($body['values']) ? $body['values'] : $body;
        $size = trim((string) ($values['size'] ?? $body['size'] ?? ''));
        if ($size === '') {
            throw HttpException::badRequest('Size is required.');
        }

        $clean = [];
        foreach (self::DIMENSION_KEYS as $k) {
            if (isset($values[$k]) && is_numeric($values[$k])) {
                $clean[$k] = (float) $values[$k];
            }
        }
        // Preserve any extra numeric dimensions the vendor provides.
        foreach ($values as $k => $v) {
            if ($k === 'size' || isset($clean[$k]) || !is_numeric($v)) {
                continue;
            }
            $clean[(string) $k] = (float) $v;
        }

        return [$size, $clean];
    }

    private function resolveVendor(ServerRequestInterface $request): Vendor
    {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }
        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $ownedIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($ownedIds === []) {
            throw HttpException::forbidden('No approved vendor account.');
        }
        // Optional ?vendor_id for multi-store owners; default first owned.
        $vendorId = $ownedIds[0];
        $q = $request->getQueryParams();
        if (!empty($q['vendor_id'])) {
            $requested = (int) $q['vendor_id'];
            if (!in_array($requested, $ownedIds, true)) {
                throw HttpException::notFound('Vendor not found.');
            }
            $vendorId = $requested;
        }
        $vendor = $vendorRepo->find($vendorId);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }
        return $vendor;
    }

    /** @return array<string, mixed> */
    private function shape(VendorSizeChart $c): array
    {
        return self::shapeRow($c);
    }

    /**
     * Canonical size-chart row shape, shared with the public customer-facing
     * endpoint (StoreSizeChartByLegacyIdController) so shoppers and vendors
     * see the same row structure: { id, size, values:{...}, created_at,
     * updated_at }. Extracted as a static so the public controller can reuse
     * it without depending on auth/vendor-resolution state.
     *
     * @return array<string, mixed>
     */
    public static function shapeRow(VendorSizeChart $c): array
    {
        return [
            'id'         => $c->getId(),
            'size'       => $c->getSize(),
            'values'     => $c->getValues(),
            'created_at' => $c->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $c->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
