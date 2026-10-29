<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ai;

use Bayti\Api\Ai\TryOn\VirtualTryOnProviderInterface;
use Bayti\Api\Ai\TryOn\VirtualTryOnResult;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\TryOn\TryOnGarmentEligibility;
use Bayti\Api\Domain\TryOn\TryOnJob;
use Bayti\Api\Domain\TryOn\TryOnJobRepository;
use Bayti\Api\Domain\TryOn\TryOnPhotoStorage;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Ai\StartTryOnController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /v3/ai/try-on refuses a flagged product whose garment image the worker
 * cannot use (shared TryOnGarmentEligibility rule: none, not in our storage,
 * GIF / other format) exactly like an un-flagged one, 404, BEFORE the rate
 * limits run, so a job that could only fail never uses up the customer's daily
 * try-on cap, a worker slot, or stores their photo.
 */
#[CoversClass(StartTryOnController::class)]
final class StartTryOnControllerTest extends HttpTestCase
{
    private const BASE = 'https://cdn.test/uploads';
    private const PRODUCT_ID = 101;
    // A 1x1 transparent PNG.
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private ?string $prevUploadsUrl = null;

    private ?Product $product = null;

    /** @var list<object> */
    private array $persisted = [];
    private int $flushCalls = 0;
    private int $dailyCapChecks = 0;
    private int $cacheCalls = 0;
    /** @var list<string> */
    private array $storedPhotos = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevUploadsUrl = $_ENV['UPLOADS_PUBLIC_URL'] ?? null;
        $_ENV['UPLOADS_PUBLIC_URL'] = self::BASE;

