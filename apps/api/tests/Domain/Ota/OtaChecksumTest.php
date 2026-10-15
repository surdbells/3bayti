<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Ota;

use Bayti\Api\Domain\Ota\OtaChecksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtaChecksum::class)]
final class OtaChecksumTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function cases(): iterable
    {
        $sha = hash('sha256', 'bundle');
        yield 'plain sha256, unsigned' => [$sha, false, true];
        yield 'plain sha256 uppercase, unsigned' => [strtoupper($sha), false, true];
        yield 'encrypted checksum on an unsigned bundle' => [str_repeat('ab', 256), false, false];
        yield 'short, unsigned' => ['abc', false, false];
        yield 'RSA-2048 encrypted, signed' => [str_repeat('ab', 256), true, true];
        yield 'RSA-2048 encrypted uppercase, signed' => [str_repeat('AB', 256), true, true];
        // The plugin accepts exactly 256 bytes (CryptoCipher.decryptChecksum).
        yield 'RSA-4096 sized, signed' => [str_repeat('ab', 512), true, false];
        yield 'RSA-1024 sized, signed' => [str_repeat('ab', 128), true, false];
        yield 'truncated paste (511), signed' => [str_repeat('a', 511), true, false];
        yield 'truncated paste (300), signed' => [str_repeat('a', 300), true, false];
        yield 'trailing newline, signed' => [str_repeat('ab', 256) . "\n", true, false];
        yield 'plain sha256 on a signed bundle' => [$sha, true, false];
        yield 'base64 (pre-7.30 updater) on a signed bundle' => [base64_encode(random_bytes(256)), true, false];
        yield 'empty, signed' => ['', true, false];
    }

    #[Test]
    #[DataProvider('cases')]
    public function validates(string $checksum, bool $signed, bool $valid): void
    {
        self::assertSame($valid, OtaChecksum::validate($checksum, $signed) === null);
    }

    #[Test]
    public function thePlainChecksumOnASignedBundleGetsAPointedMessage(): void
    {
        self::assertStringContainsString('PLAIN sha256', (string) OtaChecksum::validate(hash('sha256', 'x'), true));
    }

    #[Test]
    public function aWrongLengthSignedChecksumReportsTheLength(): void
    {
        self::assertStringContainsString('got 511 chars', (string) OtaChecksum::validate(str_repeat('a', 511), true));
    }
}
