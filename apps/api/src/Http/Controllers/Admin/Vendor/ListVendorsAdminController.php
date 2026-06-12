<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/vendors[?search=&limit=&offset=]
 *
 * Lists vendors (active + inactive) with admin shape. Supports
 * case-insensitive search across name, slug, and contact email, plus
 * limit/offset pagination. Response includes a meta block with the
 * total count so the portal can paginate.
 */
final class ListVendorsAdminController
{
    use Responder;

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $search = isset($q['search']) ? trim((string) $q['search']) : '';
        $limit  = $this->clampLimit($q['limit'] ?? null);
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $qb = $this->em->getRepository(Vendor::class)
            ->createQueryBuilder('v');

        if ($search !== '') {
            $qb->andWhere(
                'LOWER(v.name) LIKE :s OR LOWER(v.slug) LIKE :s OR LOWER(v.contactEmail) LIKE :s'
            )->setParameter('s', '%' . mb_strtolower($search) . '%');
        }

        // Total (count) — reset orderBy on the clone to keep PostgreSQL happy.
        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(v.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $vendors = $qb->orderBy('v.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->ok([
            'vendors' => $this->serializer->adminShapeMany($vendors),
            'meta'    => [
                'total'  => $total,
                'limit'  => $limit,
                'offset' => $offset,
            ],
        ]);
    }

    private function clampLimit(mixed $raw): int
    {
        $limit = (int) ($raw ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        return min($limit, self::MAX_LIMIT);
    }
}
