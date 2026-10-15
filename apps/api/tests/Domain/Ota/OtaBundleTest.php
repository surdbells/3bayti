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
     * Active bundle 1.7.3 needing native 1.7 vs a candidate upload.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'lower version, same shells: never served' => ['1.7.2.1', '1.7', true];
        yield 'equal version: never served' => ['1.7.3', '1.7', true];
        yield 'lower version, needs an even newer shell: never served' => ['1.7.2', '1.8', true];
        yield 'higher version: served' => ['1.7.4', '1.7', false];
        // An older-shell hotfix: shells below 1.7 can't run 1.7.3, so they get it.
        yield 'lower version for older shells: served to them' => ['1.6.9', '1.6', false];
    }

    #[Test]
    #[DataProvider('candidates')]
    public function shadowsOnlyWhatCouldNeverBeServed(string $version, string $minNative, bool $shadowed): void
    {
        $active = new OtaBundle('com.threebayti.app', 'android', 'production', '1.7.3', 'https://x/1.7.3.zip', 'c', '1.7');

        self::assertSame($shadowed, $active->shadows($version, $minNative));
    }

    #[Test]
    public function anInactiveBundleShadowsNothing(): void
    {
        $inactive = new OtaBundle('com.threebayti.app', 'android', 'production', '1.7.3', 'https://x/1.7.3.zip', 'c', '1.7');
        $inactive->deactivate();

        self::assertFalse($inactive->shadows('1.7.2', '1.7'));
    }
}
