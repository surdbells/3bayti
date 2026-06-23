<?php declare(strict_types=1);
namespace Bayti\Api\Http\Controllers\Admin\Finance;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/commissions[?limit&offset&since&until]
 * Returns delivered orders with commission breakdowns.
 */
final class ListCommissionsController
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
            ->where("o.status IN ('delivered','refunded')")
            // Defence in depth: keep synthetic gift-card purchase orders
            // (linked via gift_cards.purchase_order_reference) out of the
            // commission report. The delivered/refunded scope already
            // excludes them in practice, but the predicate makes it explicit.
            ->andWhere(
                'NOT EXISTS ('
                . 'SELECT 1 FROM \\Bayti\\Api\\Domain\\GiftCard\\GiftCard gc '
                . 'WHERE gc.purchaseOrderReference = o.orderReference'
                . ')'
            )
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!empty($q['since'])) $qb->andWhere('o.createdAt >= :since')->setParameter('since', new \DateTimeImmutable($q['since']));
        if (!empty($q['until'])) $qb->andWhere('o.createdAt <= :until')->setParameter('until', new \DateTimeImmutable($q['until']));

        /** @var list<Order> $orders */
        $orders = $qb->getQuery()->getResult();

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(o.id)')->resetDQLPart('orderBy')->setMaxResults(null)->setFirstResult(0)->getQuery()->getSingleScalarResult();

        $data = array_map(static function (Order $o): array {
            // Commission is computed per vendor-line: each order item carries
            // its own vendor (frozen at checkout) and its own subtotal. We sum
            // each line's commission using that vendor's rate, then derive the
            // order-level figures the table shows (one row per order, blended
            // across however many vendors contributed items to the order).
            //
            // All money math uses bcmath at 2dp to avoid float drift.
            $commission = '0.00';
            $vendorPay  = '0.00';
            $rateSum    = '0.00'; // subtotal-weighted, for a blended display rate
            $lineBase   = '0.00';

            foreach ($o->getItems() as $item) {
                $lineSubtotal = $item->getSubtotal();
                $rate         = number_format($item->getVendor()->getCommissionRate(), 2, '.', '');

                // line commission = subtotal * rate / 100, rounded to 2dp.
                $lineCommission = bcdiv(bcmul($lineSubtotal, $rate, 4), '100', 2);
                $linePay        = bcsub($lineSubtotal, $lineCommission, 2);

                $commission = bcadd($commission, $lineCommission, 2);
                $vendorPay  = bcadd($vendorPay, $linePay, 2);

                // Weight each rate by its line subtotal so the blended rate
                // reflects the money mix, not a naive average of vendor rates.
                $rateSum  = bcadd($rateSum, bcmul($lineSubtotal, $rate, 4), 4);
                $lineBase = bcadd($lineBase, $lineSubtotal, 2);
            }

            // Blended commission rate across the order's vendor-lines. When the
            // order has no items (defensive) or zero subtotal, fall back to null.
            $blendedRate = bccomp($lineBase, '0.00', 2) > 0
                ? number_format((float) bcdiv($rateSum, $lineBase, 4), 2, '.', '')
                : null;

            return [
                'id'              => $o->getId(),
                'order_reference' => $o->getOrderReference(),
                'status'          => $o->getStatus(),
                'subtotal'        => $o->getSubtotal(),
                'commission_rate' => $blendedRate,
                'commission_aed'  => $commission,
                'vendor_pay_aed'  => $vendorPay,
                'created_at'      => $o->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }, $orders);

        return $this->ok(['data' => $data, 'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset]]);
    }
}
