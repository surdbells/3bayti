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
 * Catalog fix #7: ListProductsController previously omitted the sizes +
 * colors refinement keys from the $filters it forwarded to
 * findActivePaginated, so ?colors=black / ?sizes=M were silently ignored
 * even though the repository already filters on them (JSONB_EXISTS_ANY).
 *
 * These tests assert the controller now parses the refinement params
 * (CSV or array form, same as the facets endpoint) and forwards them.
 */
#[CoversClass(ListProductsController::class)]
final class ListProductsControllerRefinementTest extends HttpTestCase
{
    #[Test]
    public function forwardsColorsFilter(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) =>
                ($f['colors'] ?? null) === ['black']))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?colors=black'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function forwardsCsvColorsAndSizesAsLists(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) =>
                ($f['colors'] ?? null) === ['black', 'white']
                && ($f['sizes'] ?? null) === ['S', 'M']))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?colors=black,white&sizes=S,M'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function nullifiesRefinementsWhenAbsent(): void
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) =>
                array_key_exists('colors', $f) && $f['colors'] === null
                && array_key_exists('sizes', $f) && $f['sizes'] === null))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Product::class)->willReturn($productRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products'),
        );

        self::assertSame(200, $response->getStatusCode());
    }
}
