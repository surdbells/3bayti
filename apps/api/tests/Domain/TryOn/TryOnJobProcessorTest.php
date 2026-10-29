<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\TryOn;

use Bayti\Api\Ai\TryOn\VirtualTryOnProviderInterface;
use Bayti\Api\Ai\TryOn\VirtualTryOnResult;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Media\ImageStorageService;
use Bayti\Api\Domain\TryOn\TryOnJob;
use Bayti\Api\Domain\TryOn\TryOnJobProcessor;
use Bayti\Api\Domain\TryOn\TryOnPhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TryOnJobProcessor::class)]
#[CoversClass(TryOnJob::class)]
#[CoversClass(TryOnPhotoStorage::class)]
#[CoversClass(VirtualTryOnResult::class)]
final class TryOnJobProcessorTest extends TestCase
{
    private const UPLOADS_BASE = 'https://cdn.test/uploads';
    private const SOURCE_PATH = 'try-on/1/in/src.jpg';
    private const GARMENT_PATH = 'products/v1/garment.png';

    /** @var list<string> */
    private array $written = [];
    /** @var list<string> */
    private array $deletedInputs = [];
    /** @var list<string> garment paths read from the public image store */
    private array $garmentReads = [];
    private int $generateCalls = 0;
    private ?string $lastGarmentMime = null;

    protected function setUp(): void
    {
        $_ENV['UPLOADS_PUBLIC_URL'] = self::UPLOADS_BASE;
        $this->written = [];
        $this->deletedInputs = [];
        $this->garmentReads = [];
        $this->generateCalls = 0;
        $this->lastGarmentMime = null;
    }

    #[Test]
    public function succeedsStoresResultAndDeletesTheSourcePhoto(): void
    {
        $job = $this->runJob(
            $this->provider(true, new VirtualTryOnResult('OUTPUTPNG', 'image/png')),
            $this->tryOnProduct(),
        );

        self::assertSame(TryOnJob::STATUS_SUCCEEDED, $job->getStatus());
        self::assertNotNull($job->getResultImageUrl());
        self::assertStringStartsWith(self::UPLOADS_BASE . '/try-on/1/out/', (string) $job->getResultImageUrl());
        self::assertNotNull($job->getResultImagePath());
        self::assertNotEmpty($this->written);
        // The raw customer photo (private storage) was deleted and the ref cleared.
        self::assertContains(self::SOURCE_PATH, $this->deletedInputs);
        self::assertSame('', $job->getSourceImagePath());
    }

