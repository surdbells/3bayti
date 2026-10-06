<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Otp;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Setting\AppSetting;
use Bayti\Api\Domain\Setting\AppSettingRepository;
use Bayti\Api\Domain\Setting\OtpProviderSettings;
use Bayti\Api\Domain\Setting\SettingsService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Otp\GetOtpProvidersController;
use Bayti\Api\Http\Controllers\Admin\Otp\UpdateOtpProvidersController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * HTTP-level tests for the admin OTP-provider config endpoints.
 *
 *   GET /v3/admin/otp/providers   (settings.view)
 *   PUT /v3/admin/otp/providers   (settings.edit)
 *
 * A real SettingsService (backed by an in-memory AppSetting repo + in-memory
 * cache) is bound so the read-your-write path is exercised end-to-end, plus a
 * capturing audit repo to assert the change is audited.
 */
#[CoversClass(GetOtpProvidersController::class)]
#[CoversClass(UpdateOtpProvidersController::class)]
#[CoversClass(SettingsService::class)]
final class OtpProvidersAdminControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAudits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAudits = [];
    }

    #[Test]
    public function getReturnsTheDefaultConfigWhenNothingIsStored(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makeGet($admin, '/v3/admin/otp/providers');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame('messagecentral', $body['default_provider']);
        self::assertSame(['messagecentral', 'cequens'], array_column($body['providers'], 'key'));
        self::assertTrue($body['providers'][0]['enabled']);
        self::assertTrue($body['providers'][1]['enabled']);
        self::assertSame(1, $body['providers'][0]['position']);
    }

    #[Test]
    public function getRequiresAdmin(): void
    {
        $regular = $this->makeUser(id: 200);
        $this->bindEnv($regular);

        $response = $this->makeGet($regular, '/v3/admin/otp/providers');

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function putReordersAndDisablesAProviderThenPersistsAndAudits(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/otp/providers', [
            'providers' => [
                ['key' => 'cequens', 'enabled' => true],
                ['key' => 'messagecentral', 'enabled' => false],
            ],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame(['cequens', 'messagecentral'], array_column($body['providers'], 'key'));
        self::assertTrue($body['providers'][0]['enabled']);
        self::assertFalse($body['providers'][1]['enabled']);

        // Persisted: a fresh GET reflects the new order.
        $after = $this->jsonBody($this->makeGet($admin, '/v3/admin/otp/providers'));
        self::assertSame(['cequens', 'messagecentral'], array_column($after['providers'], 'key'));

        // Audited as a Setting change with before/after.
        self::assertCount(1, $this->recordedAudits);
        $audit = $this->recordedAudits[0];
        self::assertSame('Setting', $audit->getSubjectType());
        self::assertSame(AuditLog::ACTION_OVERRIDDEN, $audit->getAction());
        $changes = $audit->getChanges();
        self::assertSame(OtpProviderSettings::KEY, $changes['setting']);
        self::assertSame(['messagecentral', 'cequens'], $changes['before']['order']);
        self::assertSame(['cequens', 'messagecentral'], $changes['after']['order']);
    }

    #[Test]
    public function putRejectsAnAllDisabledConfig(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/otp/providers', [
            'providers' => [
                ['key' => 'messagecentral', 'enabled' => false],
                ['key' => 'cequens', 'enabled' => false],
            ],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->recordedAudits);
    }

    #[Test]
    public function putRejectsAnUnknownProvider(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/otp/providers', [
            'providers' => [
                ['key' => 'twilio', 'enabled' => true],
                ['key' => 'messagecentral', 'enabled' => true],
                ['key' => 'cequens', 'enabled' => true],
            ],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function putRejectsAnIncompleteProviderList(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        // cequens omitted → incomplete ordering.
        $response = $this->makePut($admin, '/v3/admin/otp/providers', [
            'providers' => [
                ['key' => 'messagecentral', 'enabled' => true],
            ],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function putRequiresAdmin(): void
    {
        $regular = $this->makeUser(id: 200);
        $this->bindEnv($regular);

        $response = $this->makePut($regular, '/v3/admin/otp/providers', [
            'providers' => [
                ['key' => 'messagecentral', 'enabled' => true],
                ['key' => 'cequens', 'enabled' => true],
            ],
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Helpers
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

    /**
     * @param array<string, mixed> $body
     */
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
