<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Controllers\Catalog\ListProductsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Focused tests for the M3.1.5.5d search support in
 * ListProductsController: the `q` query param + `sort=relevance`.
 *
 * The actual tsvector matching happens at the PostgreSQL layer
 * (TSMATCH/TSRANK DQL functions translate to `@@` + `ts_rank`).
 * These tests verify the controller correctly:
 *
 *   - Parses `q` from the query string and forwards to the
 *     repository as `searchQuery`
 *   - Trims and rejects empty/whitespace `q` values
 *   - Caps `q` length at 200 chars
 *   - Accepts `sort=relevance` (was rejected pre-this-commit)
 *
 * Database-level relevance ranking behaviour is verified at deploy
 * time against the staging DB (the device-test checklist covers it).
 */
#[CoversClass(ListProductsController::class)]
final class ListProductsControllerSearchTest extends HttpTestCase
{
    #[Test]
    public function forwardsQueryParamAsSearchQueryFilter(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['searchQuery'] === 'silk abaya'))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?q=silk%20abaya'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function trimsLeadingTrailingWhitespaceInSearchQuery(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['searchQuery'] === 'abaya'))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?q=%20%20abaya%20%20'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function nullifiesEmptySearchQuery(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['searchQuery'] === null))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        // q=<empty> should be treated as no search.
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?q='),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function nullifiesWhitespaceOnlySearchQuery(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['searchQuery'] === null))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?q=%20%20%20'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function capsSearchQueryAt200Characters(): void
    {
        $longInput = str_repeat('a', 250);
        $expectedTrimmed = str_repeat('a', 200);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) =>
                is_string($f['searchQuery']) && strlen($f['searchQuery']) === 200
                && $f['searchQuery'] === $expectedTrimmed))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?q=' . $longInput),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function acceptsRelevanceAsValidSortValue(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) =>
                $f['sort'] === 'relevance' && $f['searchQuery'] === 'abaya'))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?q=abaya&sort=relevance'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejectsUnknownSortValueAndFallsBackToNewest(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['sort'] === 'newest'))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?sort=bogus'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function searchCanCombineWithOtherFilters(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) =>
                $f['searchQuery'] === 'silk'
                && $f['isFeatured'] === true
                // parsePrice formats with 2 decimals via number_format.
                && $f['maxPrice'] === '500.00'))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?q=silk&featured=true&max_price=500'),
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