    #[Test]
    public function failsWhenProviderIsDisabled(): void
    {
        $job = $this->runJob($this->provider(false, VirtualTryOnResult::empty()), $this->tryOnProduct());

        self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus());
        self::assertContains(self::SOURCE_PATH, $this->deletedInputs, 'source photo deleted even on failure');
    }

    #[Test]
    public function failsWhenGenerationReturnsEmpty(): void
    {
        $job = $this->runJob($this->provider(true, VirtualTryOnResult::empty()), $this->tryOnProduct());

        self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus());
    }

    #[Test]
    public function failsWhenProductIsNotTryOnEnabled(): void
    {
        $product = $this->tryOnProduct();
        $product->setTryOnEnabled(false);

        $job = $this->runJob($this->provider(true, new VirtualTryOnResult('X', 'image/png')), $product);

        self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus());
    }

    #[Test]
    public function failsWhenProductIsOutOfStock(): void
    {
        $product = $this->tryOnProduct();
        $product->setStockStatus(Product::STOCK_OUT);

        $job = $this->runJob($this->provider(true, new VirtualTryOnResult('X', 'image/png')), $product);

        self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus());
    }

    #[Test]
    public function failsWhenGarmentImageFormatIsUnsupported(): void
    {
        $product = $this->tryOnProduct();
        $this->setPrivate($product, 'primaryImageUrl', self::UPLOADS_BASE . '/products/v1/garment.gif');

        $job = $this->runJob(
            $this->provider(true, new VirtualTryOnResult('X', 'image/png')),
            $product,
            garmentPath: 'products/v1/garment.gif',
        );

        self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus());
        self::assertSame('This product image format is not supported for try-on.', $job->getErrorSample());
        self::assertSame([], $this->garmentReads, 'unsupported format fails before any garment read');
    }

    #[Test]
    public function failsFastOnAnUnknownGarmentExtensionInsteadOfSendingItAsJpeg(): void
    {
        // Used to default to image/jpeg and be sent upstream; now the shared
        // TryOnGarmentEligibility rejects it before any read or provider call.
        foreach (['products/v1/garment.bmp', 'products/v1/garment.tiff', 'products/v1/garment'] as $path) {
            $this->garmentReads = [];
            $product = $this->tryOnProduct();
            $this->setPrivate($product, 'primaryImageUrl', self::UPLOADS_BASE . '/' . $path);

            $provider = $this->provider(true, new VirtualTryOnResult('X', 'image/png'));
            $job = $this->runJob($provider, $product, garmentPath: $path);

            self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus(), $path);
            self::assertSame('This product image format is not supported for try-on.', $job->getErrorSample(), $path);
            self::assertSame([], $this->garmentReads, $path);
            self::assertSame(0, $this->generateCalls, $path);
        }
    }

    #[Test]
    public function failsWhenTheGarmentImageIsNotInOurStorage(): void
    {
        $product = $this->tryOnProduct();
        $this->setPrivate($product, 'primaryImageUrl', 'https://images.example.com/garment.jpg');

        $job = $this->runJob($this->provider(true, new VirtualTryOnResult('X', 'image/png')), $product);

        self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus());
        self::assertSame('The product image is unavailable for try-on.', $job->getErrorSample());
        self::assertSame([], $this->garmentReads);
    }

    #[Test]
    public function failsWhenTheProductHasNoPrimaryImage(): void
    {
        $product = $this->tryOnProduct();
        $this->setPrivate($product, 'primaryImageUrl', null);

        $job = $this->runJob($this->provider(true, new VirtualTryOnResult('X', 'image/png')), $product);

        self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus());
        self::assertSame('The product image is unavailable for try-on.', $job->getErrorSample());
    }

    #[Test]
    public function failsWhenTheStoredGarmentFileIsMissing(): void
    {
        // Eligible by URL, but the file is gone: the worker still reads it and
        // fails as before.
        $job = $this->runJob(
            $this->provider(true, new VirtualTryOnResult('X', 'image/png')),
            $this->tryOnProduct(),
            garmentPath: 'products/v1/some-other-file.png',
        );

        self::assertSame(TryOnJob::STATUS_FAILED, $job->getStatus());
        self::assertSame('The product image is unavailable for try-on.', $job->getErrorSample());
        self::assertSame([self::GARMENT_PATH], $this->garmentReads);
    }

    #[Test]
    public function readsTheQueryFreePathAndSendsTheMatchingMime(): void
    {
        $product = $this->tryOnProduct();
        $this->setPrivate($product, 'primaryImageUrl', self::UPLOADS_BASE . '/products/v1/garment.WEBP?v=2');

        $job = $this->runJob(
            $this->provider(true, new VirtualTryOnResult('OUTPUTPNG', 'image/png')),
            $product,
            garmentPath: 'products/v1/garment.WEBP',
        );

        self::assertSame(TryOnJob::STATUS_SUCCEEDED, $job->getStatus());
        self::assertSame(['products/v1/garment.WEBP'], $this->garmentReads);
        self::assertSame('image/webp', $this->lastGarmentMime);
    }

    // ── harness ─────────────────────────────────────────────────────────

    private function runJob(
        VirtualTryOnProviderInterface $provider,
        Product $product,
        string $garmentPath = self::GARMENT_PATH,
    ): TryOnJob {
        $job = new TryOnJob(1, 1, self::SOURCE_PATH);
        $processor = new TryOnJobProcessor(
            $provider,
            $this->photos(),
            $this->imageStorage($garmentPath),
            $this->em($product),
        );
        $processor->process($job);
        return $job;
    }

    private function provider(bool $enabled, VirtualTryOnResult $result): VirtualTryOnProviderInterface
    {
        $onGenerate = function (string $garmentMime): void {
            $this->generateCalls++;
            $this->lastGarmentMime = $garmentMime;
        };

        return new class ($enabled, $result, $onGenerate) implements VirtualTryOnProviderInterface {
            public function __construct(
                private readonly bool $enabled,
                private readonly VirtualTryOnResult $result,
                private readonly \Closure $onGenerate,
            ) {
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function generate(
                string $personBytes,
                string $personMime,
                string $garmentBytes,
                string $garmentMime,
                string $garmentPrompt,
            ): VirtualTryOnResult {
                ($this->onGenerate)($garmentMime);
                return $this->result;
            }
        };
    }

    private function tryOnProduct(): Product
    {
        $vendor = new Vendor('vendor-1', 'Vendor 1', 'v1@example.test');
        $vendor->approve();
        $this->setId($vendor, 1);

        $product = new Product(vendor: $vendor, slug: 'p-1', name: 'Rose Abaya');
        $product->setStatus('active');
        $product->setPrice('300.00');
        $product->setTryOnEnabled(true);
        $this->setPrivate($product, 'primaryImageUrl', self::UPLOADS_BASE . '/' . self::GARMENT_PATH);
        $this->setId($product, 1);

        return $product;
    }

    /** Private, off-web-root photo store for the raw INPUT (source read + delete). */
    private function photos(): TryOnPhotoStorage
    {
        $fs = $this->createMock(FilesystemOperator::class);
        $fs->method('fileExists')->willReturn(true);
        $fs->method('read')->willReturn('PERSONBYTES');
        $fs->method('delete')->willReturnCallback(
            function (string $path): void {
                $this->deletedInputs[] = $path;
            },
        );

        return new TryOnPhotoStorage($fs);
    }

    /** Public image store for the GARMENT read + OUTPUT write. */
    private function imageStorage(string $garmentPath): ImageStorageService
    {
        $fs = $this->createMock(FilesystemOperator::class);
        $fs->method('fileExists')->willReturn(true);
        $fs->method('read')->willReturnCallback(
            function (string $path) use ($garmentPath): string {
                $this->garmentReads[] = $path;
                return $path === $garmentPath ? 'GARMENTBYTES' : '';
            },
        );
        $fs->method('writeStream')->willReturnCallback(
            function (string $path): void {
                $this->written[] = $path;
            },
        );

        return new ImageStorageService($fs);
    }

    private function em(?Product $product): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($product);

        return $em;
    }

    private function setId(object $entity, int $id): void
    {
        $this->setPrivate($entity, 'id', $id);
    }

    private function setPrivate(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
