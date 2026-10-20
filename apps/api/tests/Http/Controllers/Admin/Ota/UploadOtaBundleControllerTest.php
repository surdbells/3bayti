<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Bayti\Api\Domain\Ota\OtaBundleStorageService;
use Bayti\Api\Domain\Ota\OtaChecksum;
use Bayti\Api\Domain\Ota\OtaSigning;
use Bayti\Api\Http\Controllers\Admin\Ota\UploadOtaBundleController;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Serializers\OtaBundleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Upload flow: validation, signing policy, servability guards, namespaced
 * storage paths, and file/row consistency (staged write, rows + promote in one
 * transaction, cleanup on any failure). Real Flysystem on a temp dir so the
 * test sees exactly which files survive; the EntityManager is mocked so
 * flush/commit failures can be injected.
 */
#[CoversClass(UploadOtaBundleController::class)]
#[CoversClass(OtaBundleStorageService::class)]
#[CoversClass(OtaChecksum::class)]
#[CoversClass(OtaSigning::class)]
final class UploadOtaBundleControllerTest extends TestCase
{
    private const BASE = 'https://api-v3.3bayti.ae/uploads';
    /** A plain .zip (local file header magic). */
    private const ZIP = "PK\x03\x04fake-zip-body";
    /** What `bundle encrypt` writes: AES ciphertext, no zip magic. */
    private const CIPHER = "\x8a\x11\x5c\xe0ciphertext-body";

    private string $tmpDir;
    private ?string $prevUploadsUrl = null;
    private OtaBundleStorageService $storage;

    /** @var list<OtaBundle> */
    private array $persisted = [];
    /** @var list<OtaBundle> rows that exist in the "database" (committed) */
    private array $committed = [];
    /** @var list<OtaBundle> what activeFor() returns */
    private array $active = [];
    private ?\Throwable $flushFailure = null;
    private ?\Throwable $commitFailure = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prevUploadsUrl = $_ENV['UPLOADS_PUBLIC_URL'] ?? null;
        $_ENV['UPLOADS_PUBLIC_URL'] = self::BASE;
        $this->tmpDir = sys_get_temp_dir() . '/ota-upload-test-' . uniqid('', true);
        mkdir($this->tmpDir, 0755, true);
        $this->storage = new OtaBundleStorageService(new Filesystem(new LocalFilesystemAdapter($this->tmpDir)));
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
    public function storesUnderAppChannelPlatformAndRegistersTheRow(): void
    {
        $response = $this->upload($this->signed(['platform' => 'android', 'version' => '1.7.1', 'min_native' => '1.7', 'notes' => 'Faster']));

        self::assertSame(201, $response->getStatusCode());
        $bundle = json_decode((string) $response->getBody(), true)['bundle'];
        $path = 'ota/com.threebayti.app/production/android/1.7.1.zip';
        self::assertSame(self::BASE . '/' . $path, $bundle['url']);
        self::assertSame(self::encrypted(), $bundle['checksum']);
        self::assertTrue($bundle['signed']);
        self::assertSame('Faster', $bundle['notes']);
        self::assertSame([$path], $this->files(), 'only the final file remains (staging is cleaned up)');
        self::assertSame(self::CIPHER, file_get_contents($this->tmpDir . '/' . $path));
        self::assertCount(1, $this->committed);
        self::assertSame(self::sessionKey(), $this->committed[0]->getSessionKey());
    }

    #[Test]
    public function unsignedAndroidOffProductionGetsTheComputedSha256(): void
    {
        $response = $this->upload(['platform' => 'android', 'version' => '1.7.1', 'channel' => 'beta']);

        self::assertSame(201, $response->getStatusCode());
        $bundle = json_decode((string) $response->getBody(), true)['bundle'];
        self::assertSame(hash('sha256', self::ZIP), $bundle['checksum']);
        self::assertFalse($bundle['signed']);
        self::assertSame(['ota/com.threebayti.app/beta/android/1.7.1.zip'], $this->files());
    }

