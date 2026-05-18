<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Order;

use Bayti\Api\Domain\Order\OrderReturnRequestPhoto;
use Bayti\Api\Domain\Order\ReturnPhotoStorageService;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\UploadedFile;

/**
 * Coverage for ReturnPhotoStorageService (M3.2.X.18-B).
 *
 * Uses a real LocalFilesystemAdapter rooted in a per-test temp
 * directory. No mocks for the filesystem — we want to verify the
 * service actually writes + reads + deletes via Flysystem.
 *
 * Each test creates a fresh temp dir + tears it down afterward.
 *
 * UploadedFile fixtures come from Slim's Psr7\UploadedFile
 * implementation, constructed against on-disk temp files (matches
 * how Slim creates them at request time).
 */
#[CoversClass(ReturnPhotoStorageService::class)]
final class ReturnPhotoStorageServiceTest extends TestCase
{
    private string $tmpDir;
    private ReturnPhotoStorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/return-photo-storage-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->tmpDir));
        $this->service = new ReturnPhotoStorageService($filesystem);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    // =================================================================
    // store() — happy paths
    // =================================================================

    #[Test]
    public function storeReturnsStoredPhotoWithValidPathForJpeg(): void
    {
        $upload = $this->makeUpload(
            bytes: random_bytes(1024),
            mime: 'image/jpeg',
            filename: 'IMG_2031.jpg',
        );

        $stored = $this->service->store($upload, returnRequestId: 42);

        self::assertSame('image/jpeg', $stored->mimeType);
        self::assertSame(1024, $stored->sizeBytes);
        self::assertSame('IMG_2031.jpg', $stored->originalFilename);
        self::assertStringStartsWith('return-photos/', $stored->storagePath);
        self::assertStringContainsString('/42/', $stored->storagePath);
        self::assertStringEndsWith('.jpg', $stored->storagePath);
        // File must actually exist on disk.
        self::assertFileExists($this->tmpDir . '/' . $stored->storagePath);
    }

    #[Test]
    public function storeReturnsStoredPhotoForPng(): void
    {
        $upload = $this->makeUpload(
            bytes: random_bytes(2048),
            mime: 'image/png',
        );
        $stored = $this->service->store($upload, returnRequestId: 17);
        self::assertSame('image/png', $stored->mimeType);
        self::assertStringEndsWith('.png', $stored->storagePath);
    }

    #[Test]
    public function storeReturnsStoredPhotoForWebp(): void
    {
        $upload = $this->makeUpload(
            bytes: random_bytes(512),
            mime: 'image/webp',
        );
        $stored = $this->service->store($upload, returnRequestId: 1);
        self::assertSame('image/webp', $stored->mimeType);
        self::assertStringEndsWith('.webp', $stored->storagePath);
    }

    #[Test]
    public function storeUsesPendingDirectoryWhenRequestIdIsZero(): void
    {
        $upload = $this->makeUpload(bytes: random_bytes(100), mime: 'image/jpeg');
        $stored = $this->service->store($upload, returnRequestId: 0);
        self::assertStringContainsString('/pending/', $stored->storagePath);
    }

    #[Test]
    public function storeNormalizesEmptyFilenameToNull(): void
    {
        $upload = $this->makeUpload(
            bytes: random_bytes(100),
            mime: 'image/jpeg',
            filename: '   ',
        );
        $stored = $this->service->store($upload, returnRequestId: 1);
        self::assertNull($stored->originalFilename);
    }

    #[Test]
    public function storeBytesRoundTripBitForBit(): void
    {
        // The bytes written to Flysystem must exactly match what we
        // sent in. This is the most important behavioral property
        // for the upload pipeline.
        $bytes = random_bytes(4096);
        $upload = $this->makeUpload(bytes: $bytes, mime: 'image/jpeg');

        $stored = $this->service->store($upload, returnRequestId: 1);

        $onDisk = file_get_contents($this->tmpDir . '/' . $stored->storagePath);
        self::assertSame($bytes, $onDisk, 'stored bytes must match upload bytes');
    }

    // =================================================================
    // store() — validation rejections
    // =================================================================

    #[Test]
    public function storeRejectsUploadWithError(): void
    {
        $upload = $this->makeUpload(
            bytes: '',
            mime: 'image/jpeg',
            error: UPLOAD_ERR_INI_SIZE,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload failed');
        $this->service->store($upload);
    }

    #[Test]
    public function storeRejectsUnsupportedMimeType(): void
    {
        $upload = $this->makeUpload(
            bytes: random_bytes(100),
            mime: 'image/gif',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported upload mime type 'image/gif'");
        $this->service->store($upload);
    }

    #[Test]
    public function storeRejectsOversize(): void
    {
        $tooBig = OrderReturnRequestPhoto::MAX_PHOTO_SIZE_BYTES + 1;
        $upload = $this->makeUpload(
            bytes: str_repeat('x', $tooBig),
            mime: 'image/jpeg',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds max');
        $this->service->store($upload);
    }

    #[Test]
    public function storeRejectsZeroSize(): void
    {
        $upload = $this->makeUpload(bytes: '', mime: 'image/jpeg');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload size must be > 0');
        $this->service->store($upload);
    }

    #[Test]
    public function storeNormalizesUppercaseMimeType(): void
    {
        // A polite client may send "Image/JPEG"; we accept it.
        $upload = $this->makeUpload(
            bytes: random_bytes(100),
            mime: 'Image/JPEG',
        );
        $stored = $this->service->store($upload, returnRequestId: 1);
        self::assertSame('image/jpeg', $stored->mimeType);
    }

    // =================================================================
    // readStream / exists / delete
    // =================================================================

    #[Test]
    public function readStreamReturnsExpectedBytesFromStoredPhoto(): void
    {
        $bytes = random_bytes(1024);
        $upload = $this->makeUpload(bytes: $bytes, mime: 'image/jpeg');
        $stored = $this->service->store($upload, returnRequestId: 1);

        $stream = $this->service->readStream($stored->storagePath);
        $read = stream_get_contents($stream);
        fclose($stream);

        self::assertSame($bytes, $read);
    }

    #[Test]
    public function existsReturnsTrueForStoredPhoto(): void
    {
        $upload = $this->makeUpload(bytes: random_bytes(100), mime: 'image/jpeg');
        $stored = $this->service->store($upload, returnRequestId: 1);

        self::assertTrue($this->service->exists($stored->storagePath));
    }

    #[Test]
    public function existsReturnsFalseForUnknownPath(): void
    {
        self::assertFalse($this->service->exists('return-photos/2026/05/999/nonexistent.jpg'));
    }

    #[Test]
    public function deleteRemovesPhotoAndIsIdempotent(): void
    {
        $upload = $this->makeUpload(bytes: random_bytes(100), mime: 'image/jpeg');
        $stored = $this->service->store($upload, returnRequestId: 1);
        self::assertTrue($this->service->exists($stored->storagePath));

        $this->service->delete($stored->storagePath);
        self::assertFalse($this->service->exists($stored->storagePath));

        // Idempotent — second delete must not throw.
        $this->service->delete($stored->storagePath);
        self::assertFalse($this->service->exists($stored->storagePath));
    }

    // =================================================================
    // Path generation
    // =================================================================

    #[Test]
    public function generatedPathsAreCollisionResistant(): void
    {
        // Generate many paths in quick succession — none should
        // collide. The ULID's 16-char random tail makes collisions
        // astronomically improbable.
        $paths = [];
        for ($i = 0; $i < 100; $i++) {
            $upload = $this->makeUpload(bytes: random_bytes(10), mime: 'image/jpeg');
            $stored = $this->service->store($upload, returnRequestId: 42);
            $paths[] = $stored->storagePath;
        }
        self::assertCount(100, array_unique($paths), 'all 100 paths must be unique');
    }

    #[Test]
    public function generatedPathsIncludeYearMonthShard(): void
    {
        $upload = $this->makeUpload(bytes: random_bytes(10), mime: 'image/jpeg');
        $stored = $this->service->store($upload, returnRequestId: 1);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        self::assertStringContainsString(
            'return-photos/' . $now->format('Y/m') . '/',
            $stored->storagePath,
        );
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeUpload(
        string $bytes,
        string $mime,
        ?string $filename = null,
        int $error = UPLOAD_ERR_OK,
    ): UploadedFile {
        // Write the bytes to a real temp file so PSR-7 UploadedFile
        // can wrap it. This is exactly what Slim does at request
        // parse time.
        $tmpFile = tempnam(sys_get_temp_dir(), 'rps-test');
        if ($tmpFile === false) {
            throw new \RuntimeException('Failed to create temp upload file.');
        }
        file_put_contents($tmpFile, $bytes);
        $size = strlen($bytes);

        return new UploadedFile(
            $tmpFile,
            $filename,
            $mime,
            $size,
            $error,
        );
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
