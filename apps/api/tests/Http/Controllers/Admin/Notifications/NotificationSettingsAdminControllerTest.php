<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Notifications;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Setting\AppSetting;
use Bayti\Api\Domain\Setting\AppSettingRepository;
use Bayti\Api\Domain\Setting\NotificationSettings;
use Bayti\Api\Domain\Setting\SettingsService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Notifications\GetNotificationSettingsController;
use Bayti\Api\Http\Controllers\Admin\Notifications\UpdateNotificationSettingsController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

#[CoversClass(GetNotificationSettingsController::class)]
#[CoversClass(UpdateNotificationSettingsController::class)]
#[CoversClass(SettingsService::class)]
final class NotificationSettingsAdminControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAudits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAudits = [];
    }

    #[Test]
    public function getReturnsTheDefaultWhenUnset(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makeGet($admin, '/v3/admin/settings/notifications');

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->jsonBody($response)['suppress_vendor_item_status']);
    }

    #[Test]
    public function getRequiresAdmin(): void
    {
        $regular = $this->makeUser(id: 200);
        $this->bindEnv($regular);

        self::assertSame(403, $this->makeGet($regular, '/v3/admin/settings/notifications')->getStatusCode());
    }

    #[Test]
    public function putEnablesSuppressionPersistsAndAudits(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/settings/notifications', [
            'suppress_vendor_item_status' => true,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->jsonBody($response)['suppress_vendor_item_status']);

        // Persisted: a fresh GET reflects it.
        $after = $this->jsonBody($this->makeGet($admin, '/v3/admin/settings/notifications'));
        self::assertTrue($after['suppress_vendor_item_status']);

        // Audited as a Setting change.
        self::assertCount(1, $this->recordedAudits);
        $audit = $this->recordedAudits[0];
        self::assertSame('Setting', $audit->getSubjectType());
        self::assertSame(NotificationSettings::KEY, $audit->getChanges()['setting']);
        self::assertFalse($audit->getChanges()['before']['suppress_vendor_item_status']);
        self::assertTrue($audit->getChanges()['after']['suppress_vendor_item_status']);
    }

    #[Test]
    public function putRejectsANonBoolean(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/settings/notifications', [
            'suppress_vendor_item_status' => 'yes',
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->recordedAudits);
    }

    #[Test]
    public function putRejectsAMissingField(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/settings/notifications', ['foo' => true]);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function putRequiresAdmin(): void
    {
        $regular = $this->makeUser(id: 200);
        $this->bindEnv($regular);

        $response = $this->makePut($regular, '/v3/admin/settings/notifications', [
            'suppress_vendor_item_status' => true,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------

    private function bindEnv(User $user): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $settingRepo = new class extends AppSettingRepository {
            /** @var array<string, AppSetting> */
            public array $store = [];
            public function __construct()
            {
            }
            public function findByKey(string $key): ?AppSetting
            {
                return $this->store[$key] ?? null;
            }
            public function save(AppSetting $setting): void
            {
                $this->store[$setting->getKey()] = $setting;
            }
        };

        $auditRepo = new class($this->recordedAudits) extends \Doctrine\ORM\EntityRepository {
            /** @param list<AuditLog> $sink */
            public function __construct(private array &$sink)
            {
            }
            public function save(AuditLog $log): void
            {
                $this->sink[] = $log;
            }
            public function getClassName(): string
            {
                return AuditLog::class;
            }
        };

        $em = $this->stubEm(function ($em) use ($userRepo, $settingRepo, $auditRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [AppSetting::class, $settingRepo],
                [AuditLog::class, $auditRepo],
            ]);
        });

        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(SettingsService::class, new SettingsService($em, new InMemoryKeyValueStore(), new NullLogger()));
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
    }

    private function makeAdminUser(int $id): User
    {
        $u = $this->makeUser(id: $id);
        $u->setRoles(admin: true);
        return $u;
    }

    /** @param array<string, mixed> $body */
    private function makePut(User $user, string $uri, array $body): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('PUT', $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function makeGet(User $user, string $uri): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest('GET', $uri, [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
