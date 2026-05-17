<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for Vendor lifecycle status (M3.2.X.6-A).
 *
 * Locks the state machine semantics + atomic invariants between
 * the new status enum and the legacy is_store_approved flag.
 */
#[CoversClass(Vendor::class)]
final class VendorStatusTest extends TestCase
{
    #[Test]
    public function statusConstantsExposed(): void
    {
        self::assertSame('pending', Vendor::STATUS_PENDING);
        self::assertSame('approved', Vendor::STATUS_APPROVED);
        self::assertSame('suspended', Vendor::STATUS_SUSPENDED);
        self::assertSame(
            ['pending', 'approved', 'suspended'],
            Vendor::ALL_STATUSES,
            'ALL_STATUSES exposes the full set for endpoint validators',
        );
    }

    #[Test]
    public function newVendorStartsAsPending(): void
    {
        $vendor = $this->makeVendor();

        self::assertSame(Vendor::STATUS_PENDING, $vendor->getStatus());
        self::assertTrue($vendor->isPending());
        self::assertFalse($vendor->isApproved());
        self::assertFalse($vendor->isSuspended());
        self::assertNull(
            $vendor->getStatusChangedAt(),
            'Pending vendors that never transitioned have null statusChangedAt',
        );
        self::assertNull($vendor->getStatusReason());
        self::assertFalse(
            $vendor->isStoreApproved(),
            'Legacy is_store_approved starts false (consistent with pending status)',
        );
    }

    #[Test]
    public function approveFromPendingTransitionsToApproved(): void
    {
        $vendor = $this->makeVendor();
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $vendor->approve('Initial KYC review passed');

        self::assertSame(Vendor::STATUS_APPROVED, $vendor->getStatus());
        self::assertTrue($vendor->isApproved());
        self::assertSame('Initial KYC review passed', $vendor->getStatusReason());
        self::assertNotNull($vendor->getStatusChangedAt());
        self::assertGreaterThanOrEqual($before, $vendor->getStatusChangedAt());
        self::assertTrue(
            $vendor->isStoreApproved(),
            'Legacy is_store_approved set atomically with status approval (Q-LegacyFlags = A)',
        );
    }

    #[Test]
    public function approveWithNoReasonStoresNull(): void
    {
        $vendor = $this->makeVendor();

        $vendor->approve();

        self::assertNull(
            $vendor->getStatusReason(),
            'Reason is optional; null is valid when admin provides none',
        );
    }

    #[Test]
    public function approveFromAlreadyApprovedThrows(): void
    {
        $vendor = $this->makeVendor();
        $vendor->approve();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already in approved state');

        $vendor->approve('duplicate call');
    }

    #[Test]
    public function suspendFromApprovedTransitionsToSuspended(): void
    {
        $vendor = $this->makeVendor();
        $vendor->approve();

        $vendor->suspend('Repeated quality complaints');

        self::assertSame(Vendor::STATUS_SUSPENDED, $vendor->getStatus());
        self::assertTrue($vendor->isSuspended());
        self::assertSame('Repeated quality complaints', $vendor->getStatusReason());
        self::assertNotNull($vendor->getStatusChangedAt());
        // Critical invariant: suspension does NOT toggle the legacy
        // is_store_approved flag. The vendor WAS approved; the
        // suspension is the more-recent operational state.
        self::assertTrue(
            $vendor->isStoreApproved(),
            'is_store_approved preserved on suspension — vendor was historically approved',
        );
    }

    #[Test]
    public function suspendFromPendingThrows(): void
    {
        $vendor = $this->makeVendor();
        // Vendor is pending; never approved

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot suspend vendor from pending state');

        $vendor->suspend();
    }

    #[Test]
    public function suspendFromAlreadySuspendedThrows(): void
    {
        $vendor = $this->makeVendor();
        $vendor->approve();
        $vendor->suspend();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot suspend vendor from suspended state');

        $vendor->suspend('duplicate');
    }

    #[Test]
    public function reactivateFromSuspendedTransitionsToApproved(): void
    {
        $vendor = $this->makeVendor();
        $vendor->approve();
        $vendor->suspend('Quality issues');

        $vendor->reactivate('Issues resolved after vendor training');

        self::assertSame(Vendor::STATUS_APPROVED, $vendor->getStatus());
        self::assertTrue($vendor->isApproved());
        self::assertSame('Issues resolved after vendor training', $vendor->getStatusReason());
        self::assertTrue($vendor->isStoreApproved());
    }

    #[Test]
    public function reactivateFromPendingThrows(): void
    {
        $vendor = $this->makeVendor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reactivation is only valid from suspended state');

        $vendor->reactivate();
    }

    #[Test]
    public function reactivateFromApprovedThrows(): void
    {
        $vendor = $this->makeVendor();
        $vendor->approve();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reactivation is only valid from suspended state');

        $vendor->reactivate();
    }

    #[Test]
    public function transitionsUpdateStatusChangedAtEachTime(): void
    {
        $vendor = $this->makeVendor();

        $vendor->approve('first');
        $firstTimestamp = $vendor->getStatusChangedAt();
        self::assertNotNull($firstTimestamp);

        usleep(2000); // ensure tick

        $vendor->suspend('second');
        $secondTimestamp = $vendor->getStatusChangedAt();

        self::assertNotNull($secondTimestamp);
        self::assertGreaterThan(
            $firstTimestamp,
            $secondTimestamp,
            'statusChangedAt advances on each transition',
        );
        self::assertSame('second', $vendor->getStatusReason());
    }

    #[Test]
    public function reasonOverwrittenOnEachTransition(): void
    {
        // Each transition overwrites the previous reason. The audit
        // log is the history of reasons across transitions; the
        // entity column only carries the most recent.
        $vendor = $this->makeVendor();
        $vendor->approve('approved-reason');
        self::assertSame('approved-reason', $vendor->getStatusReason());

        $vendor->suspend('suspended-reason');
        self::assertSame('suspended-reason', $vendor->getStatusReason());

        $vendor->reactivate(); // no reason
        self::assertNull(
            $vendor->getStatusReason(),
            'Reason resets to null when caller omits it',
        );
    }

    #[Test]
    public function statusIndependentOfIsFeatured(): void
    {
        // Status and is_featured are unrelated. An admin could
        // feature a still-pending vendor (rare but possible), or
        // suspend a featured vendor.
        $vendor = $this->makeVendor();
        $vendor->setFeatured(true);
        $vendor->approve();

        self::assertTrue($vendor->isFeatured());
        self::assertTrue($vendor->isApproved());

        $vendor->suspend();

        self::assertTrue(
            $vendor->isFeatured(),
            'Suspension does not unflag featured — that is a separate admin action',
        );
        self::assertTrue($vendor->isSuspended());
    }

    private function makeVendor(): Vendor
    {
        return new Vendor('test-vendor', 'Test Vendor', 'vendor@example.test');
    }
}
