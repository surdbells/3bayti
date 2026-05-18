<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Order\OrderReturnRefund;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestItem;
use Bayti\Api\Domain\Order\OrderReturnRequestPhoto;
use DateTimeInterface;

/**
 * Convert OrderReturnRequest entities into response shapes
 * (M3.2.X.18-D).
 *
 * Three shape variants (the multivendor architecture means a single
 * request can be viewed by different actors with different concerns):
 *
 *   - customerShape: what the customer sees on their account
 *     — full lifecycle status, their own items, their photos
 *   - vendorShape: what a vendor sees on the vendor portal
 *     — only items they sold, vendor-side actions available
 *   - adminShape: what admin sees on the operator console
 *     — full request including all items + photo metadata for review,
 *     plus admin-only fields (customer_user_id, decided_by_admin)
 *
 * Photo URLs
 * ==========
 * Photos are served through GET /v3/returns/{id}/photos/{photoId}
 * (auth-gated). The serializer emits relative URLs (without origin)
 * — clients prepend their configured API base. storage_path is NEVER
 * exposed in any shape — it's an internal implementation detail.
 *
 * Refund block
 * ============
 * When the request reaches STATUS_REFUNDED, the OrderReturnRefund
 * child entity exists. Customer-shape exposes amount/method/reference
 * (so customer can verify); admin-shape additionally includes
 * recorded_by_admin attribution.
 */
final class ReturnRequestSerializer
{
    /**
     * Customer-facing shape — what the customer sees on "my returns".
     *
     * @return array<string, mixed>
     */
    public function customerShape(OrderReturnRequest $request): array
    {
        return [
            'id' => $request->getId() ?? 0,
            'order_id' => $request->getOrder()->getId() ?? 0,
            'order_reference' => $request->getOrder()->getOrderReference(),
            'status' => $request->getStatus(),
            'reason' => $request->getReason(),
            'customer_notes' => $request->getCustomerNotes(),
            // Customer can see admin's denial reason or approval note
            // (it's about THEIR return — they deserve to know).
            'admin_notes' => $request->getAdminNotes(),
            'requested_at' => $this->iso($request->getRequestedAt()),
            'decided_at' => $this->iso($request->getDecidedAt()),
            'picked_up_at' => $this->iso($request->getPickedUpAt()),
            'delivered_to_vendor_at' => $this->iso($request->getDeliveredToVendorAt()),
            'refunded_at' => $this->iso($request->getRefundedAt()),
            'cancelled_at' => $this->iso($request->getCancelledAt()),
            'items' => $this->itemShapes($request),
            'photos' => $this->photoShapes($request),
            'refund' => $this->refundShape($request->getRefund(), forAdmin: false),
            'is_terminal' => $request->isTerminal(),
        ];
    }

    /**
     * Vendor-facing shape — only items relevant to the vendor.
     * Caller passes the vendor's id; serializer filters items.
     *
     * @return array<string, mixed>
     */
    public function vendorShape(OrderReturnRequest $request, int $vendorId): array
    {
        return [
            'id' => $request->getId() ?? 0,
            'order_id' => $request->getOrder()->getId() ?? 0,
            'order_reference' => $request->getOrder()->getOrderReference(),
            'status' => $request->getStatus(),
            'reason' => $request->getReason(),
            'customer_notes' => $request->getCustomerNotes(),
            'requested_at' => $this->iso($request->getRequestedAt()),
            'picked_up_at' => $this->iso($request->getPickedUpAt()),
            'delivered_to_vendor_at' => $this->iso($request->getDeliveredToVendorAt()),
            // Items filtered to this vendor only.
            'items' => $this->itemShapesForVendor($request, $vendorId),
            // Vendor sees photo metadata + the auth-gated URL so they
            // can review evidence before confirming receipt. They
            // can't see admin_notes or refund details.
            'photos' => $this->photoShapes($request),
        ];
    }

