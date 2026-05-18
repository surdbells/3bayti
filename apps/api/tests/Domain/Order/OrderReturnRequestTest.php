<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Order;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderReturnRefund;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestItem;
use Bayti\Api\Domain\Order\OrderReturnRequestPhoto;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the M3.2.X.18-A domain entities.
 *
 * Verifies:
 *   - OrderReturnRequest state machine (legal + illegal transitions)
 *   - Reason taxonomy + 'other' requires customer_notes
 *   - OrderReturnRequestItem quantity validation + denormalized vendor
 *   - OrderReturnRequestPhoto mime + size limits
 *   - OrderReturnRefund method validation + money invariants
 *   - getVendorIds() deduplication across items
 *   - Bidirectional collection wiring (addItem / addPhoto)
 *
 * Direct-construction tests — no DB, no DI. Entities are constructed
 * via their real constructors; ids set via reflection where needed
 * (matches the established pattern in LocaleResolverTest +
 * OrderSerializerPromoTest).
 */
#[CoversClass(OrderReturnRequest::class)]
#[CoversClass(OrderReturnRequestItem::class)]
#[CoversClass(OrderReturnRequestPhoto::class)]
#[CoversClass(OrderReturnRefund::class)]
final class OrderReturnRequestTest extends TestCase
{
    // =================================================================
    // OrderReturnRequest — construction + reason validation
    // =================================================================

    #[Test]
    public function constructsWithValidReasonAndDefaultsToPending(): void
    {
        $request = new OrderReturnRequest(
            order: $this->makeOrder(),
            customer: $this->makeCustomer(),
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'Stitching came loose',
        );

        self::assertSame(OrderReturnRequest::STATUS_PENDING, $request->getStatus());
        self::assertSame(OrderReturnRequest::REASON_DEFECTIVE, $request->getReason());
        self::assertSame('Stitching came loose', $request->getCustomerNotes());
        self::assertNull($request->getDecidedAt());
        self::assertNull($request->getDecidedByAdmin());
        self::assertFalse($request->isTerminal());
        self::assertCount(0, $request->getItems());
        self::assertCount(0, $request->getPhotos());
        self::assertNull($request->getRefund());
    }

