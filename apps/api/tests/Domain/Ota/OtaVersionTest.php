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
        // Device-reported values are parsed leniently (stored ones never carry a
        // suffix, isValid() rejects it on every write path).
        yield 'device suffix dropped' => ['1.6.8-beta', '1.6.8', 0];
        yield 'non-numeric device version is oldest' => ['builtin', '0.0.1', -1];
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
     * @return iterable<string, array{string, bool}>
     */
    public static function validity(): iterable
    {
        yield 'one segment' => ['1', true];
        yield 'three segments' => ['1.6.8', true];
        yield 'four segments' => ['1.0.0.4', true];
        yield 'five segments' => ['1.0.0.0.4', false];
        yield 'pre-release suffix' => ['1.7.0-beta.1', false];
        yield 'build metadata' => ['1.7.0+45', false];
        yield 'empty' => ['', false];
        yield 'leading v' => ['v1.7', false];
        yield 'trailing dot' => ['1.7.', false];
        yield 'zero segments' => ['0.0.0', true];
        yield 'nine-digit segment' => ['1.999999999', true];
        // Canonical only: "1.08" would compare equal to "1.8" while passing a
        // string-based duplicate guard.
        yield 'leading zero' => ['1.08', false];
        yield 'leading zero major' => ['01.8', false];
        // Bounded: no integer overflow in compare(), no 64-char column overflow.
        yield 'ten-digit segment' => ['1.1000000000', false];
        yield 'seventy digits' => [str_repeat('9', 70), false];
        // `$` would have matched before a trailing newline.
        yield 'trailing newline' => ["1.8\n", false];
    }

    #[Test]
    #[DataProvider('validity')]
    public function acceptsOnlyNumericVersions(string $version, bool $valid): void
    {
        self::assertSame($valid, OtaVersion::isValid($version));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function comparability(): iterable
    {
        yield 'store build' => ['1.8', true];
        yield 'leading zero tolerated for device builds' => ['1.08', true];
        yield 'four segments' => ['1.8.0.1', true];
        yield 'empty' => ['', false];
        yield 'word' => ['unknown', false];
        yield 'suffix' => ['1.8-beta', false];
        yield 'too long a segment' => ['1.1000000000', false];
        yield 'trailing newline' => ["1.8\n", false];
    }

    #[Test]
    #[DataProvider('comparability')]
    public function deviceBuildsMustBeReliablyComparable(string $version, bool $comparable): void
    {
        self::assertSame($comparable, OtaVersion::isComparable($version));
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

    /**
     * [min, max (already defaulted to min by the caller)] → expected error
     * fragment, or null when the range is accepted.
     *
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function nativeRanges(): iterable
    {
        yield 'single version' => ['1.8', '1.8', null];
        yield 'range' => ['1.8', '1.9', null];
        // "1.8" and "1.8.0" are the same version (missing segments are 0).
        yield 'equal after padding' => ['1.8', '1.8.0', null];
        yield 'zero min with a real max' => ['0.0.0', '1.8', null];
        yield 'min missing' => ['', '', 'min_native is required'];
        yield 'min malformed' => ['1.8-beta', '1.8-beta', 'min_native must be numeric'];
        yield 'min non-canonical' => ['1.08', '1.08', 'min_native must be numeric'];
        yield 'max malformed' => ['1.8', 'v1.9', 'max_native must be numeric'];
        yield 'max below min' => ['1.9', '1.8.5', 'max_native (1.8.5) must not be lower than min_native (1.9)'];
        // The old portal / release-script default: serves no real device.
        yield 'all zero' => ['0.0.0', '0.0.0', 'matches no installed app'];
        yield 'all zero, short' => ['0', '0', 'matches no installed app'];
    }

    #[Test]
    #[DataProvider('nativeRanges')]
    public function validatesANewBundlesNativeRange(string $min, string $max, ?string $error): void
    {
        $actual = OtaVersion::nativeRangeError($min, $max);
        if ($error === null) {
            self::assertNull($actual);
        } else {
            self::assertNotNull($actual);
            self::assertStringContainsString($error, $actual);
        }
    }

    #[Test]
    public function nativeRangeErrorsNameTheCallersInputs(): void
    {
        self::assertStringContainsString('--min-native is required', (string) OtaVersion::nativeRangeError('', '', '--min-native', '--max-native'));
        self::assertStringContainsString('--max-native (1.7)', (string) OtaVersion::nativeRangeError('1.8', '1.7', '--min-native', '--max-native'));
    }

    #[Test]
    public function zeroIsZeroInAnyPadding(): void
    {
        self::assertTrue(OtaVersion::isZero('0'));
        self::assertTrue(OtaVersion::isZero('0.0.0'));
        self::assertTrue(OtaVersion::isZero('0.0.0.0'));
        self::assertFalse(OtaVersion::isZero('0.0.1'));
        self::assertFalse(OtaVersion::isZero('1.8'));
    }

    #[Test]
    public function labelsANativeRange(): void
    {
        self::assertSame('1.8', OtaVersion::rangeLabel('1.8', '1.8'));
        self::assertSame('1.8', OtaVersion::rangeLabel('1.8', '1.8.0'));
        self::assertSame('1.8 - 1.9', OtaVersion::rangeLabel('1.8', '1.9'));
    }
}
