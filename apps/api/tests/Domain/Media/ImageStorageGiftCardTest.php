<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Media;

use Bayti\Api\Domain\Media\ImageStorageService;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * ImageStorageService::storeGiftCardPhoto (M3.5 Phase E / luxury theme).
 * Stores under gift-cards/{userId}/{ULID}.{ext} via Flysystem, reusing
 * the shared mime/size validation. The FilesystemOperator is mocked so
 * nothing touches disk.
 */
#[CoversClass(ImageStorageService::class)]
final class ImageStorageGiftCardTest extends TestCase
{
    #[Test]
    public function storesPhotoUnderUserNamespacedUlidPath(): void
    {
        $captured = null;
        $fs = $this->createMock(FilesystemOperator::class);
        $fs->expects(self::once())
            ->method('writeStream')
            ->willReturnCallback(function (string $path) use (&$captured): void {
                $captured = $path;
            });

        $service = new ImageStorageService($fs);
        $stored  = $service->storeGiftCardPhoto($this->makeUpload('image/png', 'fakebytes'), 42);

        self::assertMatchesRegularExpression('#^gift-cards/42/[0-9A-Z]{26}\.png$#', $stored->storagePath);
        self::assertSame($stored->storagePath, $captured, 'written path must match the returned storage path');
        self::assertSame('image/png', $stored->mimeType);
        self::assertSame(strlen('fakebytes'), $stored->sizeBytes);
    }

    #[Test]
    public function jpegMapsToJpgExtension(): void
    {
        $fs = $this->createMock(FilesystemOperator::class);
        $fs->method('writeStream');
        $service = new ImageStorageService($fs);

        $stored = $service->storeGiftCardPhoto($this->makeUpload('image/jpeg', 'x'), 7);
        self::assertStringEndsWith('.jpg', $stored->storagePath);
        self::assertStringStartsWith('gift-cards/7/', $stored->storagePath);
    }

    #[Test]
    public function rejectsUnsupportedMimeWithoutWriting(): void
    {
        $fs = $this->createMock(FilesystemOperator::class);
        $fs->expects(self::never())->method('writeStream');
        $service = new ImageStorageService($fs);

        $this->expectException(\InvalidArgumentException::class);
        $service->storeGiftCardPhoto($this->makeUpload('application/pdf', 'data'), 7);
    }

    private function makeUpload(string $mime, string $contents): UploadedFileInterface
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            self::fail('Could not open temp stream for the test upload.');
        }
        fwrite($resource, $contents);
        rewind($resource);

        $upload = $this->createMock(UploadedFileInterface::class);
        $upload->method('getError')->willReturn(UPLOAD_ERR_OK);
        $upload->method('getSize')->willReturn(strlen($contents));
        $upload->method('getClientMediaType')->willReturn($mime);
        $upload->method('getStream')->willReturn(new Stream($resource));

        return $upload;
    }
}