        $this->product = null;
        $this->persisted = [];
        $this->flushCalls = 0;
        $this->dailyCapChecks = 0;
        $this->cacheCalls = 0;
        $this->storedPhotos = [];
    }

    protected function tearDown(): void
    {
        if ($this->prevUploadsUrl === null) {
            unset($_ENV['UPLOADS_PUBLIC_URL']);
        } else {
            $_ENV['UPLOADS_PUBLIC_URL'] = $this->prevUploadsUrl;
        }
        parent::tearDown();
    }

    #[Test]
    public function aGifGarmentIsRefusedBeforeTheRateLimitsAndNoJobIsEnqueued(): void
    {
        $user = $this->customer();
        $this->product = $this->flaggedProduct(self::BASE . '/products/rose/abaya.gif');
        $this->bindDeps($user);

        $res = $this->start($user);

        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
        self::assertSame('This product is not available for try-on.', $this->jsonBody($res)['error']['message']);
        $this->assertNothingConsumed();
    }

    /**
     * @return iterable<string, array{0: string|null}>
     */
    public static function ineligibleGarments(): iterable
    {
        yield TryOnGarmentEligibility::REASON_NO_IMAGE => [null];
        yield TryOnGarmentEligibility::REASON_EXTERNAL_IMAGE => ['https://images.example.com/abaya.jpg'];
        yield 'legacy-hosted' => ['https://api.3bayti.ae/vendors/products/abaya.jpg'];
        yield TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT => [self::BASE . '/products/rose/abaya.bmp'];
    }

    #[Test]
    #[DataProvider('ineligibleGarments')]
    public function everyIneligibleGarmentImageIsRefusedTheSameWay(?string $url): void
    {
        $user = $this->customer();
        $this->product = $this->flaggedProduct($url);
        $this->bindDeps($user);

        $res = $this->start($user);

        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
        $this->assertNothingConsumed();
    }

    #[Test]
    public function anEligibleGarmentIsEnqueued(): void
    {
        $user = $this->customer();
        $this->product = $this->flaggedProduct(self::BASE . '/products/rose/abaya.JPG?v=2');
        $this->bindDeps($user);

        $res = $this->start($user);

        self::assertSame(201, $res->getStatusCode(), (string) $res->getBody());
        $body = $this->jsonBody($res);
        $data = $body['data'] ?? $body;
        self::assertSame(TryOnJob::STATUS_QUEUED, $data['status']);
        self::assertNotEmpty($data['job_reference']);
        self::assertSame(1, $this->dailyCapChecks, 'the rate limits ran for a real job');
        self::assertCount(1, $this->storedPhotos);
        self::assertCount(1, $this->persisted);
        self::assertInstanceOf(TryOnJob::class, $this->persisted[0]);
        self::assertSame(self::PRODUCT_ID, $this->persisted[0]->getProductId());
    }

    #[Test]
    public function anUnflaggedProductIsStillRefused(): void
    {
        $user = $this->customer();
        $this->product = $this->flaggedProduct(self::BASE . '/products/rose/abaya.jpg');
        $this->product->setTryOnEnabled(false);
        $this->bindDeps($user);

        $res = $this->start($user);

        self::assertSame(404, $res->getStatusCode(), (string) $res->getBody());
        $this->assertNothingConsumed();
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function assertNothingConsumed(): void
    {
        self::assertSame(0, $this->dailyCapChecks, 'daily cap must not be consulted');
        self::assertSame(0, $this->cacheCalls, 'cooldown / hourly cap must not be consumed');
        self::assertSame([], $this->storedPhotos, 'the customer photo must not be stored');
        self::assertSame([], $this->persisted, 'no TryOnJob may be persisted');
        self::assertSame(0, $this->flushCalls, 'nothing flushed');
    }

    private function start(User $user): ResponseInterface
    {
        $jwt = $this->app->getContainer()?->get(JwtService::class);
        self::assertInstanceOf(JwtService::class, $jwt);
        $pair = $jwt->issueTokenPair($user);

        return $this->handle($this->jsonRequest('POST', '/v3/ai/try-on', [
            'image' => 'data:image/png;base64,' . self::PNG_B64,
            'product_id' => self::PRODUCT_ID,
            'consent' => true,
        ], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function bindDeps(User $user): void
    {
        $this->bind(VirtualTryOnProviderInterface::class, $this->enabledProvider());

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $jobRepo = $this->createMock(TryOnJobRepository::class);
        $jobRepo->method('countForUserSince')->willReturnCallback(function (): int {
            $this->dailyCapChecks++;
            return 0;
        });

        $em = $this->stubEm(function ($em) use ($userRepo, $jobRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [TryOnJob::class, $jobRepo],
            ]);
            $em->method('find')->willReturnCallback(
                fn (string $class, mixed $id): ?object => $class === Product::class && (int) $id === self::PRODUCT_ID
                    ? $this->product
                    : null,
            );
            $em->method('persist')->willReturnCallback(function (object $entity): void {
                $this->persisted[] = $entity;
            });
            $em->method('flush')->willReturnCallback(function (): void {
                $this->flushCalls++;
            });
        });
        $this->bind(EntityManagerInterface::class, $em);

        $cache = $this->createMock(KeyValueStore::class);
        $cache->method('setIfAbsent')->willReturnCallback(function (): bool {
            $this->cacheCalls++;
            return true;
        });
        $cache->method('incr')->willReturnCallback(function (): int {
            $this->cacheCalls++;
            return 1;
        });
        $this->bind(KeyValueStore::class, $cache);

        $filesystem = $this->createMock(FilesystemOperator::class);
        $filesystem->method('writeStream')->willReturnCallback(function (string $path): void {
            $this->storedPhotos[] = $path;
        });
        $this->bind(TryOnPhotoStorage::class, new TryOnPhotoStorage($filesystem));
    }

    private function customer(): User
    {
        return $this->makeUser(id: 42);
    }

    /** A try-on-flagged, orderable, in-stock product with the given garment image. */
    private function flaggedProduct(?string $primaryImageUrl): Product
    {
        $vendor = new Vendor('rose-atelier', 'Rose Atelier', 'rose@example.test');
        $vendor->approve();
        $product = new Product($vendor, 'rose-abaya', 'Rose Abaya');
        $ref = new \ReflectionProperty(Product::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($product, self::PRODUCT_ID);
        $product->setPrice('300.00');
        $product->setStatus(Product::STATUS_ACTIVE);
        $product->setPrimaryImageUrl($primaryImageUrl);
        $product->setTryOnEnabled(true);

        return $product;
    }

    private function enabledProvider(): VirtualTryOnProviderInterface
    {
        return new class implements VirtualTryOnProviderInterface {
            public function isEnabled(): bool
            {
                return true;
            }

            public function generate(
                string $personBytes,
                string $personMime,
                string $garmentBytes,
                string $garmentMime,
                string $garmentPrompt,
            ): VirtualTryOnResult {
                return VirtualTryOnResult::empty();
            }
        };
    }
}
