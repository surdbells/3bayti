<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Bayti\Api\Http\Controllers\Ota\CheckOtaUpdateController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /v3/ota/updates through the real Slim app (routing, no auth), with the
 * active-bundle set stubbed on the repository.
 */
#[CoversClass(CheckOtaUpdateController::class)]
final class CheckOtaUpdateControllerTest extends HttpTestCase
{
    /** @var list<array{string, string, string}> */
    private array $activeForCalls = [];

    #[Test]
    public function servesTheBundleWhenNewerAndCompatible(): void
    {
        $this->active([$this->bundle('1.7.1', '1.7', sessionKey: 'iv:key', notes: 'Faster checkout')]);

        $body = $this->check(['version_name' => '1.7.0', 'version_build' => '1.7']);

        self::assertSame('1.7.1', $body['version']);
        self::assertSame('https://api-v3.3bayti.ae/uploads/ota/1.7.1.zip', $body['url']);
        self::assertSame('checksum-1.7.1', $body['checksum']);
        self::assertSame('iv:key', $body['session_key']);
        // Notes travel as `comment` (+ `notes`), never `message`: the plugin's
        // getLatest() rejects an update response carrying a message.
        self::assertSame('Faster checkout', $body['comment']);
        self::assertSame('Faster checkout', $body['notes']);
        self::assertArrayNotHasKey('message', $body);
        self::assertArrayNotHasKey('kind', $body);
        self::assertArrayNotHasKey('error', $body);
        self::assertSame([['com.threebayti.app', 'android', 'production']], $this->activeForCalls);
    }

    #[Test]
    public function anUpdateWithoutNotesCarriesNoNoteKeys(): void
    {
        $this->active([$this->bundle('1.7.1', '1.7')]);

        $body = $this->check(['version_name' => '1.7.0', 'version_build' => '1.7']);

        self::assertSame('1.7.1', $body['version']);
        self::assertArrayNotHasKey('comment', $body);
        self::assertArrayNotHasKey('message', $body);
    }

    #[Test]
    public function noUpdateFollowsThePluginUpToDateContract(): void
    {
        $this->active([]);

        $body = $this->check(['version_name' => '1.7.0', 'version_build' => '1.7']);

        // Without kind=up_to_date the plugin counts every check as a failed
        // download (downloadFailed event + download_fail stat).
        self::assertSame([
            'kind' => 'up_to_date',
            'error' => 'no_new_version_available',
            'message' => 'No update',
            'version' => '',
            'url' => '',
        ], $body);
    }

    #[Test]
    public function aLeadingZeroNativeBuildIsStillComparable(): void
    {
        $this->active([$this->bundle('1.7.1', '1.7')]);

        self::assertSame('1.7.1', $this->check(['version_name' => '1.7.0', 'version_build' => '1.07'])['version']);
    }

