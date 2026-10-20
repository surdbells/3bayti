<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Ota;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Http\Controllers\Admin\Ota\SetOtaBundleActiveController;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Serializers\OtaBundleSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * PATCH /v3/admin/ota/bundles/{id}: only is_active changes (the native range
 * is kept), and a row whose native range matches no installed app can't be
 * re-activated into an active bundle that silently serves nobody.
 */
#[CoversClass(SetOtaBundleActiveController::class)]
final class SetOtaBundleActiveControllerTest extends TestCase
{
    private int $flushes = 0;

    #[Test]
    public function reactivatingKeepsTheNativeRange(): void
    {
        $bundle = $this->bundle('1.8', '1.9');
        $bundle->deactivate();

        $response = $this->patch($bundle, true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($bundle->isActive());
        self::assertSame('1.8', $bundle->getMinNativeVersion());
        self::assertSame('1.9', $bundle->getMaxNativeVersion());
        $shape = json_decode((string) $response->getBody(), true)['bundle'];
        self::assertSame(['1.8', '1.9'], [$shape['min_native_version'], $shape['max_native_version']]);
        self::assertSame(1, $this->flushes);
    }

    #[Test]
    public function refusesToActivateARowThatReachesNoInstalledApp(): void
    {
        // A legacy row from the old 0.0.0 min default, no max: [0.0.0, 0.0.0].
        $legacy = $this->bundle('0.0.0');
        (new \ReflectionProperty(OtaBundle::class, 'maxNativeVersion'))->setValue($legacy, null);
        $legacy->deactivate();

        try {
            $this->patch($legacy, true);
            self::fail('Expected a 400');
        } catch (HttpException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('android 1.8.2 is published for native app version 0.0.0', $e->getMessage());
            self::assertStringContainsString('max_native_version', $e->getMessage());
        }
        self::assertFalse($legacy->isActive());
        self::assertSame(0, $this->flushes);
    }

    #[Test]
    public function aWidenedLegacyRowCanBeActivatedAndAnyRowDeactivated(): void
    {
        $widened = $this->bundle('0.0.0', '1.8');
        $widened->deactivate();
        self::assertSame(200, $this->patch($widened, true)->getStatusCode());
        self::assertTrue($widened->isActive());

        $unreachable = $this->bundle('0.0.0');
        self::assertSame(200, $this->patch($unreachable, false)->getStatusCode(), 'deactivating is always allowed');
        self::assertFalse($unreachable->isActive());
    }

    private function patch(OtaBundle $bundle, bool $active): ResponseInterface
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->willReturn($bundle);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('flush')->willReturnCallback(function (): void {
            $this->flushes++;
        });

        $request = (new ServerRequestFactory())->createServerRequest('PATCH', '/v3/admin/ota/bundles/7');
        $request->getBody()->write((string) json_encode(['is_active' => $active]));
        $request->getBody()->rewind();

        $controller = new SetOtaBundleActiveController(new ResponseFactory(), $em, new OtaBundleSerializer());

        return $controller($request, (new ResponseFactory())->createResponse(), ['id' => '7']);
    }

    private function bundle(string $min, ?string $max = null): OtaBundle
    {
        return new OtaBundle(
            'com.threebayti.app',
            'android',
            'production',
            '1.8.2',
            'https://api-v3.3bayti.ae/uploads/ota/1.8.2.zip',
            'c',
            $min,
            maxNativeVersion: $max,
        );
    }
}
