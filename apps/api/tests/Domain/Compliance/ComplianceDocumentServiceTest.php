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
        // base64 alphabet, so a missing file returns null, not a bogus data URL.
        self::assertNull($this->service()->readAsDataUrl('compliance/vendor-5/front-abc123.jpg'));
    }

    #[Test]
    public function localizeInlineStoresDataUrlAndRoundTrips(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x01", 300);
        $svc = $this->service();

        $path = $svc->localizeInline(7, 'front', 'data:image/jpeg;base64,' . base64_encode($jpeg));

        self::assertNotNull($path);
        self::assertStringStartsWith('compliance/vendor-7/front-', $path);
        self::assertStringEndsWith('.jpg', $path);
        // The stored file reads back to the original bytes.
        $doc = $svc->openForDownload($path);
        self::assertNotNull($doc);
        self::assertSame('image/jpeg', $doc['mime']);
        self::assertSame($jpeg, $doc['bytes']);
    }

    #[Test]
    public function localizeInlineStoresRawBase64(): void
    {
        $png = "\x89PNG\x0D\x0A\x1A\x0A" . str_repeat("\x02", 300);
        $svc = $this->service();

        $path = $svc->localizeInline(9, 'license', base64_encode($png));

        self::assertNotNull($path);
        self::assertStringStartsWith('compliance/vendor-9/license-', $path);
        self::assertStringEndsWith('.png', $path);
    }

    #[Test]
    public function openForDownloadToleratesWhitespaceInBase64(): void
    {
        // MIME-chunked base64 (newlines every 76 chars), how some legacy rows
        // stored it. Strict base64_decode used to reject this → "not found".
        $jpeg = "\xFF\xD8\xFF\xE0" . str_repeat("\x03", 300);
        $chunked = chunk_split(base64_encode($jpeg), 76, "\n");
        $svc = $this->service();

        $viaDataUrl = $svc->openForDownload('data:image/jpeg;base64,' . $chunked);
        self::assertNotNull($viaDataUrl);
        self::assertSame('image/jpeg', $viaDataUrl['mime']);
        self::assertSame($jpeg, $viaDataUrl['bytes']);

        $viaRaw = $svc->openForDownload($chunked);
        self::assertNotNull($viaRaw);
        self::assertSame($jpeg, $viaRaw['bytes']);
    }

    #[Test]
    public function openForDownloadToleratesDataUrlMimeParams(): void
    {
        $png = "\x89PNG\x0D\x0A\x1A\x0A" . str_repeat("\x04", 300);
        $doc = $this->service()->openForDownload('data:image/png;charset=utf-8;base64,' . base64_encode($png));
        self::assertNotNull($doc);
        self::assertSame('image/png', $doc['mime']);
        self::assertSame($png, $doc['bytes']);
    }

    #[Test]
    public function localizeInlineLeavesPathsUrlsAndEmptyUntouched(): void
    {
        $svc = $this->service();
        self::assertNull($svc->localizeInline(1, 'front', 'compliance/vendor-1/front-abc.jpg'));
        self::assertNull($svc->localizeInline(1, 'front', 'https://legacy.example/id.jpg'));
        self::assertNull($svc->localizeInline(1, 'front', 'http://legacy.example/id.jpg'));
        self::assertNull($svc->localizeInline(1, 'front', ''));
        self::assertNull($svc->localizeInline(1, 'front', null));
    }
}
