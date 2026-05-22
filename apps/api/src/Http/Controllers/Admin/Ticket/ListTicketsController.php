<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Ticket;

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
        );

        return $this->ok([
            'data' => array_map([$this, 'shape'], $result['items']),
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    /** @return array<string,mixed> */
    public function shape(Ticket $t): array
    {
        return [
            'id'           => $t->getId(),
            'subject'      => $t->getSubject(),
            'status'       => $t->getStatus(),
            'priority'     => $t->getPriority(),
            'vendor_id'    => $t->getVendorId(),
            'message_count'=> $t->getMessages()->count(),
            'created_at'   => $t->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at'   => $t->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
