<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Notification;

use Bayti\Api\Domain\Notification\DeviceToken;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the DeviceToken entity (M3.2.Z.4-A).
 *
 * Locks the construction defaults and the touch()/deactivate() state
 * transitions that DeviceTokenRepository::register/deactivate depend
 * on. Pure entity test — no DB.
 */
#[CoversClass(DeviceToken::class)]
final class DeviceTokenTest extends TestCase
{
    private function makeUser(int $id): User
    {
        $user = new User('alice@example.com', null, 'hash');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, $id);
        return $user;
    }

    #[Test]
    public function newTokenIsActiveAndTimestamped(): void
    {
        $user = $this->makeUser(1);
        $token = new DeviceToken($user, 'fcm-abc', DeviceToken::PLATFORM_IOS);

        self::assertTrue($token->isActive());
        self::assertSame('fcm-abc', $token->getToken());
        self::assertSame(DeviceToken::PLATFORM_IOS, $token->getPlatform());
        self::assertSame($user, $token->getUser());
        self::assertEquals($token->getCreatedAt(), $token->getUpdatedAt());
        self::assertNotNull($token->getLastSeenAt());
        self::assertNull($token->getId(), 'id is null until Doctrine persists');
    }

    #[Test]
    public function platformConstantsCoverBothPlatforms(): void
    {
        self::assertSame(['ios', 'android'], DeviceToken::PLATFORMS);
    }

    #[Test]
    public function touchReactivatesAndReownsAndRestamps(): void
    {
        $owner = $this->makeUser(1);
        $token = new DeviceToken($owner, 'fcm-abc', DeviceToken::PLATFORM_IOS);
        $createdAt = $token->getCreatedAt();
        $token->deactivate();
        self::assertFalse($token->isActive());

        // A different user signs in on the same device.
        $newOwner = $this->makeUser(2);
        // Force a measurable time gap so updated_at can advance.
        usleep(1000);
        $token->touch($newOwner, DeviceToken::PLATFORM_ANDROID);

        self::assertTrue($token->isActive(), 'touch reactivates a dead token');
        self::assertSame($newOwner, $token->getUser(), 'touch reassigns ownership');
        self::assertSame(DeviceToken::PLATFORM_ANDROID, $token->getPlatform(), 'touch can update platform');
        self::assertEquals($createdAt, $token->getCreatedAt(), 'created_at is immutable');
        self::assertGreaterThanOrEqual($createdAt, $token->getUpdatedAt());
        self::assertGreaterThanOrEqual($createdAt, $token->getLastSeenAt());
    }

    #[Test]
    public function deactivateFlipsActiveAndIsIdempotent(): void
    {
        $token = new DeviceToken($this->makeUser(1), 'fcm-abc', DeviceToken::PLATFORM_ANDROID);
        self::assertTrue($token->isActive());

        $token->deactivate();
        self::assertFalse($token->isActive());
        $updatedAfterFirst = $token->getUpdatedAt();

        // Second deactivate is a no-op — does not re-stamp updated_at.
        usleep(1000);
        $token->deactivate();
        self::assertFalse($token->isActive());
        self::assertSame($updatedAfterFirst, $token->getUpdatedAt(), 'idempotent: no re-stamp on second deactivate');
    }
}
