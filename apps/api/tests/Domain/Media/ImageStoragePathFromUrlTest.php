<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Media;

use Bayti\Api\Domain\Media\ImageStorageService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ImageStorageService::storagePathFromUrl (bug 2 orphan cleanup) — must
 * resolve our own upload URLs to storage paths and return null for
 * external/legacy URLs so we never delete files we don't own.
 */
#[CoversClass(ImageStorageService::class)]
final class ImageStoragePathFromUrlTest extends TestCase
{
    private ?string $prev = null;

    protected function setUp(): void
    {
        $this->prev = $_ENV['UPLOADS_PUBLIC_URL'] ?? null;
        $_ENV['UPLOADS_PUBLIC_URL'] = 'https://api-v3.3bayti.ae/uploads';
    }

    protected function tearDown(): void
    {
        if ($this->prev === null) {
            unset($_ENV['UPLOADS_PUBLIC_URL']);
        } else {
            $_ENV['UPLOADS_PUBLIC_URL'] = $this->prev;
        }
    }

    #[Test]
    public function resolvesOwnUploadUrlToStoragePath(): void
    {
        self::assertSame(
            'products/halif-stores/abc.jpg',
            ImageStorageService::storagePathFromUrl('https://api-v3.3bayti.ae/uploads/products/halif-stores/abc.jpg'),
        );
    }

    #[Test]
    public function roundTripsWithPublicUrl(): void
    {
        $path = 'products/store/x.jpg';
        self::assertSame($path, ImageStorageService::storagePathFromUrl(ImageStorageService::publicUrl($path)));
    }

    #[Test]
    public function returnsNullForExternalOrLegacyUrls(): void
    {
        self::assertNull(ImageStorageService::storagePathFromUrl('https://api.3bayti.ae/vendors/products/old.jpg'));
        self::assertNull(ImageStorageService::storagePathFromUrl('https://cdn.example.com/x.jpg'));
        self::assertNull(ImageStorageService::storagePathFromUrl(null));
        self::assertNull(ImageStorageService::storagePathFromUrl(''));
        // base URL itself with no path → null (nothing to delete)
        self::assertNull(ImageStorageService::storagePathFromUrl('https://api-v3.3bayti.ae/uploads/'));
    }
}
