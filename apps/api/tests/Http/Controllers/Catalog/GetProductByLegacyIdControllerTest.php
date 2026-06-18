<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Catalog\GetProductByLegacyIdController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Behavioural coverage for the M3.1.5a legacy-id product lookup.
 *
 * Each test stubs the EntityManager to return a controlled
 * ProductRepository + a controlled ProductReview QueryBuilder. We
 * don't exercise the real DB; the contract under test is:
 *   - URL path → controller resolves legacy id correctly
 *   - findByLegacyId is called with the parsed int
 *   - Inactive / not-found / non-numeric → 404
 *   - On success → 200 with the detail-shape envelope
 */
#[CoversClass(GetProductByLegacyIdController::class)]
final class GetProductByLegacyIdControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsProductWhenLegacyIdMatchesActiveProduct(): void
    {
        $vendor = new Vendor('almas-fashion', 'Almas Fashion', 'almas@example.test');
        $product = new Product($vendor, 'silk-abaya', 'Silk Abaya');
        $product->setLegacyProductId(123);
        $product->setStatus(Product::STATUS_ACTIVE);
        // Made-to-measure: the detail shape must surface the requirement +
        // instructions so the PDP can collect the customer's measurement.
        $product->setRequiresExtraMsmt(true);
        $product->setExtraMsmt('Provide bust, waist and length in cm.');

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findByLegacyId')
            ->with(123)
            ->willReturn($product);

        $reviewRepo = $this->stubEmptyReviewQuery();

        $em = $this->stubEm(function ($em) use ($productRepo, $reviewRepo) {
            $em->method('getRepository')->willReturnMap([
                [Product::class, $productRepo],
                [ProductReview::class, $reviewRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products/by-legacy-id/123'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertArrayHasKey('data', $body);
        self::assertSame('Silk Abaya', $body['data']['name']);
        self::assertSame('silk-abaya', $body['data']['slug']);
        self::assertTrue($body['data']['requires_measurement']);
        self::assertSame('Provide bust, waist and length in cm.', $body['data']['measurement_instructions']);
    }

    #[Test]
    public function returns404WhenLegacyIdNotFound(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findByLegacyId')
            ->with(999)
            ->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products/by-legacy-id/999'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenProductIsInactive(): void
    {
        $vendor = new Vendor('test-vendor', 'Test Vendor', 't@example.test');
        $product = new Product($vendor, 'draft-item', 'Draft Item');
        $product->setLegacyProductId(50);
        // Default status is DRAFT → isActive() is false; no setStatus call.

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findByLegacyId')->willReturn($product);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products/by-legacy-id/50'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenIdPathSegmentIsNonNumeric(): void
    {
        // No EM stubbing — the controller should reject before any
        // repo lookup. The route's {id} placeholder is unconstrained
        // by Slim, so we validate in the controller.
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products/by-legacy-id/abc'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Build a ProductReview repository whose QueryBuilder chain returns
     * an empty review list. The chain mirrors what
     * GetProductByLegacyIdController invokes:
     *   ->createQueryBuilder('r')->where(...)->andWhere(...)
     *   ->setParameter(...)->setParameter(...)
     *   ->orderBy(...)->setMaxResults(...)
     *   ->getQuery()->getResult()
     */
    private function stubEmptyReviewQuery(): EntityRepository
    {
        // Note: QueryBuilder::getQuery() declares return type Query
        // (not AbstractQuery), so the mock must extend the concrete
        // Query class to satisfy PHPUnit's strict return-type check.
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('orderBy')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('createQueryBuilder')->willReturn($qb);
        return $repo;
    }
}
