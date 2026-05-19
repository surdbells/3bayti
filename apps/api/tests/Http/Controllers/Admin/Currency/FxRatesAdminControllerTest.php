<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Currency;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Audit\AuditLog;
use Bayti\Api\Domain\Currency\FxRate;
use Bayti\Api\Domain\Currency\FxRateRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Admin\Currency\ListFxRatesController;
use Bayti\Api\Http\Controllers\Admin\Currency\UpsertFxRateController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/**
 * HTTP-level tests for the M3.2.X.15-F admin fx-rates endpoints.
 *
 *   GET /v3/admin/fx-rates              — list (admin only)
 *   PUT /v3/admin/fx-rates/{target}     — upsert with audit
 *
 * Strategy: mirror ResolveDisputeControllerTest. Inject mock EM
 * with capturing audit-log repo and a fake FxRateRepository
 * holding test rates. Authenticate via real JwtService.
 */
#[CoversClass(ListFxRatesController::class)]
#[CoversClass(UpsertFxRateController::class)]
final class FxRatesAdminControllerTest extends HttpTestCase
{
    /** @var list<AuditLog> */
    private array $recordedAudits = [];

    /** @var list<FxRate> */
    private array $persistedRates = [];

    /** @var array<string, FxRate> */
    private array $rateStore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->recordedAudits = [];
        $this->persistedRates = [];
        $this->rateStore = [];
    }

    // =================================================================
    // LIST
    // =================================================================

    #[Test]
    public function listReturnsAllRatesWithStaleness(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->seedRates([
            ['target' => 'USD', 'rate' => '0.27225000', 'ageHours' => 2],
            ['target' => 'EUR', 'rate' => '0.25180000', 'ageHours' => 1],
            ['target' => 'GBP', 'rate' => '0.21450000', 'ageHours' => 72], // stale
        ]);
        $this->bindEnv($admin);

        $response = $this->makeGet($admin, '/v3/admin/fx-rates');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertCount(3, $body['data']);
        // Find the GBP row and verify is_stale
        $gbpRow = array_filter($body['data'], fn ($r) => $r['target_code'] === 'GBP');
        $gbpRow = array_values($gbpRow)[0];
        self::assertTrue($gbpRow['is_stale']);
        self::assertGreaterThanOrEqual(48, $gbpRow['age_hours']);

        $usdRow = array_filter($body['data'], fn ($r) => $r['target_code'] === 'USD');
        $usdRow = array_values($usdRow)[0];
        self::assertFalse($usdRow['is_stale']);
        self::assertSame('0.27225000', $usdRow['rate']);

        // Meta block has supported currencies + threshold
        self::assertSame(48, $body['meta']['stale_after_hours']);
        self::assertContains('AED', $body['meta']['supported_currencies']);
        self::assertContains('USD', $body['meta']['supported_currencies']);
        self::assertCount(5, $body['meta']['supported_currencies']);
    }

    #[Test]
    public function listRequiresAdmin(): void
    {
        $regularUser = $this->makeUser(id: 200);
        $this->seedRates([]);
        $this->bindEnv($regularUser);

        $response = $this->makeGet($regularUser, '/v3/admin/fx-rates');

        // AdminAuthMiddleware rejects non-admin → 403
        self::assertSame(403, $response->getStatusCode());
    }

    // =================================================================
    // UPSERT — update path
    // =================================================================

    #[Test]
    public function upsertUpdatesExistingRateAndAudits(): void
    {
        $admin = $this->makeAdminUser(99);
        $existing = $this->makeRate('AED', 'USD', '0.27225000');
        $this->rateStore['USD'] = $existing;
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/fx-rates/USD', [
            'rate' => '0.28000000',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame('USD', $body['data']['target_code']);
        self::assertSame('0.28000000', $body['data']['rate']);
        self::assertSame('AED', $body['data']['base_code']);

        // Entity was mutated in place
        self::assertSame('0.28000000', $existing->getRate());

        // Audit recorded with before/after diff
        self::assertCount(1, $this->recordedAudits);
        $audit = $this->recordedAudits[0];
        self::assertSame(AuditLog::ACTION_UPDATED, $audit->getAction());
        self::assertSame('FxRate', $audit->getSubjectType());
        $changes = $audit->getChanges();
        self::assertSame('0.27225000', $changes['before']['rate']);
        self::assertSame('0.28000000', $changes['after']['rate']);
    }

    #[Test]
    public function upsertCreatesWhenNotFound(): void
    {
        $admin = $this->makeAdminUser(99);
        // No existing rate in store
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/fx-rates/EUR', [
            'rate' => '0.25000000',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame('EUR', $body['data']['target_code']);
        self::assertSame('0.25000000', $body['data']['rate']);

        // Audit recorded as CREATE
        self::assertCount(1, $this->recordedAudits);
        $audit = $this->recordedAudits[0];
        self::assertSame(AuditLog::ACTION_CREATED, $audit->getAction());
        self::assertSame('FxRate', $audit->getSubjectType());

        // Entity was persisted
        self::assertCount(1, $this->persistedRates);
        self::assertSame('EUR', $this->persistedRates[0]->getTargetCode());
    }

    // =================================================================
    // UPSERT — validation errors
    // =================================================================

    #[Test]
    public function upsertRejectsAedTarget(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/fx-rates/AED', [
            'rate' => '1.0',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertStringContainsString('base currency', json_encode($body) ?: '');
    }

    #[Test]
    public function upsertRejectsUnsupportedCurrency(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/fx-rates/JPY', [
            'rate' => '0.07',
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function upsertRejectsNegativeRate(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->rateStore['USD'] = $this->makeRate('AED', 'USD', '0.27225000');
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/fx-rates/USD', [
            'rate' => '-1.0',
        ]);

        self::assertSame(422, $response->getStatusCode());

        // Audit NOT recorded for failed validation
        self::assertCount(0, $this->recordedAudits);
        // Rate NOT mutated
        self::assertSame('0.27225000', $this->rateStore['USD']->getRate());
    }

    #[Test]
    public function upsertRejectsRateOverThousand(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->rateStore['USD'] = $this->makeRate('AED', 'USD', '0.27225000');
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/fx-rates/USD', [
            'rate' => '5000',
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function upsertRejectsNonStringRate(): void
    {
        $admin = $this->makeAdminUser(99);
        $this->bindEnv($admin);

        $response = $this->makePut($admin, '/v3/admin/fx-rates/USD', [
            'rate' => 0.28,  // float, not string
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function upsertRequiresAdmin(): void
    {
        $regularUser = $this->makeUser(id: 200);
        $this->bindEnv($regularUser);

        $response = $this->makePut($regularUser, '/v3/admin/fx-rates/USD', [
            'rate' => '0.28',
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function bindEnv(User $user): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        // FxRateRepository capturing find + persist
        $fxRepo = new class($this->rateStore) extends FxRateRepository {
            /** @param array<string, FxRate> $store */
            public function __construct(private array &$store)
            {
            }
            public function findAllRates(): array
            {
                return array_values($this->store);
            }
            public function findByPair(string $base, string $target): ?FxRate
            {
                return $this->store[strtoupper($target)] ?? null;
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

        $em = $this->stubEm(function ($em) use ($userRepo, $fxRepo, $auditRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [FxRate::class, $fxRepo],
                [AuditLog::class, $auditRepo],
            ]);
            // persist() captures new rates so we can verify in tests
            $em->method('persist')->willReturnCallback(function (object $entity): void {
                if ($entity instanceof FxRate) {
                    // Simulate Doctrine's autoincrement: assign an id
                    // so AuditEmitter::subjectId() doesn't throw.
                    if ($entity->getId() === null) {
                        $idRef = new \ReflectionProperty(FxRate::class, 'id');
                        $idRef->setAccessible(true);
                        $idRef->setValue($entity, count($this->persistedRates) + 100);
                    }
                    $this->persistedRates[] = $entity;
                    $this->rateStore[$entity->getTargetCode()] = $entity;
                }
            });
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(AuditEmitter::class, new AuditEmitter($em, new NullLogger()));
    }

    /**
     * @param list<array{target: string, rate: string, ageHours: int}> $specs
     */
    private function seedRates(array $specs): void
    {
        foreach ($specs as $spec) {
            $rate = $this->makeRate('AED', $spec['target'], $spec['rate'], $spec['ageHours']);
            $this->rateStore[$spec['target']] = $rate;
        }
    }

    private function makeRate(string $base, string $target, string $value, int $ageHours = 1): FxRate
    {
        $r = new FxRate($base, $target, $value);
        $when = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$ageHours} hours");
        $ref = new \ReflectionProperty(FxRate::class, 'updatedAt');
        $ref->setAccessible(true);
        $ref->setValue($r, $when);
        // Set a fake id so audit subjectId() can read it
        $idRef = new \ReflectionProperty(FxRate::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($r, count($this->rateStore) + 1);
        return $r;
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
