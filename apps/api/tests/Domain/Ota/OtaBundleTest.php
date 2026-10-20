<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtaBundle::class)]
final class OtaBundleTest extends TestCase
{
    /**
     * Active bundle 1.7.3 for native 1.7 - 1.8 vs a candidate upload.
     *
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'lower version, same range: never served' => ['1.7.2.1', '1.7', '1.8', true];
        yield 'lower version, narrower range inside: never served' => ['1.7.2', '1.7.5', '1.8', true];
        yield 'lower version, single version inside: never served' => ['1.7.2', '1.8', '1.8', true];
        yield 'equal version: never served' => ['1.7.3', '1.7', '1.7', true];
        yield 'higher version: served' => ['1.7.4', '1.7', '1.8', false];
        // An older-shell hotfix: shells below 1.7 can't run 1.7.3, so they get it.
        yield 'lower version for older shells: served to them' => ['1.6.9', '1.6', '1.6', false];
        yield 'lower version, range starts below min: served to the older shells' => ['1.6.9', '1.6', '1.7', false];
        // A bundle for a newer test shell (TestFlight 1.8.1): 1.7.3 stops at 1.8.
        yield 'lower version for a newer shell above max: served to it' => ['1.7.0', '1.8.1', '1.8.1', false];
        yield 'lower version, range ends above max: served to the newer shells' => ['1.7.0', '1.8', '1.9', false];
    }

    #[Test]
    #[DataProvider('candidates')]
    public function shadowsOnlyWhatCouldNeverBeServed(string $version, string $minNative, string $maxNative, bool $shadowed): void
    {
        $active = $this->bundle('1.7.3', '1.7', '1.8');

        self::assertSame($shadowed, $active->shadows($version, $minNative, $maxNative));
    }

    #[Test]
    public function anExactVersionBundleDoesNotShadowATestShellBundle(): void
    {
        // Production 1.7.3 built for shell 1.8 (max defaults to min); a lower
        // web version published for TestFlight 1.8.1 still reaches 1.8.1.
        $production = $this->bundle('1.7.3', '1.8');

        self::assertFalse($production->shadows('1.7.0', '1.8.1', '1.8.1'));
        self::assertTrue($production->shadows('1.7.0', '1.8', '1.8.0'), '"1.8.0" is the same native version as "1.8"');
    }

    #[Test]
    public function anInactiveBundleShadowsNothing(): void
    {
        $inactive = $this->bundle('1.7.3', '1.7', '1.8');
        $inactive->deactivate();

        self::assertFalse($inactive->shadows('1.7.2', '1.7', '1.7'));
    }

    #[Test]
    public function maxDefaultsToMinAndIsStoredExplicitly(): void
    {
        $bundle = $this->bundle('1.7.3', '1.8');

        self::assertSame('1.8', $bundle->getMinNativeVersion());
        self::assertSame('1.8', $bundle->getMaxNativeVersion());
        self::assertSame('1.8', $bundle->getEffectiveMaxNativeVersion());
        self::assertSame('1.8', $this->bundle('1.7.3', '1.8', '')->getMaxNativeVersion(), 'empty max = min');
        self::assertSame('1.9', $this->bundle('1.7.3', '1.8', '1.9')->getMaxNativeVersion());
    }

    #[Test]
    public function aMaxBelowTheMinIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->bundle('1.7.3', '1.9', '1.8');
    }

    #[Test]
    public function aLegacyRowWithoutAMaxBehavesAsMaxEqualsMin(): void
    {
        $legacy = $this->legacy('1.7.3', '1.7');

        self::assertNull($legacy->getMaxNativeVersion());
        self::assertSame('1.7', $legacy->getEffectiveMaxNativeVersion());
        self::assertTrue($legacy->supportsNative('1.7'));
        self::assertFalse($legacy->supportsNative('1.8'), 'a legacy min-only row no longer reaches newer shells');
        self::assertSame('1.7', $legacy->nativeRangeLabel());
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function nativeBuilds(): iterable
    {
        yield 'exact: the shell it was built for' => ['1.8', '1.8', '1.8', true];
        yield 'exact: same version, padded' => ['1.8', '1.8', '1.8.0', true];
        yield 'exact: newer TestFlight shell' => ['1.8', '1.8', '1.8.1', false];
        yield 'exact: next minor' => ['1.8', '1.8', '1.9', false];
        yield 'exact: older shell' => ['1.8', '1.8', '1.7.9', false];
        yield 'range: lower end inclusive' => ['1.8', '1.9', '1.8', true];
        yield 'range: inside' => ['1.8', '1.9', '1.8.5', true];
        yield 'range: upper end inclusive' => ['1.8', '1.9', '1.9', true];
        yield 'range: upper end, padded' => ['1.8', '1.9', '1.9.0.0', true];
        yield 'range: above' => ['1.8', '1.9', '1.9.1', false];
        yield 'range: below' => ['1.8', '1.9', '1.7', false];
    }

    #[Test]
    #[DataProvider('nativeBuilds')]
    public function supportsOnlyNativeVersionsInsideItsRange(string $min, string $max, string $device, bool $supported): void
    {
        self::assertSame($supported, $this->bundle('1.7.3', $min, $max)->supportsNative($device));
    }

    #[Test]
    public function aRollbackKeepsTheSourcesNativeRange(): void
    {
        $source = $this->bundle('1.6.3', '1.8', '1.9');

        $rollback = OtaBundle::rollbackOf($source, '1.6.9');

        self::assertSame('1.8', $rollback->getMinNativeVersion());
        self::assertSame('1.9', $rollback->getMaxNativeVersion());
    }

    #[Test]
    public function aRollbackOfALegacyRowStoresItsEffectiveRange(): void
    {
        $rollback = OtaBundle::rollbackOf($this->legacy('1.6.3', '1.7'), '1.6.9');

        self::assertSame('1.7', $rollback->getMinNativeVersion());
        self::assertSame('1.7', $rollback->getMaxNativeVersion(), 'stored explicitly, not left null');
    }

    /**
     * Bundle A [1.8 - 1.9] vs bundle B's range.
     *
     * @return iterable<string, array{string, ?string, bool}>
     */
    public static function overlaps(): iterable
    {
        yield 'same range' => ['1.8', '1.9', true];
        yield 'inside' => ['1.8.5', null, true];
        yield 'touches the lower end' => ['1.7', '1.8', true];
        yield 'touches the upper end' => ['1.9', '2.0', true];
        yield 'touches the upper end, padded' => ['1.9.0', null, true];
        yield 'covers it' => ['1.0', '3.0', true];
        yield 'partly below' => ['1.7', '1.8.3', true];
        yield 'entirely below' => ['1.7', '1.7.9', false];
        yield 'entirely above (test shell)' => ['1.9.1', null, false];
        yield 'legacy-style 0.0.0' => ['0.0.0', null, false];
    }