    #[Test]
    public function rejectsUnknownReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown return reason 'made_up_reason'");
        new OrderReturnRequest(
            order: $this->makeOrder(),
            customer: $this->makeCustomer(),
            reason: 'made_up_reason',
        );
    }

    #[Test]
    public function reasonOtherRequiresCustomerNotes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'other' requires non-empty customer_notes");
        new OrderReturnRequest(
            order: $this->makeOrder(),
            customer: $this->makeCustomer(),
            reason: OrderReturnRequest::REASON_OTHER,
            customerNotes: '',
        );
    }

    #[Test]
    public function reasonOtherWithWhitespaceOnlyNotesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrderReturnRequest(
            order: $this->makeOrder(),
            customer: $this->makeCustomer(),
            reason: OrderReturnRequest::REASON_OTHER,
            customerNotes: "   \t\n  ",
        );
    }

    #[Test]
    public function reasonOtherWithSubstantiveNotesIsAccepted(): void
    {
        $request = new OrderReturnRequest(
            order: $this->makeOrder(),
            customer: $this->makeCustomer(),
            reason: OrderReturnRequest::REASON_OTHER,
            customerNotes: 'Item arrived with damaged packaging',
        );
        self::assertSame('Item arrived with damaged packaging', $request->getCustomerNotes());
    }

    #[Test]
    public function customerNotesAreTrimmed(): void
    {
        $request = new OrderReturnRequest(
            order: $this->makeOrder(),
            customer: $this->makeCustomer(),
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: "  defective zipper  \n",
        );
        self::assertSame('defective zipper', $request->getCustomerNotes());
    }

    #[Test]
    public function allSevenReasonConstantsAreValid(): void
    {
        // Defensive — protects against typo regressions in the constant list.
        self::assertCount(7, OrderReturnRequest::ALL_REASONS);
        foreach (OrderReturnRequest::ALL_REASONS as $reason) {
            $notes = $reason === OrderReturnRequest::REASON_OTHER ? 'some explanation' : null;
            $request = new OrderReturnRequest(
                order: $this->makeOrder(),
                customer: $this->makeCustomer(),
                reason: $reason,
                customerNotes: $notes,
            );
            self::assertSame($reason, $request->getReason());
        }
    }

    // =================================================================
    // OrderReturnRequest — state machine (legal transitions)
    // =================================================================

    #[Test]
    public function approveTransitionsFromPendingAndStampsDecisionFields(): void
    {
        $admin = $this->makeAdmin();
        $request = $this->makePendingRequest();
        self::assertSame(OrderReturnRequest::STATUS_PENDING, $request->getStatus());

        $request->approve($admin, 'Photos confirm defect');

        self::assertSame(OrderReturnRequest::STATUS_APPROVED, $request->getStatus());
        self::assertNotNull($request->getDecidedAt());
        self::assertSame($admin, $request->getDecidedByAdmin());
        self::assertSame('Photos confirm defect', $request->getAdminNotes());
    }

    #[Test]
    public function approveAllowsNullAdminNotes(): void
    {
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());
        self::assertSame(OrderReturnRequest::STATUS_APPROVED, $request->getStatus());
        self::assertNull($request->getAdminNotes());
    }

    #[Test]
    public function denyTransitionsFromPendingAndRequiresAdminNotes(): void
    {
        $admin = $this->makeAdmin();
        $request = $this->makePendingRequest();

        $request->deny($admin, 'Items appear unused and in original condition; not defective');

        self::assertSame(OrderReturnRequest::STATUS_DENIED, $request->getStatus());
        self::assertNotNull($request->getDecidedAt());
        self::assertSame($admin, $request->getDecidedByAdmin());
        self::assertSame(
            'Items appear unused and in original condition; not defective',
            $request->getAdminNotes(),
        );
        self::assertTrue($request->isTerminal());
    }

    #[Test]
    public function denyRejectsEmptyAdminNotes(): void
    {
        $request = $this->makePendingRequest();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Denial requires non-empty admin_notes');
        $request->deny($this->makeAdmin(), '');
    }

    #[Test]
    public function denyRejectsWhitespaceOnlyAdminNotes(): void
    {
        $request = $this->makePendingRequest();
        $this->expectException(\InvalidArgumentException::class);
        $request->deny($this->makeAdmin(), "  \t  \n");
    }

    #[Test]
    public function markPickedUpTransitionsFromApproved(): void
    {
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());

        $request->markPickedUp();

        self::assertSame(OrderReturnRequest::STATUS_PICKED_UP, $request->getStatus());
        self::assertNotNull($request->getPickedUpAt());
    }

    #[Test]
    public function confirmReceivedByVendorTransitionsFromPickedUp(): void
    {
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());
        $request->markPickedUp();

        $request->confirmReceivedByVendor();

        self::assertSame(OrderReturnRequest::STATUS_DELIVERED_TO_VENDOR, $request->getStatus());
        self::assertNotNull($request->getDeliveredToVendorAt());
    }

    #[Test]
    public function markRefundedTransitionsFromDeliveredToVendor(): void
    {
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());
        $request->markPickedUp();
        $request->confirmReceivedByVendor();

        $refund = new OrderReturnRefund(
            returnRequest: $request,
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: '99.00',
            reference: 'TXN-12345',
        );

        $request->markRefunded($refund);

        self::assertSame(OrderReturnRequest::STATUS_REFUNDED, $request->getStatus());
        self::assertNotNull($request->getRefundedAt());
        self::assertSame($refund, $request->getRefund());
        self::assertTrue($request->isTerminal());
    }

    #[Test]
    public function cancelByCustomerTransitionsFromPending(): void
    {
        $request = $this->makePendingRequest();

        $request->cancelByCustomer();

        self::assertSame(OrderReturnRequest::STATUS_CANCELLED, $request->getStatus());
        self::assertNotNull($request->getCancelledAt());
        self::assertTrue($request->isTerminal());
    }

    // =================================================================
    // OrderReturnRequest — state machine (illegal transitions)
    // =================================================================

    #[Test]
    public function approveFromApprovedIsRejected(): void
    {
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cannot approve from status 'approved'");
        $request->approve($this->makeAdmin());
    }

    #[Test]
    public function approveFromDeniedIsRejected(): void
    {
        $request = $this->makePendingRequest();
        $request->deny($this->makeAdmin(), 'denied');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cannot approve from status 'denied'");
        $request->approve($this->makeAdmin());
    }

    #[Test]
    public function markPickedUpFromPendingIsRejected(): void
    {
        $request = $this->makePendingRequest();
        // Skip approval — try to go straight to picked_up.

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cannot mark picked-up from status 'pending'");
        $request->markPickedUp();
    }

    #[Test]
    public function confirmReceivedFromApprovedIsRejected(): void
    {
        // Must go through markPickedUp first.
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cannot confirm vendor receipt from status 'approved'");
        $request->confirmReceivedByVendor();
    }

    #[Test]
    public function markRefundedFromPickedUpIsRejected(): void
    {
        // Must go through confirmReceivedByVendor first.
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());
        $request->markPickedUp();
        $refund = new OrderReturnRefund(
            returnRequest: $request,
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: '50.00',
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(
            "Cannot mark refunded from status 'picked_up'; "
            . "must be 'delivered_to_vendor'."
        );
        $request->markRefunded($refund);
    }

    #[Test]
    public function cancelByCustomerFromApprovedIsRejected(): void
    {
        // Once admin has approved, customer can't unilaterally cancel.
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cannot cancel from status 'approved'");
        $request->cancelByCustomer();
    }

    #[Test]
    public function cancelByCustomerFromRefundedIsRejected(): void
    {
        $request = $this->makePendingRequest();
        $request->approve($this->makeAdmin());
        $request->markPickedUp();
        $request->confirmReceivedByVendor();
        $refund = new OrderReturnRefund(
            returnRequest: $request,
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: '99.00',
        );
        $request->markRefunded($refund);

        $this->expectException(\DomainException::class);
        $request->cancelByCustomer();
    }

    // =================================================================
    // OrderReturnRequest — getVendorIds dedup
    // =================================================================

    #[Test]
    public function getVendorIdsReturnsDistinctVendorsAcrossItems(): void
    {
        $request = $this->makePendingRequest();
        $order = $request->getOrder();
        $vendorA = $this->makeVendor(101);
        $vendorB = $this->makeVendor(202);

        $itemA1 = $this->makeOrderItem($order, $vendorA, qty: 1, unitPrice: '10.00');
        $itemA2 = $this->makeOrderItem($order, $vendorA, qty: 1, unitPrice: '20.00');
        $itemB1 = $this->makeOrderItem($order, $vendorB, qty: 1, unitPrice: '30.00');

        $request->addItem(new OrderReturnRequestItem($itemA1, 1));
        $request->addItem(new OrderReturnRequestItem($itemA2, 1));
        $request->addItem(new OrderReturnRequestItem($itemB1, 1));

        $vendorIds = $request->getVendorIds();
        sort($vendorIds);
        self::assertSame([101, 202], $vendorIds);
    }

    #[Test]
    public function getVendorIdsReturnsEmptyForRequestWithoutItems(): void
    {
        $request = $this->makePendingRequest();
        self::assertSame([], $request->getVendorIds());
    }

    // =================================================================
    // OrderReturnRequestItem
    // =================================================================

    #[Test]
    public function returnItemSnapshotsUnitPriceAndComputesLineSubtotal(): void
    {
        $order = $this->makeOrder();
        $vendor = $this->makeVendor(101);
        $orderItem = $this->makeOrderItem($order, $vendor, qty: 5, unitPrice: '19.99');

        $returnItem = new OrderReturnRequestItem($orderItem, 3);

        self::assertSame(3, $returnItem->getQuantity());
        self::assertSame('19.99', $returnItem->getUnitPriceSnapshot());
        self::assertSame('59.97', $returnItem->getLineSubtotal());  // 19.99 * 3
        self::assertSame($vendor, $returnItem->getVendor());
        self::assertSame($orderItem, $returnItem->getOrderItem());
    }

    #[Test]
    public function returnItemRejectsZeroQuantity(): void
    {
        $orderItem = $this->makeOrderItem($this->makeOrder(), $this->makeVendor(101), qty: 3, unitPrice: '10.00');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Return quantity must be > 0');
        new OrderReturnRequestItem($orderItem, 0);
    }

    #[Test]
    public function returnItemRejectsNegativeQuantity(): void
    {
        $orderItem = $this->makeOrderItem($this->makeOrder(), $this->makeVendor(101), qty: 3, unitPrice: '10.00');
        $this->expectException(\InvalidArgumentException::class);
        new OrderReturnRequestItem($orderItem, -1);
    }

    #[Test]
    public function returnItemRejectsOverReturn(): void
    {
        // Customer bought 3, tries to return 5.
        $orderItem = $this->makeOrderItem($this->makeOrder(), $this->makeVendor(101), qty: 3, unitPrice: '10.00');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds order item quantity 3');
        new OrderReturnRequestItem($orderItem, 5);
    }

    #[Test]
    public function returnItemAllowsFullQuantity(): void
    {
        // Customer bought 3, returning all 3 — boundary case.
        $orderItem = $this->makeOrderItem($this->makeOrder(), $this->makeVendor(101), qty: 3, unitPrice: '10.00');
        $returnItem = new OrderReturnRequestItem($orderItem, 3);
        self::assertSame(3, $returnItem->getQuantity());
        self::assertSame('30.00', $returnItem->getLineSubtotal());
    }

    #[Test]
    public function returnItemAddedToParentLinksBidirectionally(): void
    {
        $request = $this->makePendingRequest();
        $order = $request->getOrder();
        $vendor = $this->makeVendor(101);
        $orderItem = $this->makeOrderItem($order, $vendor, qty: 1, unitPrice: '10.00');

        $returnItem = new OrderReturnRequestItem($orderItem, 1);
        $request->addItem($returnItem);

        self::assertSame($request, $returnItem->getReturnRequest());
        self::assertCount(1, $request->getItems());
    }

    // =================================================================
    // OrderReturnRequestPhoto
    // =================================================================

    #[Test]
    public function photoConstructsWithValidJpeg(): void
    {
        $photo = new OrderReturnRequestPhoto(
            storagePath: 'return-photos/2026/05/42/abc.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 102_400,
            originalFilename: 'IMG_2031.jpg',
        );

        self::assertSame('return-photos/2026/05/42/abc.jpg', $photo->getStoragePath());
        self::assertSame('image/jpeg', $photo->getMimeType());
        self::assertSame(102_400, $photo->getSizeBytes());
        self::assertSame('IMG_2031.jpg', $photo->getOriginalFilename());
    }

    #[Test]
    public function photoConstructsWithValidPng(): void
    {
        $photo = new OrderReturnRequestPhoto(
            storagePath: 'return-photos/x.png',
            mimeType: 'image/png',
            sizeBytes: 200_000,
        );
        self::assertSame('image/png', $photo->getMimeType());
        self::assertNull($photo->getOriginalFilename());
    }

    #[Test]
    public function photoConstructsWithValidWebp(): void
    {
        $photo = new OrderReturnRequestPhoto(
            storagePath: 'return-photos/x.webp',
            mimeType: 'image/webp',
            sizeBytes: 50_000,
        );
        self::assertSame('image/webp', $photo->getMimeType());
    }

    #[Test]
    public function photoRejectsUnsupportedMimeType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported mime type 'image/gif'");
        new OrderReturnRequestPhoto(
            storagePath: 'x.gif',
            mimeType: 'image/gif',
            sizeBytes: 1000,
        );
    }

    #[Test]
    public function photoRejectsEmptyStoragePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('storage_path must not be empty');
        new OrderReturnRequestPhoto(
            storagePath: '   ',
            mimeType: 'image/jpeg',
            sizeBytes: 1000,
        );
    }

    #[Test]
    public function photoRejectsZeroSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('size_bytes must be > 0');
        new OrderReturnRequestPhoto(
            storagePath: 'x.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 0,
        );
    }

    #[Test]
    public function photoRejectsOversize(): void
    {
        // 5 MB limit + 1 byte
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds max');
        new OrderReturnRequestPhoto(
            storagePath: 'x.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: OrderReturnRequestPhoto::MAX_PHOTO_SIZE_BYTES + 1,
        );
    }

    #[Test]
    public function photoAtExactLimitIsAccepted(): void
    {
        $photo = new OrderReturnRequestPhoto(
            storagePath: 'x.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: OrderReturnRequestPhoto::MAX_PHOTO_SIZE_BYTES,
        );
        self::assertSame(
            OrderReturnRequestPhoto::MAX_PHOTO_SIZE_BYTES,
            $photo->getSizeBytes(),
        );
    }

    #[Test]
    public function photoNormalizesWhitespaceOnlyFilenameToNull(): void
    {
        $photo = new OrderReturnRequestPhoto(
            storagePath: 'x.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 1000,
            originalFilename: '   ',
        );
        self::assertNull($photo->getOriginalFilename());
    }

    #[Test]
    public function photoAddedToParentLinksBidirectionally(): void
    {
        $request = $this->makePendingRequest();
        $photo = new OrderReturnRequestPhoto(
            storagePath: 'x.jpg',
            mimeType: 'image/jpeg',
            sizeBytes: 1000,
        );
        $request->addPhoto($photo);

        self::assertSame($request, $photo->getReturnRequest());
        self::assertCount(1, $request->getPhotos());
    }

    // =================================================================
    // OrderReturnRefund
    // =================================================================

    #[Test]
    public function refundConstructsWithBankTransferAndRequiredFields(): void
    {
        $request = $this->makePendingRequest();
        $admin = $this->makeAdmin();

        $refund = new OrderReturnRefund(
            returnRequest: $request,
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: '99.99',
            reference: 'BANK-TXN-001',
            notes: 'Wired via Emirates NBD',
            recordedByAdmin: $admin,
        );

        self::assertSame('bank_transfer', $refund->getMethod());
        self::assertSame('99.99', $refund->getAmount());
        self::assertSame('AED', $refund->getCurrency());
        self::assertSame('BANK-TXN-001', $refund->getReference());
        self::assertSame('Wired via Emirates NBD', $refund->getNotes());
        self::assertSame($admin, $refund->getRecordedByAdmin());
    }

    #[Test]
    public function refundAcceptsAllFourMethods(): void
    {
        $request = $this->makePendingRequest();
        foreach (OrderReturnRefund::ALL_METHODS as $method) {
            $refund = new OrderReturnRefund(
                returnRequest: $request,
                method: $method,
                amount: '10.00',
            );
            self::assertSame($method, $refund->getMethod());
        }
    }

    #[Test]
    public function refundRejectsUnknownMethod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown refund method 'crypto'");
        new OrderReturnRefund(
            returnRequest: $this->makePendingRequest(),
            method: 'crypto',
            amount: '10.00',
        );
    }

    #[Test]
    public function refundRejectsZeroAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('amount must be > 0');
        new OrderReturnRefund(
            returnRequest: $this->makePendingRequest(),
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: '0',
        );
    }

    #[Test]
    public function refundRejectsMalformedAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a DECIMAL(10,2)');
        new OrderReturnRefund(
            returnRequest: $this->makePendingRequest(),
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: 'not-a-number',
        );
    }

    #[Test]
    public function refundRejectsThreeDecimalPlaces(): void
    {
        // DECIMAL(10,2) is the precision; values like 99.999 don't fit.
        $this->expectException(\InvalidArgumentException::class);
        new OrderReturnRefund(
            returnRequest: $this->makePendingRequest(),
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: '99.999',
        );
    }

    #[Test]
    public function refundUppercasesCurrency(): void
    {
        $refund = new OrderReturnRefund(
            returnRequest: $this->makePendingRequest(),
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: '10.00',
            currency: 'usd',
        );
        self::assertSame('USD', $refund->getCurrency());
    }

    #[Test]
    public function refundRejectsInvalidCurrencyFormat(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('3-letter ISO 4217');
        new OrderReturnRefund(
            returnRequest: $this->makePendingRequest(),
            method: OrderReturnRefund::METHOD_BANK_TRANSFER,
            amount: '10.00',
            currency: 'AEDR',
        );
    }

    #[Test]
    public function refundNormalizesWhitespaceOnlyReferenceToNull(): void
    {
        $refund = new OrderReturnRefund(
            returnRequest: $this->makePendingRequest(),
            method: OrderReturnRefund::METHOD_OTHER,
            amount: '10.00',
            reference: '   ',
            notes: '  ',
        );
        self::assertNull($refund->getReference());
        self::assertNull($refund->getNotes());
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makePendingRequest(): OrderReturnRequest
    {
        return new OrderReturnRequest(
            order: $this->makeOrder(),
            customer: $this->makeCustomer(),
            reason: OrderReturnRequest::REASON_DEFECTIVE,
            customerNotes: 'defective',
        );
    }

    private function makeOrder(): Order
    {
        $order = new Order(
            user: $this->makeCustomer(),
            orderReference: 'V3-RET-001',
            subtotal: '199.00',
        );
        $this->setEntityId($order, 100);
        return $order;
    }

    private function makeCustomer(): User
    {
        $user = new User(
            email: 'customer@example.com',
            phone: '+971501234567',
            passwordHash: password_hash('p', PASSWORD_BCRYPT),
            countryCode: 'AE',
        );
        $this->setEntityId($user, 42);
        return $user;
    }

    private function makeAdmin(): User
    {
        $user = new User(
            email: 'admin@example.com',
            phone: '+971501234999',
            passwordHash: password_hash('p', PASSWORD_BCRYPT),
            countryCode: 'AE',
        );
        $this->setEntityId($user, 1);
        $user->setRoles(admin: true);
        return $user;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($v, 'id', $id);
        $this->setEntityProp($v, 'name', "Vendor {$id}");
        $this->setEntityProp($v, 'contactEmail', "vendor{$id}@example.com");
        return $v;
    }

    private function makeOrderItem(Order $order, Vendor $vendor, int $qty, string $unitPrice): OrderItem
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', random_int(200, 999));
        $this->setEntityProp($product, 'name', 'Test product');
        $this->setEntityProp($product, 'vendor', $vendor);

        $item = new OrderItem(
            product: $product,
            vendor: $vendor,
            quantity: $qty,
            unitPrice: $unitPrice,
            productNameSnapshot: 'Test product',
            productImageSnapshot: 'cdn/x.jpg',
        );
        $this->setEntityId($item, random_int(500, 999));
        $order->addItem($item);
        return $item;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $this->setEntityProp($entity, 'id', $id);
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
