<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Notification;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\Notification\DeviceTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Notification\DeleteDeviceTokenController;
use Bayti\Api\Http\Controllers\Notification\Dto\DeleteDeviceTokenInput;
use Bayti\Api\Http\Controllers\Notification\Dto\RegisterDeviceTokenInput;
use Bayti\Api\Http\Controllers\Notification\RegisterDeviceTokenController;
use Bayti\Api\Http\Serializers\DeviceTokenSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RegisterDeviceTokenController::class)]
#[CoversClass(DeleteDeviceTokenController::class)]
#[CoversClass(RegisterDeviceTokenInput::class)]
#[CoversClass(DeleteDeviceTokenInput::class)]
#[CoversClass(DeviceTokenSerializer::class)]
#[CoversClass(DeviceToken::class)]
final class DeviceTokenControllerTest extends HttpTestCase
{
    private function authHeader(User $user): array
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return ['Authorization' => 'Bearer ' . $pair->accessToken];
    }

    /**
     * Build an EM whose User repo resolves the auth user and whose
     * DeviceToken repo is the supplied mock.
     */
    private function bindEm(User $user, DeviceTokenRepository $tokenRepo): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo, $tokenRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [DeviceToken::class, $tokenRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    // ---------------------------------------------------------------
    // POST /v3/me/device-tokens
    // ---------------------------------------------------------------

    #[Test]
    public function registerNewTokenReturns201WithMetadata(): void
    {
        $user = $this->makeUser(id: 500);
        $entity = new DeviceToken($user, 'fcm-new', DeviceToken::PLATFORM_IOS);

        $repo = $this->createMock(DeviceTokenRepository::class);
        $repo->method('findOneByToken')->with('fcm-new')->willReturn(null); // new
        $repo->expects(self::once())->method('register')
            ->with($user, 'fcm-new', 'ios')
            ->willReturn($entity);

        $this->bindEm($user, $repo);

        $response = $this->handle($this->jsonRequest(
            'POST', '/v3/me/device-tokens',
            ['token' => 'fcm-new', 'platform' => 'ios'],
            $this->authHeader($user),
        ));

        self::assertSame(201, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('ios', $body['data']['platform']);
        self::assertTrue($body['data']['is_active']);
        // The token string is never echoed back.
        self::assertArrayNotHasKey('token', $body['data']);
    }

    #[Test]
    public function reRegisterExistingTokenReturns200(): void
    {
        $user = $this->makeUser(id: 501);
        $existing = new DeviceToken($user, 'fcm-existing', DeviceToken::PLATFORM_ANDROID);

        $repo = $this->createMock(DeviceTokenRepository::class);
        $repo->method('findOneByToken')->with('fcm-existing')->willReturn($existing); // already known
        $repo->expects(self::once())->method('register')
            ->with($user, 'fcm-existing', 'android')
            ->willReturn($existing);

        $this->bindEm($user, $repo);

        $response = $this->handle($this->jsonRequest(
            'POST', '/v3/me/device-tokens',
            ['token' => 'fcm-existing', 'platform' => 'android'],
            $this->authHeader($user),
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('android', $body['data']['platform']);
    }

    #[Test]
    public function registerTrimsAndLowercasesPlatform(): void
    {
        $user = $this->makeUser(id: 502);
        $entity = new DeviceToken($user, 'fcm-x', DeviceToken::PLATFORM_IOS);

        $repo = $this->createMock(DeviceTokenRepository::class);
        $repo->method('findOneByToken')->willReturn(null);
        // Expect normalised platform 'ios' even though caller sent ' IOS '.
        $repo->expects(self::once())->method('register')
            ->with($user, 'fcm-x', 'ios')
            ->willReturn($entity);

        $this->bindEm($user, $repo);

        $response = $this->handle($this->jsonRequest(
            'POST', '/v3/me/device-tokens',
            ['token' => '  fcm-x  ', 'platform' => ' IOS '],
            $this->authHeader($user),
        ));

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function registerRejectsMissingToken(): void
    {
        $user = $this->makeUser(id: 503);
        $repo = $this->createMock(DeviceTokenRepository::class);
        $repo->expects(self::never())->method('register');
        $this->bindEm($user, $repo);

        $response = $this->handle($this->jsonRequest(
            'POST', '/v3/me/device-tokens',
            ['platform' => 'ios'],
            $this->authHeader($user),
        ));

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function registerRejectsInvalidPlatform(): void
    {
        $user = $this->makeUser(id: 504);
        $repo = $this->createMock(DeviceTokenRepository::class);
        $repo->expects(self::never())->method('register');
        $this->bindEm($user, $repo);

        $response = $this->handle($this->jsonRequest(
            'POST', '/v3/me/device-tokens',
            ['token' => 'fcm-x', 'platform' => 'windows'],
            $this->authHeader($user),
        ));

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function registerRequiresAuth(): void
    {
        $response = $this->handle($this->jsonRequest(
            'POST', '/v3/me/device-tokens',
            ['token' => 'fcm-x', 'platform' => 'ios'],
        ));
        self::assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // DELETE /v3/me/device-tokens
    // ---------------------------------------------------------------

    #[Test]
    public function deleteDeactivatesOwnedTokenReturns204(): void
    {
        $user = $this->makeUser(id: 510);

        $repo = $this->createMock(DeviceTokenRepository::class);
        $repo->expects(self::once())->method('deactivateForUser')
            ->with($user, 'fcm-mine')
            ->willReturn(true);

        $this->bindEm($user, $repo);

        $response = $this->handle($this->jsonRequest(
            'DELETE', '/v3/me/device-tokens',
            ['token' => 'fcm-mine'],
            $this->authHeader($user),
        ));

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function deleteUnknownTokenIsIdempotent204(): void
    {
        $user = $this->makeUser(id: 511);

        $repo = $this->createMock(DeviceTokenRepository::class);
        // Unknown / not-owned → repo returns false; still 204.
        $repo->method('deactivateForUser')->willReturn(false);

        $this->bindEm($user, $repo);

        $response = $this->handle($this->jsonRequest(
            'DELETE', '/v3/me/device-tokens',
            ['token' => 'fcm-unknown'],
            $this->authHeader($user),
        ));

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function deleteRejectsMissingToken(): void
    {
        $user = $this->makeUser(id: 512);
        $repo = $this->createMock(DeviceTokenRepository::class);
        $repo->expects(self::never())->method('deactivateForUser');
        $this->bindEm($user, $repo);

        $response = $this->handle($this->jsonRequest(
            'DELETE', '/v3/me/device-tokens',
            [],
            $this->authHeader($user),
        ));

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function deleteRequiresAuth(): void
    {
        $response = $this->handle($this->jsonRequest(
            'DELETE', '/v3/me/device-tokens',
            ['token' => 'fcm-x'],
        ));
        self::assertSame(401, $response->getStatusCode());
    }
}
