<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Shipping;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderShipment;
use Bayti\Api\Domain\Order\OrderShipmentRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Shipping\Oto\OtoWebhookVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/shipping/webhook/oto
 *
 * OTO status callback: `{ orderId, otoId, status, trackingNumber,
 * dcTrackingNumber, deliveryCompany, timestamp }`. Advances the matching
 * per-vendor shipment's status and back-fills tracking, then syncs that store's
 * line items (delivered / returned / cancelled) and rolls the order status up.
 *
 * INTENTIONALLY UNAUTHENTICATED (OTO has no 3bayti JWT). Safety:
 *   1. OtoWebhookVerifier checks the HMAC signature / authorization key when
 *      configured (TRYOTO_WEBHOOK_SECRET / TRYOTO_WEBHOOK_AUTH_KEY).
 *   2. RETRIEVE-BEFORE-ACTING: we only ever touch a shipment we already booked
 *      and whose provider id (otoId) matches — a spoofed call can't invent one.
 * Unknown/known-but-unmatched payloads are ACKed with 200 so OTO doesn't retry.
 *
 * NEVER add AuthMiddleware to this route.
 */
final class OtoWebhookController
{
    use Responder;

    /** Item statuses we never move backward from on a status callback. */
    private const TERMINAL_ITEM_STATUSES = [
        OrderItem::ITEM_STATUS_DELIVERED,
        OrderItem::ITEM_STATUS_RETURNED,
        OrderItem::ITEM_STATUS_REFUNDED,
        OrderItem::ITEM_STATUS_CANCELLED,
        OrderItem::ITEM_STATUS_REJECTED,
    ];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OtoWebhookVerifier $verifier,
        private readonly AuditEmitter $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $raw = (string) $request->getBody();
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            return $this->ok(['success' => false, 'ignored' => 'invalid_json']);
        }
        /** @var array<string, mixed> $body */

        if (!$this->verifier->verify($request, $body)) {
            $this->logger->warning('oto.webhook.unauthorized', ['oto_id' => $body['otoId'] ?? null]);
            $response = $this->responseFactory->createResponse(401);
            $response->getBody()->write((string) json_encode(['success' => false, 'error' => 'unauthorized']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $status = trim((string) ($body['status'] ?? ''));
        $otoId = trim((string) ($body['otoId'] ?? ''));
        $orderRef = trim((string) ($body['orderId'] ?? ''));
        if ($status === '') {
            return $this->ok(['success' => true, 'ignored' => 'no_status']);
        }

        $shipment = $this->resolveShipment($otoId, $orderRef);
        if ($shipment === null) {
            $this->logger->info('oto.webhook.unknown_shipment', ['oto_id' => $otoId, 'order_id' => $orderRef]);
            return $this->ok(['success' => true, 'ignored' => 'unknown_shipment']);
        }

        $beforeStatus = $shipment->getStatus();
        $shipment->applyOtoStatus(
            $status,
            $this->strOrNull($body['trackingNumber'] ?? null),
            $this->strOrNull($body['dcTrackingNumber'] ?? null),
            $this->strOrNull($body['deliveryCompany'] ?? null),
        );

        $order = $shipment->getOrder();
        $implied = $shipment->impliedItemStatus();
        if ($implied !== null) {
            $this->syncItems($order, $shipment->getVendor(), $implied);
            $order->recomputeStatusFromItems();
        }

        $this->em->flush();

        $this->audit->recordUpdate(
            request: $request,
            actor: null, // system: the courier provider
            subject: $shipment,
            beforeSnapshot: ['status' => $beforeStatus],
            afterSnapshot: ['status' => $shipment->getStatus(), 'oto_status' => $status],
        );

        $this->logger->info('oto.webhook.applied', [
            'oto_id' => $otoId,
            'order_reference' => $order->getOrderReference(),
            'vendor_id' => $shipment->getVendor()->getId(),
            'status_before' => $beforeStatus,
            'status_after' => $shipment->getStatus(),
            'oto_status' => $status,
        ]);

        return $this->ok(['success' => true]);
    }

    private function resolveShipment(string $otoId, string $orderRef): ?OrderShipment
    {
        /** @var OrderShipmentRepository $shipments */
        $shipments = $this->em->getRepository(OrderShipment::class);

        if ($otoId !== '') {
            $found = $shipments->findByProviderOrderId($otoId);
            if ($found !== null) {
                return $found;
            }
        }

        // Fallback: our orderId is "{orderReference}-V{vendorId}".
        if ($orderRef !== '' && preg_match('/^(.*)-V(\d+)$/', $orderRef, $m) === 1) {
            /** @var OrderRepository $orders */
            $orders = $this->em->getRepository(Order::class);
            $order = $orders->findByOrderReference($m[1]);
            $vendor = $this->em->getRepository(Vendor::class)->find((int) $m[2]);
            if ($order !== null && $vendor instanceof Vendor) {
                return $shipments->findForOrderAndVendor($order, $vendor);
            }
        }

        return null;
    }

    private function syncItems(Order $order, Vendor $vendor, string $impliedStatus): void
    {
        $vendorId = $vendor->getId();
        foreach ($order->getItems() as $item) {
            if ($item->getVendor()->getId() !== $vendorId) {
                continue;
            }
            if (in_array($item->getItemStatus(), self::TERMINAL_ITEM_STATUSES, true)) {
                continue; // never move a terminal item backward
            }
            try {
                // The courier is authoritative on delivery outcome, so force it.
                $item->setItemStatus($impliedStatus, adminOverride: true);
            } catch (\InvalidArgumentException) {
                // Unknown status target — skip this item rather than 500.
            }
        }
    }

    private function strOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return (string) $v;
    }
}