    #[Test]
    public function unsignedBundlesAreRefusedForIosAndForProduction(): void
    {
        // iOS can never install them (the app embeds a public key)...
        $this->assertBadRequest('iOS only installs SIGNED', ['platform' => 'ios', 'version' => '1.7.1', 'channel' => 'beta']);
        $this->assertBadRequest('iOS only installs SIGNED', ['platform' => 'both', 'version' => '1.7.1', 'channel' => 'beta']);
        // ...and production must be signed on every platform.
        $this->assertBadRequest('Production bundles must be SIGNED', ['platform' => 'android', 'version' => '1.7.1']);
    }

    #[Test]
    public function sameVersionOnTwoChannelsDoesNotOverwriteTheFirstFile(): void
    {
        $this->upload($this->signed(['platform' => 'android', 'version' => '1.7.1', 'channel' => 'production']), self::CIPHER . 'production-bytes');
        $this->upload($this->signed(['platform' => 'android', 'version' => '1.7.1', 'channel' => 'beta']), self::CIPHER . 'beta-bytes');
        $this->upload($this->signed(['platform' => 'android', 'version' => '1.7.1', 'app_id' => 'com.threebayti.staging']), self::CIPHER . 'staging-bytes');

        self::assertSame([
            'ota/com.threebayti.app/beta/android/1.7.1.zip',
            'ota/com.threebayti.app/production/android/1.7.1.zip',
            'ota/com.threebayti.staging/production/android/1.7.1.zip',
        ], $this->files());
        self::assertStringEndsWith('production-bytes', (string) file_get_contents($this->tmpDir . '/ota/com.threebayti.app/production/android/1.7.1.zip'));
        self::assertSame(3, count(array_unique(array_map(static fn (OtaBundle $b): string => $b->getUrl(), $this->committed))));
    }

    #[Test]
    public function bothPlatformsGetTheirOwnFileAndRow(): void
    {
        $response = $this->upload($this->signed(['platform' => 'both', 'version' => '1.7.1']));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(2, json_decode((string) $response->getBody(), true)['bundles']);
        self::assertSame([
            'ota/com.threebayti.app/production/android/1.7.1.zip',
            'ota/com.threebayti.app/production/ios/1.7.1.zip',
        ], $this->files());
    }

    #[Test]
    public function aFailedFlushLeavesNoFileBehind(): void
    {
        $this->flushFailure = new \RuntimeException('db down');

        $this->assertThrowsMessage('db down', fn () => $this->upload($this->signed(['platform' => 'both', 'version' => '1.7.1'])));

        self::assertSame([], $this->files());
        self::assertSame([], $this->committed);
    }

    #[Test]
    public function aFailedCommitRemovesTheAlreadyPromotedFiles(): void
    {
        $this->commitFailure = new \RuntimeException('commit failed');

        $this->assertThrowsMessage('commit failed', fn () => $this->upload($this->signed(['platform' => 'both', 'version' => '1.7.1'])));

        self::assertSame([], $this->files());
        self::assertSame([], $this->committed);
    }

