<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Finance;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/transactions[?limit&offset&since&until&status]
 * Returns paid/refunded orders as a transaction ledger.
 */
final class ListTransactionsController
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

        $qb = $this->em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where("o.status NOT IN ('pending_payment','draft')")
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!empty($q['since']))  $qb->andWhere('o.createdAt >= :since')->setParameter('since', new \DateTimeImmutable($q['since']));
        if (!empty($q['until']))  $qb->andWhere('o.createdAt <= :until')->setParameter('until', new \DateTimeImmutable($q['until']));
        if (!empty($q['status'])) $qb->andWhere('o.status = :status')->setParameter('status', $q['status']);

        /** @var list<Order> $orders */
        $orders  = $qb->getQuery()->getResult();

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(o.id)')
            ->resetDQLPart('orderBy')
            ->setMaxResults(null)
            ->setFirstResult(0)
            ->getQuery()
            ->getSingleScalarResult();

        $data = array_map(static fn(Order $o) => [
            'id'              => $o->getId(),
            'order_reference' => $o->getOrderReference(),
            'status'          => $o->getStatus(),
            'subtotal'        => $o->getSubtotal(),
            'payment_method'  => null,
            'created_at'      => $o->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $orders);

        return $this->ok(['data' => $data, 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]]);
    }
}
