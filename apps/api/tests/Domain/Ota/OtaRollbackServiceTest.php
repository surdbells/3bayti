<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Bayti\Api\Domain\Ota\OtaBundleStorageService;
use Bayti\Api\Domain\Ota\OtaRollbackException;
use Bayti\Api\Domain\Ota\OtaRollbackService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One-click rollback: re-publishes an old bundle's file under the next unused
 * version and deactivates the active bundles its native range overlaps (and
 * no others). Real Flysystem (temp dir) for
 * the file-availability check; the EntityManager + repository are mocked.
 */
#[CoversClass(OtaRollbackService::class)]
#[CoversClass(OtaBundleStorageService::class)]
#[CoversClass(OtaBundle::class)]
final class OtaRollbackServiceTest extends TestCase
{
    private const BASE = 'https://api-v3.3bayti.ae/uploads';

    private string $tmpDir;
    private ?string $prevUploadsUrl = null;
    private Filesystem $filesystem;
    private OtaBundleStorageService $storage;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevUploadsUrl = $_ENV['UPLOADS_PUBLIC_URL'] ?? null;
        $_ENV['UPLOADS_PUBLIC_URL'] = self::BASE;
        $this->tmpDir = sys_get_temp_dir() . '/ota-rollback-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->filesystem = new Filesystem(new LocalFilesystemAdapter($this->tmpDir));
        $this->storage = new OtaBundleStorageService($this->filesystem);
        $this->persisted = [];
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
    public function republishesTheSourceFileUnderTheNextVersionAndDeactivatesTheRest(): void
    {
        $good = $this->bundle('1.6.3', sessionKey: 'iv-key', notes: 'Sept release', storeFile: true);
        $good->deactivate();
        $bad = $this->bundle('1.6.8', notes: 'Meet Ain', storeFile: true);

        $service = new OtaRollbackService($this->em(['1.6.3', '1.6.8'], [$bad]), $this->storage);
        $result = $service->rollbackTo($good);

        $rollback = $result['bundle'];
        self::assertSame('1.6.9', $rollback->getVersion());
        self::assertSame($good->getUrl(), $rollback->getUrl());
        self::assertSame($good->getChecksum(), $rollback->getChecksum());
        self::assertSame('iv-key', $rollback->getSessionKey());
        self::assertSame($good->getMinNativeVersion(), $rollback->getMinNativeVersion());
        self::assertSame($good->getMaxNativeVersion(), $rollback->getMaxNativeVersion());
        self::assertSame('android', $rollback->getPlatform());
        self::assertSame('1.6.3', $rollback->getRollbackOfVersion());
        self::assertNull($rollback->getNotes(), 'a rollback must not re-show old "what\'s new" notes');
        self::assertTrue($rollback->isActive());

        self::assertFalse($bad->isActive());
        self::assertSame([$bad], $result['deactivated']);
        self::assertSame([$rollback], $this->persisted);
    }

    #[Test]
    public function theRollbackKeepsTheSourcesNativeRangeAndIsServedOnlyInsideIt(): void
    {
        $good = $this->bundle('1.6.3', storeFile: true, minNative: '1.8', maxNative: '1.9');
        $good->deactivate();
        $bad = $this->bundle('1.6.8', storeFile: true, minNative: '1.8', maxNative: '1.9');
        $testShell = $this->bundle('1.6.5', storeFile: true, minNative: '2.0');

        $service = new OtaRollbackService($this->em(['1.6.3', '1.6.5', '1.6.8'], [$bad, $testShell]), $this->storage);
        $result = $service->rollbackTo($good);

        $rollback = $result['bundle'];
        self::assertSame('1.6.9', $rollback->getVersion());
        self::assertSame('1.8', $rollback->getMinNativeVersion());
        self::assertSame('1.9', $rollback->getMaxNativeVersion());
        self::assertTrue($rollback->supportsNative('1.9'));
        self::assertFalse($rollback->supportsNative('2.0'), 'the rollback never reaches shells the source did not support');
        self::assertSame([$bad], $result['deactivated']);
        self::assertTrue($testShell->isActive(), 'the 2.0 test shell is outside the rollback range: its bundle keeps serving it');
    }

