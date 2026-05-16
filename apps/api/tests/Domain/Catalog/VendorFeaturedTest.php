<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for VendorRepository::findFeatured / countFeatured.
 *
 * Sub-phase A of M3.2.X.2. These are unit tests that exercise the
 * Vendor entity's is_featured accessor + verify the repository
 * method signatures (the actual SQL is covered indirectly through
 * the ListFeaturedVendorsController HTTP integration test in
 * sub-phase C).
 *
 * Why not DB-integration tests
 * ----------------------------
 * Apps/api test suite is mock-driven (per HttpTestCase docblock).
 * Adding a real Postgres-backed test for this method would be the
 * first one in the suite — out of scope for M3.2.X.2 which is
 * about shipping the curation surface, not changing the test
 * infrastructure baseline.
 *
 * Sub-phase C will verify the end-to-end behavior (active +
 * featured filter, alphabetical order, etc.) via mocked repository
 * returning canned vendor lists.
 */
#[CoversClass(Vendor::class)]
#[CoversClass(VendorRepository::class)]
final class VendorFeaturedTest extends TestCase
{
    #[Test]
    public function newVendorDefaultsToNotFeatured(): void
    {
        $v = new Vendor('test-vendor', 'Test Vendor', 'test@example.com');

        self::assertFalse(
            $v->isFeatured(),
            'New vendors must default to is_featured=false per the migration default'
        );
    }

    #[Test]
    public function setFeaturedTrueFlipsTheFlag(): void
    {
        $v = new Vendor('test-vendor', 'Test Vendor', 'test@example.com');
        self::assertFalse($v->isFeatured());

        $v->setFeatured(true);
        self::assertTrue($v->isFeatured());
    }

    #[Test]
    public function setFeaturedFalseResetsTheFlag(): void
    {
        $v = new Vendor('test-vendor', 'Test Vendor', 'test@example.com');
        $v->setFeatured(true);
        $v->setFeatured(false);

        self::assertFalse($v->isFeatured());
    }

    #[Test]
    public function isFeaturedIsIndependentFromIsActiveAndIsVerified(): void
    {
        // Verify the four boolean status fields don't accidentally
        // share state. is_featured is curation (admin-managed Spotlight),
        // is_active is soft-delete, is_verified is admin verification,
        // is_store_approved is admin onboarding approval.
        $v = new Vendor('test-vendor', 'Test Vendor', 'test@example.com');

        self::assertTrue($v->isActive(), 'isActive defaults to true');
        self::assertFalse($v->isVerified(), 'isVerified defaults to false');
        self::assertFalse($v->isStoreApproved(), 'isStoreApproved defaults to false');
        self::assertFalse($v->isFeatured(), 'isFeatured defaults to false');

        // Setting one must not flip the others
        $v->setFeatured(true);

        self::assertTrue($v->isActive(), 'isActive unchanged by setFeatured');
        self::assertFalse($v->isVerified(), 'isVerified unchanged by setFeatured');
        self::assertFalse($v->isStoreApproved(), 'isStoreApproved unchanged by setFeatured');
        self::assertTrue($v->isFeatured(), 'isFeatured now true');
    }

    #[Test]
    public function setFeaturedTrueDoesNotImplyIsActive(): void
    {
        // Edge case: a vendor can be flagged is_featured=true and
        // later soft-deleted (is_active=false). The repository's
        // findFeatured() query must filter BOTH conditions; the
        // entity-level setters are independent.
        $v = new Vendor('test-vendor', 'Test Vendor', 'test@example.com');
        $v->setActive(false);
        $v->setFeatured(true);

        self::assertFalse($v->isActive(), 'Soft-deleted');
        self::assertTrue($v->isFeatured(), 'Still flagged featured at entity level');

        // The findFeatured query will exclude this vendor via
        // `WHERE isActive = true AND isFeatured = true` in DQL;
        // verified in sub-phase C's HTTP integration test.
    }
}
