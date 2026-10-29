<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the session-ending bulk UPDATEs of RefreshTokenRepository (CI has no
 * PostgreSQL; same harness as CollectionCurationRepositoryDqlTest):
 *
 *   1. a mocked EntityManager captures every DQL string + bound parameter
 *      (with its type) + lock mode and runs the transaction callback inline;
 *   2. every captured DQL is COMPILED to Postgres SQL by a real
 *      EntityManager (the app's Doctrine config; it never connects), so a
 *      parser error or a wrong column mapping fails here.
 *
 * The contract: after revokeFamily / revokeAllForUser NO affected row is
 * re-issuable, i.e. 'rotated' rows are relabelled (keeping their
 * revoked_at) and still-active rows are revoked with :now typed as
 * datetimetz_immutable. reassignUser re-points rows to another user. Every
 * one of them FIRST locks the rows it will change (SELECT ... ORDER BY id
 * FOR UPDATE, in the same transaction) so a concurrent refresh can't undo
 * or slip past it.
 */
#[CoversClass(RefreshTokenRepository::class)]
final class RefreshTokenRepositoryDqlTest extends TestCase
{
    /** @var list<array{dql: string, params: array<string, array{value: mixed, type: mixed}>, lock: LockMode|int|null, ran: bool}> */
    private array $queries = [];
    private int $transactions = 0;
    private int $updates = 0;

    private static ?EntityManager $sqlEm = null;

    #[Test]
    public function revokeFamilyLocksThenRetiresRotatedRowsThenRevokesActiveOnesInOneTransaction(): void
    {
        $count = $this->repo()->revokeFamily('fam-1', 'logout');

        self::assertSame(1, $this->transactions, 'lock + both statements share one transaction');
        self::assertCount(3, $this->queries);
        self::assertSame(5, $count, 'total rows changed by both UPDATEs (2 + 3)');

        [$lock, $retire, $revoke] = $this->queries;

        // 0. the family's re-issuable rows are locked first, in id order.
        self::assertMatchesRegularExpression(
            '/^SELECT t\.id FROM .*RefreshToken t WHERE \(t\.familyId = :scope OR \(t\.familyId IS NULL AND t\.jti = :scope\)\) AND \(t\.revokedAt IS NULL OR t\.revokedReason = :rotated\) ORDER BY t\.id ASC$/',
            $this->squash($lock['dql']),
        );
        self::assertSame(LockMode::PESSIMISTIC_WRITE, $lock['lock']);
        self::assertTrue($lock['ran'], 'the lock query is executed, not just built');
        self::assertSame('fam-1', $lock['params']['scope']['value']);
        self::assertSame('rotated', $lock['params']['rotated']['value']);
        self::assertMatchesRegularExpression(
            '/^SELECT u0_\.id AS id_0 FROM user_refresh_tokens u0_ WHERE \(u0_\.family_id = \? OR \(u0_\.family_id IS NULL AND u0_\.jti = \?\)\) AND \(u0_\.revoked_at IS NULL OR u0_\.revoked_reason = \?\) ORDER BY u0_\.id ASC FOR UPDATE$/',
            $this->compile($lock['dql'], locked: true),
        );

        // 1. rotated rows of the family get the new reason, revoked_at kept.
        self::assertMatchesRegularExpression(
            '/^UPDATE .*RefreshToken t\s+SET t\.revokedReason = :reason\s+WHERE \(t\.familyId = :scope OR \(t\.familyId IS NULL AND t\.jti = :scope\)\) AND t\.revokedReason = :rotated$/',
            $this->squash($retire['dql']),
        );
        self::assertStringNotContainsString('revokedAt', $retire['dql'], 'original revoked_at is kept');
        self::assertSame('logout', $retire['params']['reason']['value']);
        self::assertSame('rotated', $retire['params']['rotated']['value']);
        self::assertSame('fam-1', $retire['params']['scope']['value']);

        // 2. still-active rows of the family are revoked now.
        self::assertMatchesRegularExpression(
            '/^UPDATE .*RefreshToken t\s+SET t\.revokedAt = :now, t\.revokedReason = :reason\s+WHERE \(t\.familyId = :scope OR \(t\.familyId IS NULL AND t\.jti = :scope\)\) AND t\.revokedAt IS NULL$/',
            $this->squash($revoke['dql']),
        );
        self::assertInstanceOf(DateTimeImmutable::class, $revoke['params']['now']['value']);
        self::assertSame(Types::DATETIMETZ_IMMUTABLE, $revoke['params']['now']['type']);
        self::assertSame('logout', $revoke['params']['reason']['value']);
        self::assertSame('fam-1', $revoke['params']['scope']['value']);

        $retireSql = $this->compile($retire['dql']);
        self::assertMatchesRegularExpression(
            '/^UPDATE user_refresh_tokens SET revoked_reason = \? WHERE \(family_id = \? OR \(family_id IS NULL AND jti = \?\)\) AND revoked_reason = \?$/',
            $retireSql,
        );
        $revokeSql = $this->compile($revoke['dql']);
        self::assertMatchesRegularExpression(
            '/^UPDATE user_refresh_tokens SET revoked_at = \?, revoked_reason = \? WHERE \(family_id = \? OR \(family_id IS NULL AND jti = \?\)\) AND revoked_at IS NULL$/',
            $revokeSql,
        );
    }

    #[Test]
    public function revokeAllForUserUsesTheSameLockedTwoStepSemanticsScopedToTheUser(): void
    {
        $user = $this->user(42);

        $count = $this->repo()->revokeAllForUser($user, 'logout_all');

        self::assertSame(1, $this->transactions);
        self::assertCount(3, $this->queries);
        self::assertSame(5, $count);

        [$lock, $retire, $revoke] = $this->queries;
        self::assertStringContainsString(
            'WHERE (t.user = :scope) AND (t.revokedAt IS NULL OR t.revokedReason = :rotated) ORDER BY t.id ASC',
            $this->squash($lock['dql']),
        );
        self::assertSame(LockMode::PESSIMISTIC_WRITE, $lock['lock']);
        self::assertTrue($lock['ran']);
        self::assertSame($user, $lock['params']['scope']['value']);

        self::assertStringContainsString('WHERE (t.user = :scope) AND t.revokedReason = :rotated', $this->squash($retire['dql']));
        self::assertSame($user, $retire['params']['scope']['value']);
        self::assertSame('logout_all', $retire['params']['reason']['value']);

        self::assertStringContainsString('WHERE (t.user = :scope) AND t.revokedAt IS NULL', $this->squash($revoke['dql']));
        self::assertSame(Types::DATETIMETZ_IMMUTABLE, $revoke['params']['now']['type']);

        self::assertMatchesRegularExpression(
            '/^SELECT u0_\.id AS id_0 FROM user_refresh_tokens u0_ WHERE \(u0_\.user_id = \?\) AND \(u0_\.revoked_at IS NULL OR u0_\.revoked_reason = \?\) ORDER BY u0_\.id ASC FOR UPDATE$/',
            $this->compile($lock['dql'], locked: true),
        );
        self::assertMatchesRegularExpression(
            '/^UPDATE user_refresh_tokens SET revoked_reason = \? WHERE \(user_id = \?\) AND revoked_reason = \?$/',
            $this->compile($retire['dql']),
        );
        self::assertMatchesRegularExpression(
            '/^UPDATE user_refresh_tokens SET revoked_at = \?, revoked_reason = \? WHERE \(user_id = \?\) AND revoked_at IS NULL$/',
            $this->compile($revoke['dql']),
        );
    }

    #[Test]
    public function reassignUserLocksThenRepointsEveryRowOfTheSourceUser(): void
    {
        $from = $this->user(20);
        $to = $this->user(21);

        $moved = $this->repo()->reassignUser($from, $to);

        self::assertSame(2, $moved);
        self::assertSame(1, $this->transactions, 'lock + UPDATE share one transaction');
        self::assertCount(2, $this->queries);

        [$lock, $q] = $this->queries;
        // Every row of the source, whatever its state (all of them move).
        self::assertMatchesRegularExpression(
            '/^SELECT t\.id FROM .*RefreshToken t WHERE \(t\.user = :scope\) ORDER BY t\.id ASC$/',
            $this->squash($lock['dql']),
        );
        self::assertSame(LockMode::PESSIMISTIC_WRITE, $lock['lock']);
        self::assertTrue($lock['ran']);
        self::assertSame($from, $lock['params']['scope']['value']);
        self::assertArrayNotHasKey('rotated', $lock['params']);
        self::assertMatchesRegularExpression(
            '/^SELECT u0_\.id AS id_0 FROM user_refresh_tokens u0_ WHERE \(u0_\.user_id = \?\) ORDER BY u0_\.id ASC FOR UPDATE$/',
            $this->compile($lock['dql'], locked: true),
        );

        self::assertMatchesRegularExpression(
            '/^UPDATE .*RefreshToken t\s+SET t\.user = :to\s+WHERE t\.user = :from$/',
            $this->squash($q['dql']),
        );
        self::assertSame($to, $q['params']['to']['value']);
        self::assertSame($from, $q['params']['from']['value']);
        self::assertMatchesRegularExpression(
            '/^UPDATE user_refresh_tokens SET user_id = \? WHERE user_id = \?$/',
            $this->compile($q['dql']),
        );
    }

    // ── harness ─────────────────────────────────────────────────────

    private function repo(): RefreshTokenRepository
    {
        return new RefreshTokenRepository($this->capturingEm(), new ClassMetadata(RefreshToken::class));
    }

    private function user(int $id): User
    {
        $u = new User("u{$id}@example.com", null, null, 'AE');
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);
        return $u;
    }

    private function capturingEm(): EntityManagerInterface
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('transactional')->willReturnCallback(function (\Closure $fn) use ($conn): mixed {
            $this->transactions++;
            return $fn($conn);
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $em->method('createQuery')->willReturnCallback(function (string $dql): Query {
            $this->queries[] = ['dql' => $dql, 'params' => [], 'lock' => null, 'ran' => false];
            // Canned affected-row counts: 2 for the first UPDATE, 3 for the
            // second, so the summed return value is checkable. The lock
            // SELECT changes nothing.
            $affected = 0;
            if (str_starts_with(ltrim($dql), 'UPDATE')) {
                $affected = $this->updates++ === 0 ? 2 : 3;
            }
            return $this->stubQuery(count($this->queries) - 1, $affected);
        });
        return $em;
    }

    /** A Query double that records bound parameters and never touches a DB. */
    private function stubQuery(int $index, int $affected): Query
    {
        $test = $this;
        return new class ($test, $index, $affected) extends Query {
            public function __construct(
                private readonly RefreshTokenRepositoryDqlTest $test,
                private readonly int $index,
                private readonly int $affected,
            ) {
                // Parent constructor needs a real EM + UnitOfWork; never executed.
            }

            public function setParameter(string|int $key, mixed $value, \Doctrine\DBAL\ParameterType|\Doctrine\DBAL\ArrayParameterType|string|int|null $type = null): static
            {
                $this->test->recordParam($this->index, (string) $key, $value, $type);
                return $this;
            }

            public function setLockMode(LockMode|int $lockMode): self
            {
                $this->test->recordLock($this->index, $lockMode);
                return $this;
            }

            /** @return list<array<string, mixed>> */
            public function getScalarResult(): array
            {
                $this->test->recordRan($this->index);
                return [];
            }

            public function execute(\Doctrine\Common\Collections\ArrayCollection|array|null $parameters = null, string|int|null $hydrationMode = null): mixed
            {
                $this->test->recordRan($this->index);
                return $this->affected;
            }
        };
    }

    /** @internal called by the Query double */
    public function recordParam(int $index, string $name, mixed $value, mixed $type): void
    {
        $this->queries[$index]['params'][$name] = ['value' => $value, 'type' => $type];
    }

    /** @internal called by the Query double */
    public function recordLock(int $index, LockMode|int $lockMode): void
    {
        $this->queries[$index]['lock'] = $lockMode;
    }

    /** @internal called by the Query double */
    public function recordRan(int $index): void
    {
        $this->queries[$index]['ran'] = true;
    }

    private function squash(string $dql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $dql));
    }

    /**
     * Compile DQL to SQL with a REAL EntityManager on the pdo_pgsql
     * platform. DBAL connects lazily, so nothing touches a database.
     * $locked applies the pessimistic-write lock the repository sets (as
     * the hint: setLockMode() itself demands an open transaction).
     */
    private function compile(string $dql, bool $locked = false): string
    {
        if (self::$sqlEm === null) {
            $root = dirname(__DIR__, 3);
            /** @var array{config_factory: callable(string, string): \Doctrine\ORM\Configuration} $doctrine */
            $doctrine = require $root . '/config/doctrine.php';
            $config = ($doctrine['config_factory'])('test', $root);
            $conn = DriverManager::getConnection([
                'driver'        => 'pdo_pgsql',
                'serverVersion' => '16.0',
                'host'          => '127.0.0.1',
                'dbname'        => 'never_connected',
                'user'          => 'never_connected',
            ], $config);
            self::$sqlEm = new EntityManager($conn, $config);
        }
        $query = self::$sqlEm->createQuery($dql);
        if ($locked) {
            $query->setHint(Query::HINT_LOCK_MODE, LockMode::PESSIMISTIC_WRITE);
        }
        $sql = $query->getSQL();
        self::assertIsString($sql);
        self::assertFalse(self::$sqlEm->getConnection()->isConnected(), 'SQL generation must not connect');
        return $sql;
    }
}