    #[Test]
    public function rollingBackProductionLeavesTheTestShellBundleLiveAndViceVersa(): void
    {
        // Production [1.8] and TestFlight [1.8.1] are both live side by side.
        $goodProd = $this->bundle('1.8.2', storeFile: true, minNative: '1.8');
        $goodProd->deactivate();
        $badProd = $this->bundle('1.8.5', storeFile: true, minNative: '1.8');
        $test = $this->bundle('1.8.4', storeFile: true, minNative: '1.8.1');

        $prodResult = (new OtaRollbackService($this->em(['1.8.2', '1.8.4', '1.8.5'], [$badProd, $test]), $this->storage))
            ->rollbackTo($goodProd);

        self::assertSame([$badProd], $prodResult['deactivated']);
        self::assertTrue($test->isActive(), 'a production rollback never pulls the test-shell bundle');

        $goodTest = $this->bundle('1.8.3', storeFile: true, minNative: '1.8.1');
        $goodTest->deactivate();
        $prodRollback = $prodResult['bundle'];

        $testResult = (new OtaRollbackService($this->em(['1.8.2', '1.8.3', '1.8.4', '1.8.5', '1.8.6'], [$test, $prodRollback]), $this->storage))
            ->rollbackTo($goodTest);

        self::assertSame('1.8.7', $testResult['bundle']->getVersion());
        self::assertSame([$test], $testResult['deactivated']);
        self::assertTrue($prodRollback->isActive(), 'a test-shell rollback never pulls the production bundle');
    }