    /**
     * Admin-facing shape — full visibility.
     *
     * @return array<string, mixed>
     */
    public function adminShape(OrderReturnRequest $request): array
    {
        $customer = $request->getCustomer();
        $decider = $request->getDecidedByAdmin();
        return [
            'id' => $request->getId() ?? 0,
            'order_id' => $request->getOrder()->getId() ?? 0,
            'order_reference' => $request->getOrder()->getOrderReference(),
            'customer_user_id' => $customer->getId() ?? 0,
            'customer_email' => $customer->getEmail(),
            'status' => $request->getStatus(),
            'reason' => $request->getReason(),
            'customer_notes' => $request->getCustomerNotes(),
            'admin_notes' => $request->getAdminNotes(),
            'requested_at' => $this->iso($request->getRequestedAt()),
            'decided_at' => $this->iso($request->getDecidedAt()),
            'decided_by_admin_user_id' => $decider?->getId(),
            'picked_up_at' => $this->iso($request->getPickedUpAt()),
            'delivered_to_vendor_at' => $this->iso($request->getDeliveredToVendorAt()),
            'refunded_at' => $this->iso($request->getRefundedAt()),
            'cancelled_at' => $this->iso($request->getCancelledAt()),
            'items' => $this->itemShapes($request),
            'photos' => $this->photoShapes($request),
            'refund' => $this->refundShape($request->getRefund(), forAdmin: true),
            'is_terminal' => $request->isTerminal(),
            'vendor_ids' => $request->getVendorIds(),
        ];
    }

    /**
     * @param iterable<OrderReturnRequest> $requests
     * @return list<array<string, mixed>>
     */
    public function customerShapeMany(iterable $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            $out[] = $this->customerShape($r);
        }
        return $out;
    }

    /**
     * @param iterable<OrderReturnRequest> $requests
     * @return list<array<string, mixed>>
     */
    public function vendorShapeMany(iterable $requests, int $vendorId): array
    {
        $out = [];
        foreach ($requests as $r) {
            $out[] = $this->vendorShape($r, $vendorId);
        }
        return $out;
    }

    /**
     * @param iterable<OrderReturnRequest> $requests
     * @return list<array<string, mixed>>
     */
    public function adminShapeMany(iterable $requests): array
    {
        $out = [];
        foreach ($requests as $r) {
            $out[] = $this->adminShape($r);
        }
        return $out;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function itemShapes(OrderReturnRequest $request): array
    {
        $out = [];
        foreach ($request->getItems() as $item) {
            $out[] = $this->itemShape($item);
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemShapesForVendor(OrderReturnRequest $request, int $vendorId): array
    {
        $out = [];
        foreach ($request->getItems() as $item) {
            if ($item->getVendor()->getId() === $vendorId) {
                $out[] = $this->itemShape($item);
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemShape(OrderReturnRequestItem $item): array
    {
        $orderItem = $item->getOrderItem();
        $vendor = $item->getVendor();
        return [
            'id' => $item->getId() ?? 0,
            'order_item_id' => $orderItem->getId() ?? 0,
            'product_name' => $orderItem->getProductNameSnapshot(),
            'product_image' => $orderItem->getProductImageSnapshot(),
            'vendor_id' => $vendor->getId() ?? 0,
            'vendor_name' => $vendor->getName(),
            'quantity' => $item->getQuantity(),
            'unit_price' => $item->getUnitPriceSnapshot(),
            'line_subtotal' => $item->getLineSubtotal(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function photoShapes(OrderReturnRequest $request): array
    {
        $out = [];
        $returnId = $request->getId() ?? 0;
        foreach ($request->getPhotos() as $photo) {
            $out[] = $this->photoShape($photo, $returnId);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function photoShape(OrderReturnRequestPhoto $photo, int $returnId): array
    {
        $photoId = $photo->getId() ?? 0;
        return [
            'id' => $photoId,
            'mime_type' => $photo->getMimeType(),
            'size_bytes' => $photo->getSizeBytes(),
            'original_filename' => $photo->getOriginalFilename(),
            'uploaded_at' => $this->iso($photo->getUploadedAt()),
            // Auth-gated URL; client prepends API base.
            'url' => "/v3/returns/{$returnId}/photos/{$photoId}",
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function refundShape(?OrderReturnRefund $refund, bool $forAdmin): ?array
    {
        if ($refund === null) {
            return null;
        }
        $shape = [
            'method' => $refund->getMethod(),
            'amount' => $refund->getAmount(),
            'currency' => $refund->getCurrency(),
            'reference' => $refund->getReference(),
            'notes' => $refund->getNotes(),
            'recorded_at' => $this->iso($refund->getRecordedAt()),
        ];
        if ($forAdmin) {
            $shape['recorded_by_admin_user_id'] = $refund->getRecordedByAdmin()?->getId();
        }
        return $shape;
    }

    private function iso(?DateTimeInterface $dt): ?string
    {
        return $dt?->format(DateTimeInterface::ATOM);
    }
}
