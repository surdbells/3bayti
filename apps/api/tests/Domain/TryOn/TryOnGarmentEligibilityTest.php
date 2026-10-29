<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\TryOn;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\TryOn\TryOnGarmentEligibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The single garment-image rule shared by the admin try-on screens, the public
 * product serializer and the try-on worker: primary image present, one of our
 * uploads, and a JPEG/PNG/WebP by extension (case-insensitive, query string and
 * fragment ignored). Pure string checks, no I/O.
 */
#[CoversClass(TryOnGarmentEligibility::class)]
final class TryOnGarmentEligibilityTest extends TestCase
{
    private const BASE = 'https://cdn.test/uploads';

    private ?string $prevUploadsUrl = null;

    protected function setUp(): void
    {
        $this->prevUploadsUrl = $_ENV['UPLOADS_PUBLIC_URL'] ?? null;
        $_ENV['UPLOADS_PUBLIC_URL'] = self::BASE;
    }

    protected function tearDown(): void
    {
        if ($this->prevUploadsUrl === null) {
            unset($_ENV['UPLOADS_PUBLIC_URL']);
        } else {
            $_ENV['UPLOADS_PUBLIC_URL'] = $this->prevUploadsUrl;
        }
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string|null}>
     */
    public static function urls(): iterable
    {
        // eligible
        yield 'jpg' => [self::BASE . '/products/v/a.jpg', null];
        yield 'jpeg' => [self::BASE . '/products/v/a.jpeg', null];
        yield 'png' => [self::BASE . '/products/v/a.png', null];
        yield 'webp' => [self::BASE . '/products/v/a.webp', null];
        yield 'upper-case JPG' => [self::BASE . '/products/v/a.JPG', null];
        yield 'mixed-case WebP' => [self::BASE . '/products/v/a.WebP', null];
        yield 'query string ignored' => [self::BASE . '/products/v/a.png?v=3', null];
        yield 'fragment ignored' => [self::BASE . '/products/v/a.jpeg#zoom', null];
        // no_image
        yield 'null' => [null, TryOnGarmentEligibility::REASON_NO_IMAGE];
        yield 'empty' => ['', TryOnGarmentEligibility::REASON_NO_IMAGE];
        yield 'blank' => ['   ', TryOnGarmentEligibility::REASON_NO_IMAGE];
        // external_image
        yield 'foreign host' => ['https://images.example.com/a.jpg', TryOnGarmentEligibility::REASON_EXTERNAL_IMAGE];
        yield 'same host outside uploads' => ['https://cdn.test/static/a.jpg', TryOnGarmentEligibility::REASON_EXTERNAL_IMAGE];
        yield 'uploads root only' => [self::BASE . '/', TryOnGarmentEligibility::REASON_EXTERNAL_IMAGE];
        // Legacy-migrated products still point at the old platform's host
        // (MigrationSteps::LEGACY_PRODUCT_IMAGE_HOST); the worker cannot read
        // those either, so they need re-uploading before try-on.
        yield 'legacy-hosted product image' => [
            'https://api.3bayti.ae/vendors/products/abaya.jpg',
            TryOnGarmentEligibility::REASON_EXTERNAL_IMAGE,
        ];
        // unsupported_format
        yield 'gif' => [self::BASE . '/products/v/a.gif', TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT];
        yield 'upper-case GIF' => [self::BASE . '/products/v/a.GIF', TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT];
        yield 'gif behind a jpg-looking query' => [self::BASE . '/products/v/a.gif?f=x.jpg', TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT];
        yield 'bmp' => [self::BASE . '/products/v/a.bmp', TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT];
        yield 'heic' => [self::BASE . '/products/v/a.heic', TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT];
        yield 'no extension' => [self::BASE . '/products/v/a', TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT];
        yield 'extension only in the query' => [self::BASE . '/products/v/a?name=b.png', TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT];
    }

    #[Test]
    #[DataProvider('urls')]
    public function classifiesTheGarmentImageUrl(?string $url, ?string $expectedReason): void
    {
        $eligibility = new TryOnGarmentEligibility();

        self::assertSame($expectedReason, $eligibility->reasonForUrl($url));
        self::assertSame($expectedReason, $eligibility->reasonFor($this->product($url)));
        self::assertSame($expectedReason === null, $eligibility->isEligible($this->product($url)));
        self::assertSame(
            ['eligible' => $expectedReason === null, 'reason' => $expectedReason],
            $eligibility->describe($this->product($url)),
        );
    }

    #[Test]
    public function usesThePrimaryImageNotTheGallery(): void
    {
        $product = $this->product(null);
        $product->setImages([self::BASE . '/products/v/gallery.jpg']);

        self::assertSame(
            TryOnGarmentEligibility::REASON_NO_IMAGE,
            (new TryOnGarmentEligibility())->reasonFor($product),
        );
    }

    #[Test]
    public function garmentStoragePathDropsQueryAndFragment(): void
    {
        $eligibility = new TryOnGarmentEligibility();

        self::assertSame('products/v/a.png', $eligibility->garmentStoragePath($this->product(self::BASE . '/products/v/a.png?v=3')));
        self::assertSame('products/v/a.jpg', $eligibility->storagePathForUrl(self::BASE . '/products/v/a.jpg#x'));
        self::assertNull($eligibility->garmentStoragePath($this->product('https://images.example.com/a.jpg')));
        self::assertNull($eligibility->garmentStoragePath($this->product(null)));
    }

    #[Test]
    public function mapsAcceptedExtensionsToTheModelMime(): void
    {
        $eligibility = new TryOnGarmentEligibility();

        self::assertSame('image/jpeg', $eligibility->mimeForPath('a/b.jpg'));
        self::assertSame('image/jpeg', $eligibility->mimeForPath('a/b.JPEG'));
        self::assertSame('image/png', $eligibility->mimeForPath('a/b.png?x=1'));
        self::assertSame('image/webp', $eligibility->mimeForPath('a/b.webp'));
        self::assertNull($eligibility->mimeForPath('a/b.gif'));
        self::assertNull($eligibility->mimeForPath('a/b.tiff'));
        self::assertNull($eligibility->mimeForPath('a/b'));
        self::assertSame(['jpeg', 'png', 'webp'], TryOnGarmentEligibility::ELIGIBLE_FORMATS);
    }

    #[Test]
    public function everyReasonHasADistinctMessage(): void
    {
        $messages = array_map(
            static fn (string $r): string => TryOnGarmentEligibility::reasonMessage($r),
            [
                TryOnGarmentEligibility::REASON_NO_IMAGE,
                TryOnGarmentEligibility::REASON_EXTERNAL_IMAGE,
                TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT,
            ],
        );

        self::assertCount(3, array_unique($messages));
        self::assertStringContainsString('no primary image', $messages[0]);
        self::assertStringContainsString('external', $messages[1]);
        // Legacy-hosted 3bayti images land here too, so the message must not
        // claim the image is merely "not on 3bayti".
        self::assertStringContainsString('legacy', $messages[1]);
        self::assertStringContainsString('Re-upload', $messages[1]);
        self::assertStringContainsString('JPEG, PNG or WebP', $messages[2]);
    }

    private function product(?string $primaryImageUrl): Product
    {
        $product = new Product(new Vendor('v', 'Vendor', 'v@example.test'), 'p', 'Abaya');
        $product->setPrimaryImageUrl($primaryImageUrl);

        return $product;
    }
}