    #[Test]
    #[DataProvider('overlaps')]
    public function nativeRangesOverlapWhenTheyShareAVersion(string $min, ?string $max, bool $overlap): void
    {
        $a = $this->bundle('1.7.3', '1.8', '1.9');
        $b = $this->bundle('1.7.4', $min, $max);

        self::assertSame($overlap, $a->nativeRangeOverlaps($b));
        self::assertSame($overlap, $b->nativeRangeOverlaps($a), 'symmetric');
    }

    #[Test]
    public function aLegacyRowOverlapsOnlyAtItsMin(): void
    {
        $legacy = $this->legacy('1.7.3', '1.7');

        self::assertTrue($legacy->nativeRangeOverlaps($this->bundle('1.7.4', '1.6', '1.7')));
        self::assertFalse($legacy->nativeRangeOverlaps($this->bundle('1.7.4', '1.7.1', '1.9')));
    }

    #[Test]
    public function aRangeEndingAtZeroReachesNoShell(): void
    {
        self::assertTrue($this->legacy('1.6.3', '0.0.0')->reachesNoShell(), 'old 0.0.0 min default, no max');
        self::assertTrue($this->legacy('1.6.3', '0')->reachesNoShell());
        self::assertTrue($this->bundle('1.6.3', '0.0.0', '0')->reachesNoShell());
        self::assertFalse($this->bundle('1.6.3', '0.0.0', '1.8')->reachesNoShell(), 'widened by the operator');
        self::assertFalse($this->bundle('1.6.3', '1.8')->reachesNoShell());
        self::assertFalse($this->legacy('1.6.3', '1.6.0')->reachesNoShell(), 'narrow, but a 1.6.0 shell can still run it');
    }

    #[Test]
    public function labelsItsNativeRange(): void
    {
        self::assertSame('1.8', $this->bundle('1.7.3', '1.8')->nativeRangeLabel());
        self::assertSame('1.8 - 1.9', $this->bundle('1.7.3', '1.8', '1.9')->nativeRangeLabel());
    }

    private function bundle(string $version, string $min, ?string $max = null): OtaBundle
    {
        return new OtaBundle(
            'com.threebayti.app',
            'android',
            'production',
            $version,
            "https://x/{$version}.zip",
            'c',
            $min,
            maxNativeVersion: $max,
        );
    }

    /** A row as published before max_native_version existed (column NULL). */
    private function legacy(string $version, string $min): OtaBundle
    {
        $bundle = $this->bundle($version, $min);
        (new \ReflectionProperty(OtaBundle::class, 'maxNativeVersion'))->setValue($bundle, null);

        return $bundle;
    }
}
