<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\GiftCard;

use Bayti\Api\Domain\Media\ImageStorageService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\GiftCard\UploadGiftCardPhotoController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

#[CoversClass(UploadGiftCardPhotoController::class)]
final class UploadGiftCardPhotoControllerTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Real service with a mocked filesystem — exercises the full
        // validate/path/write logic without touching disk. Tests that
        // need to assert no write rebind their own.
        $fs = $this->createMock(FilesystemOperator::class);
        $fs->method('writeStream');
        $this->bind(ImageStorageService::class, new ImageStorageService($fs));
    }

    #[Test]
    public function happyPathReturns201WithGiftCardNamespacedUrl(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindEmWithUser($user);

        $jwt  = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $request = $this->authedUpload(
            $pair->accessToken,
            $this->makeUpload('image/jpeg', 'jpegbytes'),
        );

        $response = $this->handle($request);

        self::assertSame(201, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertArrayHasKey('data', $body);
        self::assertSame('image/jpeg', $body['data']['mime_type']);
        self::assertStringStartsWith('gift-cards/7/', $body['data']['storage_path']);
        self::assertMatchesRegularExpression(
            '#/uploads/gift-cards/7/[0-9A-Z]{26}\.jpg$#',
            (string) $body['data']['url'],
        );
        self::assertSame(strlen('jpegbytes'), $body['data']['size_bytes']);
    }

    #[Test]
    public function missingFileReturns400(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindEmWithUser($user);

        $jwt  = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // No uploaded files attached.
        $request = $this->jsonRequest('POST', '/v3/gift-cards/photo', [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]);

        $response = $this->handle($request);
        self::assertSame(400, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
        self::assertStringContainsString('image', strtolower($this->jsonBody($response)['error']['message']));
    }

    #[Test]
    public function unsupportedMimeReturns400(): void
    {
        $user = $this->makeUser(id: 7);
        $this->bindEmWithUser($user);

        $jwt  = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $request = $this->authedUpload(
            $pair->accessToken,
            $this->makeUpload('application/pdf', 'notanimage'),
        );

        $response = $this->handle($request);
        self::assertSame(400, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
    }

    #[Test]
    public function requiresAuth(): void
    {
        $request = $this->jsonRequest('POST', '/v3/gift-cards/photo', [])
            ->withUploadedFiles(['image' => $this->makeUpload('image/png', 'png')]);

        $response = $this->handle($request);
        self::assertSame(401, $response->getStatusCode());
    }

    // ----------------- helpers -----------------

    private function bindEmWithUser(User $user): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with($user->getId())->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function authedUpload(string $accessToken, UploadedFileInterface $upload): ServerRequestInterface
    {
        return $this->jsonRequest('POST', '/v3/gift-cards/photo', [], [
            'Authorization' => 'Bearer ' . $accessToken,
        ])->withUploadedFiles(['image' => $upload]);
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
