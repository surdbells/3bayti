<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Compliance;

use Bayti\Api\Domain\Compliance\ComplianceDocumentService;
use Bayti\Api\Domain\Media\ImageStorageService;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers the legacy-document fallbacks added so admins can view KYC documents
 * migrated from the legacy platform (which stored raw base64 or full URLs
 * rather than the new private-storage paths).
 */
final class ComplianceDocumentServiceTest extends TestCase
{
    private function service(): ComplianceDocumentService
    {
        // Empty temp dir → every storage read() misses, so the legacy fallbacks
        // (raw base64 / passthrough) are what we exercise here.
        $dir = sys_get_temp_dir() . '/compliance-doc-test-' . bin2hex(random_bytes(4));
        $storage = new ImageStorageService(new Filesystem(new LocalFilesystemAdapter($dir)));
        return new ComplianceDocumentService($storage);
    }

    #[Test]
    public function rawBase64LegacyValueIsWrappedAsDataUrl(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 300);
        $rawB64 = base64_encode($jpeg);
        $svc = $this->service();

        self::assertStringStartsWith('data:image/jpeg;base64,', (string) $svc->readAsDataUrl($rawB64));

        $doc = $svc->openForDownload($rawB64);
        self::assertNotNull($doc);
        self::assertSame('image/jpeg', $doc['mime']);
        self::assertSame($jpeg, $doc['bytes']);
    }

    #[Test]
    public function dataAndHttpUrlsPassThroughVerbatim(): void
    {
        $svc = $this->service();
        self::assertSame('data:image/png;base64,AAAA', $svc->readAsDataUrl('data:image/png;base64,AAAA'));
        self::assertSame('https://legacy.example/kyc/id-front.jpg', $svc->readAsDataUrl('https://legacy.example/kyc/id-front.jpg'));
    }

    #[Test]
    public function realStoragePathIsNotMistakenForBase64(): void
    {
        // A genuine storage path contains '-' and '.', which aren't in the
        // base64 alphabet — so a missing file returns null, not a bogus data URL.
        self::assertNull($this->service()->readAsDataUrl('compliance/vendor-5/front-abc123.jpg'));
    }
}
