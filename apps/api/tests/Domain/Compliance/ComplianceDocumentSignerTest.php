<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Compliance;

use Bayti\Api\Domain\Compliance\ComplianceDocumentSigner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComplianceDocumentSignerTest extends TestCase
{
    private function signer(): ComplianceDocumentSigner
    {
        return new ComplianceDocumentSigner(str_repeat('k', 32));
    }

    /** @return array{exp:int, sig:string} */
    private function parse(string $path): array
    {
        parse_str((string) parse_url($path, PHP_URL_QUERY), $q);

        return ['exp' => (int) ($q['exp'] ?? 0), 'sig' => (string) ($q['sig'] ?? '')];
    }

    #[Test]
    public function verifiesAFreshlySignedUrl(): void
    {
        $signer = $this->signer();
        $q = $this->parse($signer->signedPath(101, 'front'));

        self::assertTrue($signer->verify(101, 'front', $q['exp'], $q['sig']));
    }

    #[Test]
    public function rejectsTamperedVendorOrField(): void
    {
        $signer = $this->signer();
        $q = $this->parse($signer->signedPath(101, 'front'));

        self::assertFalse($signer->verify(102, 'front', $q['exp'], $q['sig']), 'vendor id swap');
        self::assertFalse($signer->verify(101, 'back', $q['exp'], $q['sig']), 'field swap');
    }

    #[Test]
    public function rejectsExpired(): void
    {
        $signer = $this->signer();
        $q = $this->parse($signer->signedPath(101, 'front', -10));

        self::assertFalse($signer->verify(101, 'front', $q['exp'], $q['sig']));
    }

    #[Test]
    public function rejectsUnknownField(): void
    {
        self::assertFalse($this->signer()->verify(101, 'selfie', time() + 100, 'anything'));
    }

    #[Test]
    public function rejectsWeakSecret(): void
    {
        $this->expectException(\RuntimeException::class);
        new ComplianceDocumentSigner('too-short');
    }
}
