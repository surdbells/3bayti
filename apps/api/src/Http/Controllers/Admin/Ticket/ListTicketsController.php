<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Ticket;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Support\Ticket;
use Bayti\Api\Domain\Support\TicketRepository;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /v3/admin/tickets[?status=&priority=&vendor_id=&limit=&offset=] */
final class ListTicketsController
{
    use Responder;
    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
    ) {}
    protected function getResponseFactory(): ResponseFactoryInterface { return $this->responseFactory; }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $q      = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($q['limit']  ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        /** @var TicketRepository $repo */
        $repo   = $this->em->getRepository(Ticket::class);
        $result = $repo->findPaginated(
            limit:    $limit,
            offset:   $offset,
            status:   $q['status']    ?? null,
            priority: $q['priority']  ?? null,
            vendorId: isset($q['vendor_id']) ? (int) $q['vendor_id'] : null,
            search:   isset($q['search']) ? (string) $q['search'] : null,
        );

        $vendorNames = $this->vendorNames($result['items']);

        return $this->ok([
            'data' => array_map(
                fn (Ticket $t): array => $this->shape($t, $vendorNames),
                $result['items'],
            ),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    /**
     * Batch-resolve vendor display names for the tickets on this page so
     * shape() can attach a human store name without an N+1 lookup.
     *
     * @param  list<Ticket>      $tickets
     * @return array<int,string> vendorId => name
     */
    private function vendorNames(array $tickets): array
    {
        $ids = [];
        foreach ($tickets as $t) {
            $vid = $t->getVendorId();
            if ($vid !== null) {
                $ids[$vid] = $vid;
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('v.id AS id', 'v.name AS name')
            ->from(Vendor::class, 'v')
            ->where('v.id IN (:ids)')
            ->setParameter('ids', array_values($ids))
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['id']] = (string) $row['name'];
        }
        return $names;
    }

    /**
     * @param  array<int,string> $vendorNames
     * @return array<string,mixed>
     */
    public function shape(Ticket $t, array $vendorNames = []): array
    {
        $vid = $t->getVendorId();
        return [
            'id'           => $t->getId(),
            'ticket_ref'   => 'TKT-' . $t->getId(),
            'subject'      => $t->getSubject(),
            'status'       => $t->getStatus(),
            'priority'     => $t->getPriority(),
            'vendor_id'    => $vid,
            'vendor_name'  => $vid !== null ? ($vendorNames[$vid] ?? null) : null,
            'message_count'=> $t->getMessages()->count(),
            'created_at'   => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'   => $t->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
