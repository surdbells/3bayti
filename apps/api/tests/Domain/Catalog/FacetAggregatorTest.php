<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\FacetAggregator;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for FacetAggregator (M3.2.X.10-A).
 *
 * We mock Doctrine's Connection to verify:
 *   1. The right SQL is generated for each facet
 *   2. The right filter parameters are routed
 *   3. Disjunctive-facet behavior: the same-facet filter is dropped
 *      when counting that facet's values
 *   4. Result ordering matches Q-Ordering
 *   5. Per-facet cap (50) + 0-count suppression are applied
 *
 * Each Connection::executeQuery call captures the SQL string + params
 * via a callback, and returns a canned Result mock. The test then
 * inspects the captured SQL and asserts on shape.
 */
#[CoversClass(FacetAggregator::class)]
final class FacetAggregatorTest extends TestCase
{
    // =================================================================
    // Disjunctive-facet semantics
    // =================================================================

    #[Test]
    public function sizeFacetDropsSizesFilterWhenCounting(): void
    {
        $captured = $this->captureQueries([
            // size aggregation result
            [['value' => 'M', 'count' => '47']],
            // color result (unused for this assertion)
            [],
            // price
            [],
            // vendor
            [],
            // category
            [],
            // total
            ['80'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $aggregator = new FacetAggregator($em);
        $aggregator->compute([
            'sizes' => ['M'],         // user has size=M filter active
            'colors' => ['Black'],    // and color=Black filter
            'categoryId' => 5,
        ]);

        // First query is the size facet — its SQL must NOT reference :sizes
        $sizeQuery = $captured['queries'][0];
        self::assertStringNotContainsString(':sizes', $sizeQuery['sql']);
        self::assertArrayNotHasKey('sizes', $sizeQuery['params']);
        // ... but it MUST still respect the disjunctive color filter:
        self::assertStringContainsString(':colors', $sizeQuery['sql']);
        self::assertSame(['Black'], $sizeQuery['params']['colors']);
        // ... and the category filter (narrowing across):
        self::assertSame(5, $sizeQuery['params']['categoryId']);
    }

    #[Test]
    public function colorFacetDropsColorsFilterWhenCounting(): void
    {
        $captured = $this->captureQueries([
            [],                                                // size
            [['value' => 'Black', 'count' => '47']],           // color
            [],                                                // price
            [],                                                // vendor
            [],                                                // category
            ['80'],                                            // total
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $aggregator = new FacetAggregator($em);
        $aggregator->compute([
            'sizes' => ['M'],
            'colors' => ['Black'],
        ]);

        // color query (second) — must NOT have :colors param
        $colorQuery = $captured['queries'][1];
        self::assertStringNotContainsString(':colors', $colorQuery['sql']);
        self::assertArrayNotHasKey('colors', $colorQuery['params']);
        // ... but sizes filter still active:
        self::assertSame(['M'], $colorQuery['params']['sizes']);
    }

    #[Test]
    public function priceFacetDropsMinAndMaxPriceFilters(): void
    {
        $captured = $this->captureQueries([
            [],                                                // size
            [],                                                // color
            [['value' => '50-100', 'count' => '47']],          // price
            [],                                                // vendor
            [],                                                // category
            ['80'],                                            // total
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $aggregator = new FacetAggregator($em);
        $aggregator->compute([
            'minPrice' => '50',
            'maxPrice' => '200',
            'categoryId' => 5,
        ]);

        // price query (third) — must NOT carry minPrice or maxPrice
        $priceQuery = $captured['queries'][2];
        self::assertArrayNotHasKey('minPrice', $priceQuery['params']);
        self::assertArrayNotHasKey('maxPrice', $priceQuery['params']);
        // ... but other filters still applied:
        self::assertSame(5, $priceQuery['params']['categoryId']);
        // SQL must contain the CASE-WHEN band classification
        self::assertStringContainsString('CASE', $priceQuery['sql']);
    }

    #[Test]
    public function vendorFacetDropsVendorIdFilter(): void
    {
        $captured = $this->captureQueries([
            [], [], [],                                        // size/color/price
            [['value' => 'almas', 'label' => 'Almas', 'count' => '47']],  // vendor
            [],                                                // category
            ['80'],                                            // total
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $aggregator = new FacetAggregator($em);
        $aggregator->compute([
            'vendorId' => 5,
            'categoryId' => 10,
        ]);

        $vendorQuery = $captured['queries'][3];
        self::assertArrayNotHasKey('vendorId', $vendorQuery['params']);
        self::assertSame(10, $vendorQuery['params']['categoryId']);
        self::assertStringContainsString('JOIN vendors', $vendorQuery['sql']);
    }

    #[Test]
    public function categoryFacetDropsCategoryIdFilter(): void
    {
        $captured = $this->captureQueries([
            [], [], [], [],                                    // size/color/price/vendor
            [['value' => 'abayas', 'label' => 'Abayas', 'count' => '47']],  // category
            ['80'],                                            // total
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $aggregator = new FacetAggregator($em);
        $aggregator->compute([
            'vendorId' => 5,
            'categoryId' => 10,
        ]);

        $categoryQuery = $captured['queries'][4];
        self::assertArrayNotHasKey('categoryId', $categoryQuery['params']);
        self::assertSame(5, $categoryQuery['params']['vendorId']);
        self::assertStringContainsString('JOIN categories', $categoryQuery['sql']);
    }

    // =================================================================
    // Result shaping
    // =================================================================

    #[Test]
    public function sizeFacetSortsByCanonicalOrder(): void
    {
        // Aggregator returns these in count-DESC SQL order; we expect
        // the helper to re-sort by XS/S/M/L/XL/XXL/XXXL.
        $captured = $this->captureQueries([
            [
                ['value' => 'L', 'count' => '20'],
                ['value' => 'XS', 'count' => '5'],
                ['value' => 'M', 'count' => '40'],
                ['value' => 'XL', 'count' => '10'],
                ['value' => 'S', 'count' => '15'],
            ],
            [], [], [], [], ['100'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $result = (new FacetAggregator($em))->compute([]);

        $values = $result['size']['values'];
        self::assertSame('XS', $values[0]['value']);
        self::assertSame('S', $values[1]['value']);
        self::assertSame('M', $values[2]['value']);
        self::assertSame('L', $values[3]['value']);
        self::assertSame('XL', $values[4]['value']);
        self::assertSame(5, $result['size']['total_distinct']);
    }

    #[Test]
    public function unknownSizesSortAfterCanonicalAlphaAscending(): void
    {
        $captured = $this->captureQueries([
            [
                ['value' => 'M', 'count' => '40'],
                ['value' => '40', 'count' => '5'],     // numeric (shoe size)
                ['value' => '38', 'count' => '8'],     // numeric
                ['value' => 'custom-A', 'count' => '3'],
            ],
            [], [], [], [], ['56'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $result = (new FacetAggregator($em))->compute([]);

        $values = $result['size']['values'];
        self::assertSame('M', $values[0]['value']);     // canonical first
        // unknowns sorted naturally: 38 < 40 < custom-A
        self::assertSame('38', $values[1]['value']);
        self::assertSame('40', $values[2]['value']);
        self::assertSame('custom-A', $values[3]['value']);
    }

    #[Test]
    public function colorFacetSortsByCountDescThenAlpha(): void
    {
        // SQL already does ORDER BY count DESC, value ASC — verify we
        // pass through without reshuffling.
        $captured = $this->captureQueries([
            [],
            [
                ['value' => 'Black', 'count' => '47'],
                ['value' => 'White', 'count' => '47'],   // tie → alpha
                ['value' => 'Red',   'count' => '20'],
            ],
            [], [], [], ['100'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $result = (new FacetAggregator($em))->compute([]);

        $values = $result['color']['values'];
        self::assertSame('Black', $values[0]['value']);
        self::assertSame(47, $values[0]['count']);
        self::assertSame('White', $values[1]['value']);
        self::assertSame('Red', $values[2]['value']);
    }

    #[Test]
    public function priceFacetUsesFixedBandsInOrder(): void
    {
        $captured = $this->captureQueries([
            [], [],
            [
                ['value' => '500+', 'count' => '5'],
                ['value' => '0-50', 'count' => '40'],
                ['value' => '100-250', 'count' => '20'],
            ],
            [], [], ['100'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $result = (new FacetAggregator($em))->compute([]);

        $values = $result['price']['values'];
        // Bands present (50-100 + 250-500 had 0 count → suppressed)
        self::assertSame('0-50', $values[0]['value']);
        self::assertSame(40, $values[0]['count']);
        self::assertSame('0.00', $values[0]['min']);
        self::assertSame('50.00', $values[0]['max']);

        self::assertSame('100-250', $values[1]['value']);
        self::assertSame(20, $values[1]['count']);

        self::assertSame('500+', $values[2]['value']);
        self::assertNull($values[2]['max']);
    }

    #[Test]
    public function vendorFacetIncludesSlugAndLabel(): void
    {
        $captured = $this->captureQueries([
            [], [], [],
            [
                ['value' => 'almas', 'label' => 'Almas Fashion', 'count' => '47'],
                ['value' => 'noor', 'label' => 'Noor Boutique', 'count' => '12'],
            ],
            [], ['59'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $result = (new FacetAggregator($em))->compute([]);

        self::assertSame('almas', $result['vendor']['values'][0]['value']);
        self::assertSame('Almas Fashion', $result['vendor']['values'][0]['label']);
        self::assertSame(47, $result['vendor']['values'][0]['count']);
    }

    #[Test]
    public function emptyCountValuesAreSuppressed(): void
    {
        $captured = $this->captureQueries([
            [
                ['value' => 'M', 'count' => '5'],
                ['value' => 'S', 'count' => '0'],   // should be dropped
            ],
            [], [], [], [], ['5'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $result = (new FacetAggregator($em))->compute([]);

        self::assertCount(1, $result['size']['values']);
        self::assertSame('M', $result['size']['values'][0]['value']);
    }

    #[Test]
    public function perFacetCapAt50(): void
    {
        // Generate 60 distinct values; expect 50 returned.
        $colorRows = [];
        for ($i = 1; $i <= 60; $i++) {
            $colorRows[] = ['value' => 'Color' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'count' => (string) $i];
        }
        // Reverse so highest count first (matches SQL ORDER BY count DESC).
        $colorRows = array_reverse($colorRows);

        $captured = $this->captureQueries([
            [], $colorRows, [], [], [], ['1830'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $result = (new FacetAggregator($em))->compute([]);

        self::assertCount(50, $result['color']['values']);
        self::assertSame(60, $result['color']['total_distinct']);
    }

    // =================================================================
    // Filter routing
    // =================================================================

    #[Test]
    public function searchQueryFilterRoutedThroughTsvector(): void
    {
        $captured = $this->captureQueries([
            [], [], [], [], [], ['0'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        (new FacetAggregator($em))->compute([
            'searchQuery' => 'red dress',
        ]);

        $sizeQuery = $captured['queries'][0];
        self::assertStringContainsString('search_tsv @@ plainto_tsquery', $sizeQuery['sql']);
        self::assertSame('red dress', $sizeQuery['params']['searchQuery']);
    }

    #[Test]
    public function whitespaceOnlySearchQueryIsIgnored(): void
    {
        $captured = $this->captureQueries([
            [], [], [], [], [], ['0'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        (new FacetAggregator($em))->compute([
            'searchQuery' => '   ',
        ]);

        $sizeQuery = $captured['queries'][0];
        self::assertStringNotContainsString('search_tsv', $sizeQuery['sql']);
        self::assertArrayNotHasKey('searchQuery', $sizeQuery['params']);
    }

    #[Test]
    public function sizesAndColorsParamsUseArrayParameterType(): void
    {
        $captured = $this->captureQueries([
            [], [], [], [], [], ['0'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        (new FacetAggregator($em))->compute([
            'sizes' => ['S', 'M'],
            'colors' => ['Black'],
        ]);

        // Size query drops sizes filter (disjunctive) but keeps colors
        $sizeQuery = $captured['queries'][0];
        self::assertSame(ArrayParameterType::STRING, $sizeQuery['types']['colors']);
        // Color query drops colors filter but keeps sizes
        $colorQuery = $captured['queries'][1];
        self::assertSame(ArrayParameterType::STRING, $colorQuery['types']['sizes']);
    }

    #[Test]
    public function totalProductsRespectsAllFilters(): void
    {
        $captured = $this->captureQueries([
            [], [], [], [], [], ['42'],
        ]);

        $em = $this->emWithConnection($captured['connection']);
        $result = (new FacetAggregator($em))->compute([
            'sizes' => ['M'],
            'colors' => ['Black'],
            'categoryId' => 5,
        ]);

        // Total query is the 6th (last) — must apply EVERY filter
        $totalQuery = $captured['queries'][5];
        self::assertSame(['M'], $totalQuery['params']['sizes']);
        self::assertSame(['Black'], $totalQuery['params']['colors']);
        self::assertSame(5, $totalQuery['params']['categoryId']);
        self::assertSame(42, $result['total_products']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * Build a Connection mock whose executeQuery captures each call's
     * SQL + params + types and returns a Result mock yielding the
     * next canned row set.
     *
     * @param list<list<array<string, mixed>>|list<string>> $resultRows
     *        Each entry is either:
     *          - a list of associative rows (for fetchAllAssociative)
     *          - a single-element list with one scalar (for fetchOne)
     * @return array{connection: Connection, queries: list<array{sql: string, params: array<string, mixed>, types: array<string, int|string>}>}
     */
    private function captureQueries(array $resultRows): array
    {
        $queries = [];
        $callIndex = 0;

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params, array $types) use (&$queries, &$callIndex, $resultRows): Result {
                $queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
                $rowSet = $resultRows[$callIndex] ?? [];
                $callIndex++;

                $result = $this->createMock(Result::class);
                if ($rowSet === []) {
                    $result->method('fetchAllAssociative')->willReturn([]);
                    $result->method('fetchOne')->willReturn(false);
                    return $result;
                }
                // Heuristic: if the first row is a string (scalar),
                // treat as a single fetchOne result; otherwise rows.
                if (is_string($rowSet[0]) || is_int($rowSet[0])) {
                    $result->method('fetchAllAssociative')->willReturn([]);
                    $result->method('fetchOne')->willReturn($rowSet[0]);
                } else {
                    $result->method('fetchAllAssociative')->willReturn($rowSet);
                    $result->method('fetchOne')->willReturn(false);
                }
                return $result;
            },
        );

        return ['connection' => $connection, 'queries' => &$queries];
    }

    private function emWithConnection(Connection $connection): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        return $em;
    }
}
