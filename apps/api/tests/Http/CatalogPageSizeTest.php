<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http;

use Bayti\Api\Http\CatalogPageSize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The shared page-size rule for GET /v3/products and
 * GET /v3/vendors/{slug}/products.
 *
 *   - limit present (any value)      => max(1, min(100, (int) value)), the
 *                                       historical clamp, regardless of scope
 *   - limit absent + store-scoped    => 500 (whole store catalogue; the
 *                                       shipped mobile store page has no
 *                                       pagination)
 *   - limit absent + not store-scoped => 24 (unchanged default)
 */
#[CoversClass(CatalogPageSize::class)]
final class CatalogPageSizeTest extends TestCase
{
    #[Test]
    public function constantsMatchTheDocumentedRule(): void
    {
        self::assertSame(24, CatalogPageSize::DEFAULT);
        self::assertSame(100, CatalogPageSize::MAX);
        self::assertSame(500, CatalogPageSize::STORE_CATALOGUE_MAX);
    }

    #[Test]
    public function absentLimitOnAStoreScopedRequestIsTheWholeCatalogue(): void
    {
        self::assertSame(500, CatalogPageSize::resolve([], storeScoped: true));
        self::assertSame(500, CatalogPageSize::resolve(['offset' => '20', 'label' => 'eid'], storeScoped: true));
    }

    #[Test]
    public function absentLimitOnANonStoreRequestKeepsTheDefault(): void
    {
        self::assertSame(24, CatalogPageSize::resolve([], storeScoped: false));
        self::assertSame(24, CatalogPageSize::resolve(['category' => 'abayas', 'offset' => '48'], storeScoped: false));
    }

    #[Test]
    public function nullLimitCountsAsAbsentLikeTheHistoricalNullCoalesce(): void
    {
        // `$query['limit'] ?? 24` treated a null value as absent; a real query
        // string never yields null, but the rule keeps that semantic exactly.
        self::assertSame(24, CatalogPageSize::resolve(['limit' => null], storeScoped: false));
        self::assertSame(500, CatalogPageSize::resolve(['limit' => null], storeScoped: true));
    }

    /**
     * Explicit (present) limit values and what they resolve to. These are
     * exactly what `max(1, min(100, (int) ($query['limit'] ?? 24)))` returned
     * before the shared rule existed.
     *
     * @return iterable<string, array{0: mixed, 1: int}>
     */
    public static function explicitLimits(): iterable
    {
        yield 'empty string'        => ['', 1];
        yield 'garbage'             => ['abc', 1];
        yield 'zero'                => ['0', 1];
        yield 'negative'            => ['-5', 1];
        yield 'one'                 => ['1', 1];
        yield 'ten'                 => ['10', 10];
        yield 'default value'       => ['24', 24];
        yield 'max'                 => ['100', 100];
        yield 'just over max'       => ['101', 100];
        yield 'over max'            => ['250', 100];
        yield 'store catalogue max' => ['500', 100];
        yield 'decimal'             => ['10.7', 10];
        yield 'exponent'            => ['1e3', 100];
        yield 'leading digits'      => ['7abc', 7];
        yield 'leading space'       => [' 7', 7];
        yield 'int value'           => [12, 12];
        yield 'empty array'         => [[], 1];
        yield 'array value'         => [['5'], 1];
    }

    #[Test]
    #[DataProvider('explicitLimits')]
    public function explicitLimitIsClampedIdenticallyWhateverTheScope(mixed $value, int $expected): void
    {
        self::assertSame($expected, CatalogPageSize::resolve(['limit' => $value], storeScoped: false));
        self::assertSame($expected, CatalogPageSize::resolve(['limit' => $value], storeScoped: true));
    }

    #[Test]
    #[DataProvider('explicitLimits')]
    public function explicitLimitMatchesTheHistoricalExpressionExactly(mixed $value, int $expected): void
    {
        $query = ['limit' => $value];
        // The expression both controllers used before the shared rule.
        $historical = max(1, min(100, (int) ($query['limit'] ?? 24)));

        self::assertSame($historical, CatalogPageSize::resolve($query, storeScoped: false));
        self::assertSame($historical, CatalogPageSize::resolve($query, storeScoped: true));
    }
}
