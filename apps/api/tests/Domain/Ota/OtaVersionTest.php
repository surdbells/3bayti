<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Ota;

use Bayti\Api\Domain\Ota\OtaVersion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtaVersion::class)]
final class OtaVersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function comparisons(): iterable
    {
        yield 'equal' => ['1.6.8', '1.6.8', 0];
        yield 'numeric not lexical' => ['1.6.10', '1.6.9', 1];
        yield 'missing segment is zero' => ['1.6', '1.6.0', 0];
        yield 'older' => ['1.6.3', '1.6.8', -1];
        yield 'pre-release ignored' => ['1.6.8-beta', '1.6.8', 0];
    }

    #[Test]
    #[DataProvider('comparisons')]
    public function comparesNumerically(string $a, string $b, int $sign): void
    {
        self::assertSame($sign, OtaVersion::compare($a, $b) <=> 0);
    }

    #[Test]
    public function maxPicksTheHighestSemverNotTheLastString(): void
    {
        self::assertSame('1.6.10', OtaVersion::max(['1.6.9', '1.6.10', '1.6.2']));
        self::assertNull(OtaVersion::max([]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nextVersions(): iterable
    {
        yield 'patch bump' => ['1.6.8', '1.6.9'];
        yield 'pads a two-part version' => ['1.6', '1.6.1'];
        yield 'bumps the last of four parts' => ['1.0.0.4', '1.0.0.5'];
        yield 'rolls past nine' => ['1.6.9', '1.6.10'];
    }

    #[Test]
    #[DataProvider('nextVersions')]
    public function nextIsStrictlyGreater(string $version, string $expected): void
    {
        $next = OtaVersion::next($version);
        self::assertSame($expected, $next);
        self::assertGreaterThan(0, OtaVersion::compare($next, $version));
    }
}
