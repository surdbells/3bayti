<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Ota;

use Bayti\Api\Domain\Ota\OtaBundleStorageService;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtaBundleStorageService::class)]
final class OtaBundleStorageServiceTest extends TestCase
{
    private const BASE = 'https://api-v3.3bayti.ae/uploads';

    private string $tmpDir;
    private ?string $prevUploadsUrl = null;
    private Filesystem $filesystem;
    private OtaBundleStorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevUploadsUrl = $_ENV['UPLOADS_PUBLIC_URL'] ?? null;
        $_ENV['UPLOADS_PUBLIC_URL'] = self::BASE;
        $this->tmpDir = sys_get_temp_dir() . '/ota-storage-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->tmpDir));
        $this->storage = new OtaBundleStorageService($this->filesystem);
    }

    protected function tearDown(): void
    {
        if ($this->prevUploadsUrl === null) {
            unset($_ENV['UPLOADS_PUBLIC_URL']);
        } else {
            $_ENV['UPLOADS_PUBLIC_URL'] = $this->prevUploadsUrl;
        }
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function pathCarriesEveryUniqueKeyColumn(): void
    {
        self::assertSame(
            'ota/com.threebayti.app/production/android/1.7.1.zip',
            $this->storage->pathFor('com.threebayti.app', 'production', 'android', '1.7.1'),
        );
        self::assertNotSame(
            $this->storage->pathFor('com.threebayti.app', 'production', 'android', '1.7.1'),
            $this->storage->pathFor('com.threebayti.app', 'beta', 'android', '1.7.1'),
        );
        self::assertNotSame(
            $this->storage->pathFor('com.threebayti.app', 'production', 'android', '1.7.1'),
            $this->storage->pathFor('com.other.app', 'production', 'android', '1.7.1'),
        );
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function unsafeSegments(): iterable
    {
        yield 'app id ..' => ['..', 'production', 'android', '1.7.1'];
        yield 'channel traversal' => ['com.threebayti.app', '../../etc', 'android', '1.7.1'];
        yield 'platform traversal' => ['com.threebayti.app', 'production', 'ANDROID/..', '1.7.1'];
        yield 'unknown platform' => ['com.threebayti.app', 'production', 'windows', '1.7.1'];
        yield 'version traversal' => ['com.threebayti.app', 'production', 'android', '1.7/../../x'];
        // Would alias com.threebayti.app if sanitised by trimming the dot.
        yield 'trailing dot app id' => ['com.threebayti.app.', 'production', 'android', '1.7.1'];
        yield 'leading dot app id' => ['.com.threebayti.app', 'production', 'android', '1.7.1'];
        yield 'empty channel' => ['com.threebayti.app', '', 'android', '1.7.1'];
        yield 'slash in app id' => ['com/evil', 'production', 'android', '1.7.1'];
    }

    #[Test]
    #[DataProvider('unsafeSegments')]
    public function unsafeSegmentsAreRejectedNotRewritten(string $appId, string $channel, string $platform, string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->pathFor($appId, $channel, $platform, $version);
    }

    #[Test]
    public function promoteNeverOverwritesAnExistingFile(): void
    {
        // e.g. the file still backs a rollback row after its own row was deleted.
        $path = 'ota/com.threebayti.app/production/android/1.8.1.zip';
        $this->filesystem->write($path, 'served-by-a-rollback');
        self::assertTrue($this->storage->isTaken('com.threebayti.app', 'production', 'android', '1.8.1'));
        self::assertFalse($this->storage->isTaken('com.threebayti.app', 'production', 'android', '1.8.2'));

        $staging = $this->storage->stage('new-bytes');
        try {
            $this->storage->promote($staging, 'com.threebayti.app', 'production', 'android', '1.8.1');
            self::fail('promote() must refuse to overwrite');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('refusing to overwrite', $e->getMessage());
        }

        self::assertSame('served-by-a-rollback', $this->filesystem->read($path));
    }

    #[Test]
    public function stagePromoteAndDiscard(): void
    {
        $staging = $this->storage->stage('bytes');
        self::assertStringStartsWith('ota/.staging/', $staging);
        self::assertTrue($this->filesystem->fileExists($staging));

        $path = $this->storage->promote($staging, 'com.threebayti.app', 'production', 'ios', '1.7.1');
        self::assertSame('bytes', $this->filesystem->read($path));
        self::assertSame(
            self::BASE . '/' . $path,
            $this->storage->publicUrlFor('com.threebayti.app', 'production', 'ios', '1.7.1'),
        );

        $this->storage->discard($staging);
        $this->storage->discard($staging); // idempotent
        self::assertFalse($this->filesystem->fileExists($staging));
        self::assertTrue($this->storage->isAvailable(self::BASE . '/' . $path));
    }

    #[Test]
    public function stagingNamesAreUnique(): void
    {
        self::assertNotSame($this->storage->stage('a'), $this->storage->stage('a'));
    }

    #[Test]
    public function legacyRowsKeepResolvingThroughTheirStoredUrl(): void
    {
        // A bundle uploaded before namespacing lives at ota/<platform>/<version>.zip
        // and its row stores that URL; availability + delete follow the URL.
        $this->filesystem->write('ota/android/1.6.3.zip', 'legacy');
        $legacyUrl = self::BASE . '/ota/android/1.6.3.zip';

        self::assertTrue($this->storage->isAvailable($legacyUrl));
        $this->storage->deleteUrl($legacyUrl);
        self::assertFalse($this->storage->isAvailable($legacyUrl));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
