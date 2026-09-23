<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Customization\CustomizationRequest;
use DateTimeInterface;

/**
 * Convert CustomizationRequest entities into response shapes (P5).
 *
 * Two actor views:
 *   - customerShape: what the customer sees on "my customization requests"
 *     — full lifecycle, the vendor's quote, and (once accepted) the payment
 *     order reference so the client can initiate checkout.
 *   - vendorShape: what the vendor sees on their portal — the request +
 *     the customer's notes + measurement snapshot (they need these to do
 *     the work and quote it), but no customer contact PII.
 *
 * The embedded product is the real ProductSerializer::listShape() card, so
 * the client always renders live product data (never an invented snapshot).
 */
final class CustomizationRequestSerializer
{
    public function __construct(
        private readonly ProductSerializer $productSerializer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function customerShape(CustomizationRequest $request): array
    {
        return [
            'id' => $request->getId() ?? 0,
            'status' => $request->getStatus(),
            'product' => $this->productSerializer->listShape($request->getProduct()),
            'vendor' => $this->vendorSummary($request),
            'customer_notes' => $request->getCustomerNotes(),
            'measurement_snapshot' => $request->getMeasurementSnapshot(),
            'quote' => $this->quoteShape($request),
            // Present only once the customer has accepted and a synthetic
            // payment order exists — the client checks out with this ref.
            'payment_order_reference' => $request->getPaymentOrderReference(),
            'timestamps' => $this->timestamps($request),
            'is_terminal' => $request->isTerminal(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function vendorShape(CustomizationRequest $request): array
    {
        return [
            'id' => $request->getId() ?? 0,
            'status' => $request->getStatus(),
            'product' => $this->productSerializer->listShape($request->getProduct()),
            // The vendor needs these to scope + price the work.
            'customer_notes' => $request->getCustomerNotes(),
            'measurement_snapshot' => $request->getMeasurementSnapshot(),
            'quote' => $this->quoteShape($request),
            'timestamps' => $this->timestamps($request),
            'is_terminal' => $request->isTerminal(),
        ];
    }

    /**
     * @param list<CustomizationRequest> $requests
     * @return list<array<string, mixed>>
     */
    public function customerShapeMany(array $requests): array
    {
        return array_map(fn (CustomizationRequest $r): array => $this->customerShape($r), $requests);
    }

    /**
     * @param list<CustomizationRequest> $requests
     * @return list<array<string, mixed>>
     */
    public function vendorShapeMany(array $requests): array
    {
        return array_map(fn (CustomizationRequest $r): array => $this->vendorShape($r), $requests);
    }

    /**
     * @return array<string, mixed>
     */
    private function vendorSummary(CustomizationRequest $request): array
    {
        $vendor = $request->getVendor();
        return [
            'id' => $vendor->getId() ?? 0,
            'name' => $vendor->getName(),
            'slug' => $vendor->getSlug(),
        ];
    }

    /**
     * The vendor's quote, or null until one has been given.
     *
     * @return array<string, mixed>|null
     */
    private function quoteShape(CustomizationRequest $request): ?array
    {
        $amount = $request->getQuoteAmount();
        if ($amount === null) {
            return null;
        }
        return [
            'amount' => $amount,
            'currency' => $request->getQuoteCurrency(),
            'lead_time_days' => $request->getQuoteLeadTimeDays(),
            'vendor_notes' => $request->getVendorNotes(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function timestamps(CustomizationRequest $request): array
    {
        return [
            'requested_at' => $this->iso($request->getRequestedAt()),
            'quoted_at' => $this->iso($request->getQuotedAt()),
            'accepted_at' => $this->iso($request->getAcceptedAt()),
            'paid_at' => $this->iso($request->getPaidAt()),
            'completed_at' => $this->iso($request->getCompletedAt()),
            'declined_at' => $this->iso($request->getDeclinedAt()),
            'rejected_at' => $this->iso($request->getRejectedAt()),
            'cancelled_at' => $this->iso($request->getCancelledAt()),
        ];
    }

    private function iso(?DateTimeInterface $dt): ?string
    {
        return $dt?->format(DateTimeInterface::ATOM);
    }
}
