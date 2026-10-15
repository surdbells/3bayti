<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Ota;

use Bayti\Api\Domain\Ota\OtaSigning;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtaSigning::class)]
final class OtaSigningTest extends TestCase
{
    /** A real-shaped ivSessionKey: base64(16-byte IV) ":" base64(256-byte RSA-2048 block). */
    public static function sessionKey(): string
    {
        return base64_encode(str_repeat("\x01", 16)) . ':' . base64_encode(str_repeat("\x02", 256));
    }

    /**
     * @return iterable<string, array{list<string>, string, bool}>
     */
    public static function unsignedCases(): iterable
    {
        yield 'android, non-production (dev builds)' => [['android'], 'beta', true];
        yield 'android, production' => [['android'], 'production', false];
        yield 'ios, non-production (iOS can never install it)' => [['ios'], 'beta', false];
        yield 'ios, production' => [['ios'], 'production', false];
        yield 'both, non-production' => [['android', 'ios'], 'beta', false];
    }

    /**
     * @param list<string> $platforms
     */
    #[Test]
    #[DataProvider('unsignedCases')]
    public function unsignedIsOnlyForAndroidOffProduction(array $platforms, string $channel, bool $allowed): void
    {
        self::assertSame($allowed, OtaSigning::unsignedError($platforms, $channel) === null);
    }

    #[Test]
    public function unsignedErrorsSayWhy(): void
    {
        self::assertStringContainsString('iOS only installs SIGNED', (string) OtaSigning::unsignedError(['ios'], 'beta'));
        self::assertStringContainsString('Production bundles must be SIGNED', (string) OtaSigning::unsignedError(['android'], 'production'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function sessionKeys(): iterable
    {
        yield 'bundle encrypt output (RSA-2048)' => [self::sessionKey(), true];
        yield 'placeholder' => ['iv:key', false];
        yield 'truncated key' => [substr(self::sessionKey(), 0, -10), false];
        yield 'missing iv' => [':' . base64_encode(str_repeat("\x02", 256)), false];
        yield 'RSA-4096 key block' => [base64_encode(str_repeat("\x01", 16)) . ':' . base64_encode(str_repeat("\x02", 512)), false];
        yield 'url-decoded plus as space' => [str_replace('+', ' ', base64_encode(str_repeat("\xfb", 16)) . ':' . base64_encode(str_repeat("\xfb", 256))), false];
        yield 'trailing newline' => [self::sessionKey() . "\n", false];
    }

    #[Test]
    #[DataProvider('sessionKeys')]
    public function sessionKeyMustHaveTheIvSessionKeyShape(string $key, bool $valid): void
    {
        self::assertSame($valid, OtaSigning::sessionKeyError($key) === null);
    }

    #[Test]
    public function recognisesThePlainZipHeaders(): void
    {
        self::assertTrue(OtaSigning::looksLikePlainZip("PK\x03\x04rest"));
        self::assertTrue(OtaSigning::looksLikePlainZip("PK\x05\x06"));
        self::assertFalse(OtaSigning::looksLikePlainZip("\x8a\x11ciphertext"));
        self::assertFalse(OtaSigning::looksLikePlainZip(''));
    }
}
