<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "genuine sale" rule: a product is on sale only when its sale price is
 * present, strictly positive, and strictly below the regular price. A 0 (which
 * some vendors typed to mean "no sale") must NOT count as discounted — it was
 * surfacing products in the discounted section without a real markdown.
 */
#[CoversClass(Product::class)]
final class ProductSalePriceTest extends TestCase
{
    private function product(string $price): Product
    {
        $vendor = new Vendor('v', 'V', 'v@example.com');
        $p = new Product($vendor, 'p', 'P');
        $p->setPrice($price);
        return $p;
    }

    /**
     * @return array<string, array{0: ?string, 1: bool}>
     */
    public static function saleCases(): array
    {
        // price is fixed at 100.00 in the test body.
        return [
            'no sale price (null)'      => [null, false],
            'zero is not a sale'        => ['0.00', false],
            'zero unformatted'          => ['0', false],
            'negative is not a sale'    => ['-5.00', false],
            'equal to price'            => ['100.00', false],
            'above price'               => ['150.00', false],
            'genuine markdown'          => ['80.00', true],
            'one cent below'            => ['99.99', true],
        ];
    }

    #[Test]
    #[DataProvider('saleCases')]
    public function isOnSaleOnlyForAGenuineMarkdown(?string $salePrice, bool $expected): void
    {
        $p = $this->product('100.00');
        $p->setSalePrice($salePrice);
        self::assertSame($expected, $p->isOnSale());
    }

    #[Test]
    public function effectivePriceUsesSaleOnlyWhenGenuine(): void
    {
        $p = $this->product('100.00');

        self::assertSame('100.00', $p->effectivePrice(), 'no sale yet');

        $p->setSalePrice('0.00');
        self::assertSame('100.00', $p->effectivePrice(), 'zero is not a discount');

        $p->setSalePrice('80.00');
        self::assertSame('80.00', $p->effectivePrice(), 'genuine markdown charged');

        $p->setSalePrice('150.00');
        self::assertSame('100.00', $p->effectivePrice(), 'sale above price ignored');
    }

    /**
     * setSalePrice normalises non-positive input to NULL at the source, so 0
     * and blank are equivalent for every downstream reader.
     */
    #[Test]
    #[DataProvider('normalisationCases')]
    public function setSalePriceNormalisesNonPositiveToNull(?string $input, ?string $expected): void
    {
        $p = $this->product('100.00');
        $p->setSalePrice($input);
        self::assertSame($expected, $p->getSalePrice());
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function normalisationCases(): array
    {
        return [
            'null stays null'       => [null, null],
            'zero → null'           => ['0.00', null],
            'zero unformatted'      => ['0', null],
            'negative → null'       => ['-1.00', null],
            'non-numeric → null'    => ['abc', null],
            'positive kept'         => ['80.00', '80.00'],
        ];
    }
}