    #[Test]
    public function aFailedPromoteRollsBackAndRemovesEarlierCopies(): void
    {
        // A directory squatting on the ios target makes that copy fail after
        // the android copy already succeeded.
        mkdir($this->tmpDir . '/ota/com.threebayti.app/production/ios/1.7.1.zip', 0755, true);

        $threw = false;
        try {
            $this->upload($this->signed(['platform' => 'both', 'version' => '1.7.1']));
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertTrue($threw);
        self::assertSame([], $this->files(), 'android copy and staging file are both removed');
        self::assertSame([], $this->committed);
    }

    #[Test]
    public function signedBundleKeepsTheEncryptedChecksum(): void
    {
        $response = $this->upload($this->signed(['platform' => 'ios', 'version' => '1.7.1']));

        self::assertSame(201, $response->getStatusCode());
        $bundle = json_decode((string) $response->getBody(), true)['bundle'];
        self::assertSame(self::encrypted(), $bundle['checksum']);
        self::assertTrue($bundle['signed']);
    }

    #[Test]
    public function signedBundleRejectsThePlainChecksum(): void
    {
        $this->assertBadRequest(
            'PLAIN sha256',
            ['platform' => 'ios', 'version' => '1.7.1', 'session_key' => self::sessionKey(), 'checksum' => hash('sha256', 'x')],
            bytes: self::CIPHER,
        );
        $this->assertBadRequest(
            'encrypted checksum',
            ['platform' => 'ios', 'version' => '1.7.1', 'session_key' => self::sessionKey()],
            bytes: self::CIPHER,
        );
        $this->assertBadRequest(
            'exactly 512 hex',
            ['platform' => 'ios', 'version' => '1.7.1', 'session_key' => self::sessionKey(), 'checksum' => str_repeat('a', 511)],
            bytes: self::CIPHER,
        );
    }

    #[Test]
    public function signedBundleRejectsThePlainZip(): void
    {
        // `bundle encrypt` leaves the plain zip next to <zip>_encrypted.zip;
        // uploading it as signed would fail AES decryption on every device.
        $this->assertBadRequest('looks like the PLAIN .zip', $this->signed(['platform' => 'ios', 'version' => '1.7.1']), bytes: self::ZIP);
    }

    #[Test]
    public function signedBundleRejectsAMalformedSessionKey(): void
    {
        $this->assertBadRequest(
            'session_key must be the ivSessionKey',
            ['platform' => 'ios', 'version' => '1.7.1', 'session_key' => 'iv:key', 'checksum' => self::encrypted()],
            bytes: self::CIPHER,
        );
    }

    #[Test]
    public function rejectsPreReleaseVersions(): void
    {
        $this->assertBadRequest('pre-release', $this->signed(['platform' => 'android', 'version' => '1.7.1-beta.1']));
    }

    #[Test]
    public function rejectsNonCanonicalVersions(): void
    {
        $this->assertBadRequest('version must be numeric', $this->signed(['platform' => 'android', 'version' => '1.08']));
    }

    #[Test]
    public function rejectsUnsafeChannelAndAppId(): void
    {
        $this->assertBadRequest('channel', $this->signed(['platform' => 'android', 'version' => '1.7.1', 'channel' => '../prod']));
        $this->assertBadRequest('app_id', $this->signed(['platform' => 'android', 'version' => '1.7.1', 'app_id' => 'com/evil']));
        // A trailing dot would alias com.threebayti.app's directory.
        $this->assertBadRequest('app_id', $this->signed(['platform' => 'android', 'version' => '1.7.1', 'app_id' => 'com.threebayti.app.']));
    }

    #[Test]
    public function rejectsOversizedBundlesWithTheConfiguredLimit(): void
    {
        $query = $this->signed(['platform' => 'android', 'version' => '1.7.1']);
        $this->assertBadRequest('max 50 MB', $query, size: UploadOtaBundleController::MAX_BYTES + 1);
        $this->assertBadRequest('max 50 MB', $query, error: UPLOAD_ERR_FORM_SIZE);
    }

    #[Test]
    public function aPhpIniLimitNamesTheSettingToRaise(): void
    {
        // PHP's own upload_max_filesize tripped (server not configured per
        // OTA-DEPLOY.md), not our 50 MB cap: say so.
        $this->assertBadRequest(
            'PHP limit (upload_max_filesize=',
            $this->signed(['platform' => 'android', 'version' => '1.7.1']),
            error: UPLOAD_ERR_INI_SIZE,
        );
    }

    #[Test]
    public function duplicateVersionIsRejectedBeforeAnythingIsWritten(): void
    {
        $existing = new OtaBundle('com.threebayti.app', 'ios', 'production', '1.7.1', 'https://x/1.zip', hash('sha256', 'x'), '0.0.0');

        $this->assertBadRequest('already exists', $this->signed(['platform' => 'both', 'version' => '1.7.1']), existing: $existing);
    }

    #[Test]
    public function aVersionWhoseFileStillBacksAnotherRowIsRefused(): void
    {
        // Upload 1.8.1, roll back to it (row 1.8.3 serves 1.8.1's file), delete
        // row 1.8.1 (its file is kept): re-uploading 1.8.1 must not replace the
        // bytes the live rollback row serves under its checksum.
        $path = 'ota/com.threebayti.app/production/android/1.8.1.zip';
        mkdir(dirname($this->tmpDir . '/' . $path), 0755, true);
        file_put_contents($this->tmpDir . '/' . $path, 'served-by-rollback-1.8.3');

        $this->assertBadRequest('still served by another bundle', $this->signed(['platform' => 'android', 'version' => '1.8.1']));

        self::assertSame('served-by-rollback-1.8.3', file_get_contents($this->tmpDir . '/' . $path));
    }

    #[Test]
    public function aVersionNoDeviceCouldEverBeServedIsRefused(): void
    {
        $this->active = [new OtaBundle('com.threebayti.app', 'android', 'production', '1.7.3', 'https://x/1.7.3.zip', self::encrypted(), '1.7')];

        // A "hotfix" below the live release, for the same shells: never served.
        $this->assertBadRequest('would never be served', $this->signed(['platform' => 'android', 'version' => '1.7.2.1', 'min_native' => '1.7']));

        // A lower version for OLDER shells is fine: they can't run 1.7.3.
        $response = $this->upload($this->signed(['platform' => 'android', 'version' => '1.6.9', 'min_native' => '1.6']));
        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function aLowerVersionForShellsAboveTheLiveBundlesMaxIsAllowed(): void
    {
        // Production 1.7.3 for store shell 1.8 only (max defaults to min).
        $this->active = [new OtaBundle('com.threebayti.app', 'android', 'production', '1.7.3', 'https://x/1.7.3.zip', self::encrypted(), '1.8')];

        // Same shell, lower version: never served, and the message shows both ranges.
        $this->assertBadRequest(
            'active bundle 1.7.3 (native 1.8) is at least as high and runs on every app version this one targets (native 1.8)',
            $this->signed(['platform' => 'android', 'version' => '1.7.2', 'min_native' => '1.8']),
        );

        // A lower version for the TestFlight shell 1.8.1 is served to it.
        self::assertSame(201, $this->upload($this->signed(['platform' => 'android', 'version' => '1.7.0', 'min_native' => '1.8.1']))->getStatusCode());
        // As is one whose range reaches beyond 1.8 (1.8.x / 1.9 shells get it).
        self::assertSame(201, $this->upload($this->signed(['platform' => 'android', 'version' => '1.7.1', 'min_native' => '1.8', 'max_native' => '1.9']))->getStatusCode());
    }

    #[Test]
    public function maxNativeDefaultsToMinNativeAndIsStored(): void
    {
        $response = $this->upload($this->signed(['platform' => 'both', 'version' => '1.7.1', 'min_native' => '1.8']));

        self::assertSame(201, $response->getStatusCode());
        foreach (json_decode((string) $response->getBody(), true)['bundles'] as $shape) {
            self::assertSame('1.8', $shape['min_native_version']);
            self::assertSame('1.8', $shape['max_native_version']);
        }
        self::assertCount(2, $this->committed);
        foreach ($this->committed as $bundle) {
            self::assertSame('1.8', $bundle->getMaxNativeVersion(), 'stored explicitly, not left null');
        }
    }

    #[Test]
    public function anExplicitMaxNativeIsStoredAndReturned(): void
    {
        $response = $this->upload($this->signed(['platform' => 'android', 'version' => '1.7.1', 'min_native' => '1.8', 'max_native' => '1.9']));

        self::assertSame(201, $response->getStatusCode());
        $shape = json_decode((string) $response->getBody(), true)['bundle'];
        self::assertSame('1.8', $shape['min_native_version']);
        self::assertSame('1.9', $shape['max_native_version']);
        self::assertSame('1.9', $this->committed[0]->getMaxNativeVersion());
    }

    #[Test]
    public function minNativeIsRequired(): void
    {
        $query = ['platform' => 'android', 'version' => '1.7.1'];
        $this->assertBadRequest('min_native is required', $this->signed($query + ['min_native' => null]));
        $this->assertBadRequest('min_native is required', $this->signed($query + ['min_native' => '  ']));
        // A max alone does not stand in for the min.
        $this->assertBadRequest('min_native is required', $this->signed($query + ['min_native' => null, 'max_native' => '1.9']));
    }

    #[Test]
    public function rejectsAnInvalidNativeRange(): void
    {
        $query = ['platform' => 'android', 'version' => '1.7.1'];
        $this->assertBadRequest('min_native must be numeric', $this->signed($query + ['min_native' => '1.8-beta']));
        $this->assertBadRequest('max_native must be numeric', $this->signed($query + ['min_native' => '1.8', 'max_native' => '1.08']));
        $this->assertBadRequest(
            'max_native (1.7.9) must not be lower than min_native (1.8)',
            $this->signed($query + ['min_native' => '1.8', 'max_native' => '1.7.9']),
        );
        // "0" is falsy in PHP: it must be validated, not replaced by the min.
        $this->assertBadRequest('max_native (0) must not be lower than', $this->signed($query + ['min_native' => '1.8', 'max_native' => '0']));
        // The old portal / release-script default: matches no real device.
        $this->assertBadRequest('matches no installed app', $this->signed($query + ['min_native' => '0.0.0']));
    }

    // ── helpers ────────────────────────────────────────────────────────

    private static function encrypted(): string
    {
        return str_repeat('ab', 256); // 512 hex, RSA-2048
    }

    private static function sessionKey(): string
    {
        return base64_encode(str_repeat("\x01", 16)) . ':' . base64_encode(str_repeat("\x02", 256));
    }

    /**
     * @param array<string, ?string> $query
     * @return array<string, ?string>
     */
    private function signed(array $query): array
    {
        return $query + ['session_key' => self::sessionKey(), 'checksum' => self::encrypted()];
    }

    /**
     * min_native is required, so it defaults to the production shell (1.8)
     * here unless the test sets it; a null value omits the key entirely.
     *
     * @param array<string, ?string> $query
     */
    private function upload(
        array $query,
        ?string $bytes = null,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?OtaBundle $existing = null,
    ): \Psr\Http\Message\ResponseInterface {
        if (!array_key_exists('min_native', $query)) {
            $query['min_native'] = '1.8';
        }
        $query = array_filter($query, static fn (?string $v): bool => $v !== null);
        $bytes ??= isset($query['session_key']) ? self::CIPHER : self::ZIP;
        $controller = new UploadOtaBundleController(
            new ResponseFactory(),
            $this->em($existing),
            $this->storage,
            new OtaBundleSerializer(),
        );

        return $controller($this->request($query, $bytes, $size, $error));
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query, string $bytes, ?int $size, int $error): ServerRequestInterface
    {
        $upload = $this->createMock(UploadedFileInterface::class);
        $upload->method('getError')->willReturn($error);
        $upload->method('getSize')->willReturn($size ?? strlen($bytes));
        $upload->method('getStream')->willReturnCallback(static fn () => (new StreamFactory())->createStream($bytes));

        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/v3/admin/ota/bundles')
            ->withQueryParams($query)
            ->withUploadedFiles(['file' => $upload]);
    }

    private function em(?OtaBundle $existing): EntityManagerInterface
    {
        $repo = $this->createMock(OtaBundleRepository::class);
        $repo->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?OtaBundle => $existing !== null && $criteria['platform'] === $existing->getPlatform() ? $existing : null,
        );
        $repo->method('activeFor')->willReturnCallback(
            fn (string $appId, string $platform, string $channel): array => array_values(array_filter(
                $this->active,
                static fn (OtaBundle $b): bool => $b->getAppId() === $appId && $b->getPlatform() === $platform && $b->getChannel() === $channel,
            )),
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        $em->method('flush')->willReturnCallback(function (): void {
            if ($this->flushFailure !== null) {
                throw $this->flushFailure;
            }
        });
        // Mirrors Doctrine: run the callback, then commit; anything thrown
        // (by the callback or the commit) rolls back = nothing is committed.
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $fn): mixed {
            $this->persisted = [];
            $result = $fn();
            if ($this->commitFailure !== null) {
                throw $this->commitFailure;
            }
            array_push($this->committed, ...$this->persisted);

            return $result;
        });

        return $em;
    }

    /**
     * @param array<string, ?string> $query
     */
    private function assertBadRequest(
        string $messageFragment,
        array $query,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?OtaBundle $existing = null,
        ?string $bytes = null,
    ): void {
        $before = $this->files();
        try {
            $this->upload($query, $bytes, $size, $error, $existing);
            self::fail('Expected a 400 for ' . json_encode($query));
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString($messageFragment, $e->getMessage());
        }
        self::assertSame($before, $this->files(), 'a rejected upload writes nothing');
        self::assertSame([], $this->committed, 'a rejected upload registers nothing');
    }

    private function assertThrowsMessage(string $message, callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected an exception');
        } catch (\RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /** @return list<string> every file under the uploads root, sorted */
    private function files(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->tmpDir) + 1));
            }
        }
        sort($out);

        return $out;
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