    #[Test]
    public function decodesTheRawBodyWhenNoParserRan(): void
    {
        $this->active([$this->bundle('1.7.1', '1.7')]);
        $request = (new \Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('POST', '/v3/ota/updates')
            ->withHeader('Content-Type', 'text/plain');
        $request->getBody()->write((string) json_encode(['platform' => 'android', 'version_name' => '1.7.0', 'version_build' => '1.7']));
        $request->getBody()->rewind();

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1.7.1', $this->jsonBody($response)['version']);
    }

    #[Test]
    public function anOversizedBodyIsTreatedAsMalformed(): void
    {
        $this->active([$this->bundle('1.7.1', '0.0.0')]);

        $body = $this->check([
            'version_name' => '1.0.0',
            'version_build' => '1.7',
            'custom_id' => str_repeat('x', 20 * 1024),
        ]);

        self::assertSame('', $body['version']);
        self::assertSame('up_to_date', $body['kind']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unprovableNativeBuilds(): iterable
    {
        yield 'version_build missing' => [['version_name' => '1.0.0']];
        yield 'version_build empty' => [['version_name' => '1.0.0', 'version_build' => '']];
        yield 'version_build whitespace' => [['version_name' => '1.0.0', 'version_build' => '  ']];
        yield 'version_build malformed' => [['version_name' => '1.0.0', 'version_build' => 'unknown']];
    }

    /**
     * @param array<string, string> $device
     */
    #[Test]
    #[DataProvider('unprovableNativeBuilds')]
    public function failsClosedWhenTheNativeBuildCannotBeChecked(array $device): void
    {
        // Even a bundle with no native requirement is withheld: the gate can't
        // be evaluated, so the endpoint must not guess.
        $this->active([$this->bundle('1.7.1', '0.0.0')]);

        $body = $this->check($device);

        self::assertSame('', $body['version']);
        self::assertSame('No update', $body['message']);
    }

    #[Test]
    public function picksTheHighestVersionNotTheMostRecentlyCreated(): void
    {
        // Upload order: 1.7.3 first, then a late 1.7.2 hotfix registered via
        // ota:publish. The repo returns them in created_at order.
        $this->active([
            $this->bundle('1.7.3', '1.7'),
            $this->bundle('1.7.2', '1.7'),
        ]);

        self::assertSame('1.7.3', $this->check(['version_name' => '1.7.0', 'version_build' => '1.7'])['version']);
        // A device already on 1.7.3 is not offered the "newer row" 1.7.2.
        self::assertSame('', $this->check(['version_name' => '1.7.3', 'version_build' => '1.7'])['version']);
    }

    #[Test]
    public function comparesVersionsNumerically(): void
    {
        $this->active([$this->bundle('1.7.9', '1.7'), $this->bundle('1.7.10', '1.7')]);

        self::assertSame('1.7.10', $this->check(['version_name' => '1.7.0', 'version_build' => '1.7'])['version']);
    }

    #[Test]
    public function olderShellsGetTheHighestBundleTheyCanRun(): void
    {
        $this->active([
            $this->bundle('1.7.4', '1.7'),
            $this->bundle('1.8.0', '1.8'), // needs the new native shell
        ]);

        self::assertSame('1.7.4', $this->check(['version_name' => '1.7.0', 'version_build' => '1.7'])['version']);
        self::assertSame('1.8.0', $this->check(['version_name' => '1.7.0', 'version_build' => '1.8'])['version']);
    }

    #[Test]
    public function noUpdateWhenEveryBundleNeedsANewerShell(): void
    {
        $this->active([$this->bundle('1.8.0', '1.8')]);

        self::assertSame('', $this->check(['version_name' => '1.7.0', 'version_build' => '1.7.9'])['version']);
    }

    #[Test]
    public function noUpdateWhenTheDeviceIsAlreadyCurrent(): void
    {
        $this->active([$this->bundle('1.7.1', '1.7', notes: 'notes')]);

        $body = $this->check(['version_name' => '1.7.1', 'version_build' => '1.7']);

        self::assertSame('', $body['version']);
        self::assertSame('No update', $body['message']);
        self::assertArrayNotHasKey('notes', $body);
    }

    #[Test]
    public function noUpdateForAnUnknownPlatform(): void
    {
        $this->active([$this->bundle('1.7.1', '1.7')]);

        self::assertSame('', $this->check(['platform' => 'windows', 'version_name' => '1.0', 'version_build' => '1.7'])['version']);
    }

    #[Test]
    public function noUpdateWhenNothingIsActive(): void
    {
        $this->active([]);

        self::assertSame('', $this->check(['version_name' => '1.0', 'version_build' => '1.7'])['version']);
    }

    #[Test]
    public function usesTheRequestedChannelAndAppId(): void
    {
        $this->active([$this->bundle('1.7.1', '1.7')]);

        $this->check([
            'version_name' => '1.0',
            'version_build' => '1.7',
            'defaultChannel' => 'beta',
            'app_id' => 'com.threebayti.staging',
        ]);

        self::assertSame([['com.threebayti.staging', 'android', 'beta']], $this->activeForCalls);
    }

    /**
     * @param array<string, string> $device
     * @return array<string, mixed>
     */
    private function check(array $device): array
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/ota/updates', $device + ['platform' => 'android']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->jsonBody($response);
    }

    /**
     * @param list<OtaBundle> $bundles
     */
    private function active(array $bundles): void
    {
        $repo = $this->createMock(OtaBundleRepository::class);
        $repo->method('activeFor')->willReturnCallback(
            function (string $appId, string $platform, string $channel) use ($bundles): array {
                $this->activeForCalls[] = [$appId, $platform, $channel];

                return $bundles;
            },
        );
        $this->bind(EntityManagerInterface::class, $this->stubEm(
            static function (\PHPUnit\Framework\MockObject\MockObject $em) use ($repo): void {
                $em->method('getRepository')->willReturn($repo);
            },
        ));
    }

    private function bundle(string $version, string $minNative, ?string $sessionKey = null, ?string $notes = null): OtaBundle
    {
        return new OtaBundle(
            'com.threebayti.app',
            'android',
            'production',
            $version,
            "https://api-v3.3bayti.ae/uploads/ota/{$version}.zip",
            "checksum-{$version}",
            $minNative,
            $sessionKey,
            $notes,
        );
    }
}