    #[Test]
    public function aBadBundleThatOnlyPartlyOverlapsTheRollbackRangeIsStillPulled(): void
    {
        // The bad release reached 1.7 - 1.9; the good one ran on 1.8 only.
        $good = $this->bundle('1.6.3', storeFile: true, minNative: '1.8');
        $good->deactivate();
        $bad = $this->bundle('1.6.8', storeFile: true, minNative: '1.7', maxNative: '1.9');
        $older = $this->bundle('1.6.6', storeFile: true, minNative: '1.6', maxNative: '1.7.9');

        $result = (new OtaRollbackService($this->em(['1.6.3', '1.6.6', '1.6.8'], [$bad, $older]), $this->storage))
            ->rollbackTo($good);

        self::assertSame([$bad], $result['deactivated']);
        self::assertTrue($older->isActive(), '[1.6 - 1.7.9] does not reach 1.8: it is left alone');
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function unreachableSources(): iterable
    {
        yield 'legacy row: old 0.0.0 min default, no max' => ['0.0.0', null];
        yield 'legacy row: bare 0 min, no max' => ['0', null];
        yield 'explicit 0.0.0 range' => ['0.0.0', '0.0.0'];
    }

    #[Test]
    #[DataProvider('unreachableSources')]
    public function refusesASourceWhoseNativeRangeMatchesNoInstalledApp(string $minNative, ?string $maxNative): void
    {
        $source = $this->bundle('1.8.2', storeFile: true, minNative: $minNative, maxNative: $maxNative);
        if ($maxNative === null) {
            (new \ReflectionProperty(OtaBundle::class, 'maxNativeVersion'))->setValue($source, null);
        }
        $source->deactivate();
        $live = $this->bundle('1.8.3', storeFile: true, minNative: '0.0.0');
        (new \ReflectionProperty(OtaBundle::class, 'maxNativeVersion'))->setValue($live, null);

        $service = new OtaRollbackService($this->em(['1.8.2', '1.8.3'], [$live]), $this->storage);

        try {
            $service->rollbackTo($source);
            self::fail('Expected the rollback to be refused');
        } catch (OtaRollbackException $e) {
            self::assertStringContainsString('which no installed app reports', $e->getMessage());
            self::assertStringContainsString('android 1.8.2', $e->getMessage());
        }
        self::assertSame([], $this->persisted, 'no rollback row is created');
        self::assertTrue($live->isActive(), 'nothing is deactivated');
    }

    #[Test]
    public function aLegacyZeroMinRowWidenedByTheOperatorCanBeRolledBackTo(): void
    {
        // OTA-DEPLOY.md: max_native_version set on a legacy 0.0.0 row.
        $source = $this->bundle('1.8.2', storeFile: true, minNative: '0.0.0', maxNative: '1.8');
        $source->deactivate();
        $bad = $this->bundle('1.8.5', storeFile: true, minNative: '1.8');

        $result = (new OtaRollbackService($this->em(['1.8.2', '1.8.5'], [$bad]), $this->storage))->rollbackTo($source);

        self::assertTrue($result['bundle']->supportsNative('1.8'));
        self::assertSame([$bad], $result['deactivated']);
    }

    #[Test]
    public function versionIsAboveTheHighestEverPublishedNotJustTheActiveOne(): void
    {
        $good = $this->bundle('1.6.3', storeFile: true);
        // 1.6.10 was published and deleted/deactivated earlier; devices may have it.
        $service = new OtaRollbackService($this->em(['1.6.3', '1.6.10', '1.6.8'], []), $this->storage);

        self::assertSame('1.6.11', $service->rollbackTo($good)['bundle']->getVersion());
    }

    #[Test]
    public function rollingBackToARollbackKeepsTheOriginalContentLabel(): void
    {
        $original = $this->bundle('1.6.3', storeFile: true);
        $firstRollback = OtaBundle::rollbackOf($original, '1.6.9');

        $service = new OtaRollbackService($this->em(['1.6.3', '1.6.9', '1.6.10'], []), $this->storage);
        $result = $service->rollbackTo($firstRollback);

        self::assertSame('1.6.11', $result['bundle']->getVersion());
        self::assertSame('1.6.3', $result['bundle']->getRollbackOfVersion());
        self::assertSame($original->getUrl(), $result['bundle']->getUrl());
    }

    #[Test]
    public function refusesWhenTheHostedFileIsGone(): void
    {
        $good = $this->bundle('1.6.3', storeFile: false);
        $service = new OtaRollbackService($this->em(['1.6.3', '1.6.8'], []), $this->storage);

        $this->expectException(OtaRollbackException::class);
        try {
            $service->rollbackTo($good);
        } finally {
            self::assertSame([], $this->persisted);
        }
    }

    #[Test]
    public function allowsExternallyHostedBundles(): void
    {
        $cdn = new OtaBundle('com.threebayti.app', 'ios', 'production', '1.6.3', 'https://cdn.example.com/ota/ios/1.6.3.zip', 'abc', '1.6.0');
        $service = new OtaRollbackService($this->em(['1.6.3', '1.6.4'], []), $this->storage);

        self::assertSame('1.6.5', $service->rollbackTo($cdn)['bundle']->getVersion());
    }

    #[Test]
    public function deleteUrlRemovesOnlyFilesWeHost(): void
    {
        $bundle = $this->bundle('1.6.3', storeFile: true);
        self::assertTrue($this->storage->isAvailable($bundle->getUrl()));

        $this->storage->deleteUrl('https://cdn.example.com/ota/android/1.6.3.zip');
        self::assertTrue($this->storage->isAvailable($bundle->getUrl()));

        $this->storage->deleteUrl($bundle->getUrl());
        self::assertFalse($this->storage->isAvailable($bundle->getUrl()));
    }

    /**
     * @param list<string>    $versions all versions published in scope
     * @param list<OtaBundle> $active   currently active bundles in scope
     */
    private function em(array $versions, array $active): EntityManagerInterface
    {
        $repo = $this->createMock(OtaBundleRepository::class);
        $repo->method('versionsFor')->willReturn($versions);
        $repo->method('activeFor')->willReturn($active);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $fn): mixed => $fn(),
        );
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });

        return $em;
    }

    private function bundle(
        string $version,
        ?string $sessionKey = null,
        ?string $notes = null,
        bool $storeFile = false,
        string $minNative = '1.6.0',
        ?string $maxNative = null,
    ): OtaBundle {
        $path = $this->storage->pathFor('com.threebayti.app', 'production', 'android', $version);
        $url = self::BASE . '/' . $path;
        if ($storeFile) {
            $this->filesystem->write($path, 'zip-bytes');
        }

        return new OtaBundle(
            'com.threebayti.app',
            'android',
            'production',
            $version,
            $url,
            'checksum-' . $version,
            $minNative,
            $sessionKey,
            $notes,
            maxNativeVersion: $maxNative,
        );
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
