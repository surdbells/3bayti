<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Auth\Dto\RefreshInput;
use Bayti\Api\Http\Controllers\Auth\RefreshController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\TokenPair;
use Bayti\Api\Tests\Http\HttpTestCase;
use Bayti\Api\Tests\Support\InMemoryLogger;
use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

/**
 * POST /v3/auth/refresh under the "stay signed in until you log out" rule:
 *
 *   - a token revoked by ROTATION is re-issued at any age (lost response,
 *     parallel refresh, stale stored copy) and the new row joins its family;
 *   - a token whose session really ended gets 401 AUTH_REFRESH_TOKEN_INVALID
 *     and NOTHING else is revoked (the old reuse-detection wipe is gone);
 *   - the response profile is built before the rotation commits;
 *   - the rotation re-reads the row under a lock, so a logout or merge
 *     that committed meanwhile is honoured, never overwritten;
 *   - every rejection is logged with a reason tag, never the token.
 */
#[CoversClass(RefreshController::class)]
#[CoversClass(RefreshInput::class)]
final class RefreshControllerTest extends HttpTestCase
{
    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new InMemoryLogger();
        $this->bind(LoggerInterface::class, $this->logger);
    }

    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    #[Test]
    public function happyPathRotatesTokenAndReturnsNewPairInTheSameFamily(): void
    {
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $oldPair = $jwt->issueTokenPair($user);

        // Persisted refresh row matching the issued pair, in a family that
        // started with an earlier sign-in.
        $row = $this->makeRefreshRow($user, $oldPair, familyId: 'family-of-this-phone');

        $saved = [];
        $refreshRepo = $this->refreshRepo($row, $saved);

        // NB: no ->with(RefreshToken::class) constraint, the success path
        // also serialises the user (UserSerializer looks up other
        // repositories), so pinning getRepository to a single class throws.
        $em = $this->stubEm(function ($em) use ($refreshRepo, $row): void {
            $em->method('getRepository')->willReturn($refreshRepo);
            // The presented row is re-read under a row lock before rotating.
            $em->expects(self::once())->method('refresh')
                ->with(self::identicalTo($row), LockMode::PESSIMISTIC_WRITE);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($oldPair->refreshToken);

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['access_token_expires_at']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertNotEmpty($body['refresh_token_expires_at']);
        // Different from the old refresh, proves rotation happened.
        self::assertNotSame($oldPair->refreshToken, $body['refresh_token']);
        self::assertSame(7, $body['user']['id']);

        // Side effect: old row revoked with reason 'rotated'.
        self::assertTrue($row->isRevoked());
        self::assertSame('rotated', $row->getRevokedReason());

        // The replacement row joins the presented token's family.
        self::assertCount(1, $saved);
        self::assertSame('family-of-this-phone', $saved[0]->getFamilyId());
        self::assertSame($user, $saved[0]->getUser());

        // New access token verifies via real JwtService and names the
        // family (sid), so a later password change / merge from this device
        // can keep it in the same family.
        $newClaims = $jwt->verifyAccessToken($body['access_token']);
        self::assertNotNull($newClaims);
        self::assertSame(7, $newClaims->userId);
        self::assertSame('family-of-this-phone', $newClaims->sessionFamilyId);

        // Refresh-token lifetime is the 5-year sliding window.
        $expiresAt = new DateTimeImmutable($body['refresh_token_expires_at']);
        self::assertGreaterThan(
            (new DateTimeImmutable())->modify('+1825 days')->modify('-1 day'),
            $expiresAt,
        );
        self::assertSame([], $this->logger->findByLevel('warning'));
    }

    #[Test]
    public function rotatedTokenOfAnyAgeIsReissuedInTheSameFamilyAndNothingIsRevoked(): void
    {
        // The lost-response / stale-copy case: the server rotated this token
        // LONG ago (well past the old 30-day grace window), the client never
        // stored the replacement and presents the old one. It must be
        // re-issued, never treated as theft.
        $user = $this->makeUser(id: 99);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $row = $this->makeRefreshRow($user, $pair, familyId: 'fam-99');
        $row->revoke('rotated');
        $rotatedAt = (new DateTimeImmutable())->modify('-400 days');
        $this->setRevokedAt($row, $rotatedAt);

        $saved = [];
        $refreshRepo = $this->refreshRepo($row, $saved);
        $refreshRepo->expects(self::never())->method('revokeAllForUser');
        $refreshRepo->expects(self::never())->method('revokeFamily');

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNotSame($pair->refreshToken, $body['refresh_token']);
        self::assertSame(99, $body['user']['id']);

        // Replacement joins the family; the replayed row keeps its original
        // rotation timestamp (revoke() is idempotent).
        self::assertCount(1, $saved);
        self::assertSame('fam-99', $saved[0]->getFamilyId());
        self::assertSame('rotated', $row->getRevokedReason());
        self::assertEquals($rotatedAt, $row->getRevokedAt());

        // Replay is logged at info, not as a rejection.
        self::assertCount(1, $this->logger->findByMessage('auth.refresh.rotated_replay'));
        self::assertSame([], $this->logger->findByMessage('auth.refresh.rejected'));
    }

    #[Test]
    public function sameRotatedTokenPresentedTwiceIsReissuedBothTimes(): void
    {
        // Two tabs / a retry racing: both presentations of the same token
        // succeed and both new rows stay in the one family.
        $user = $this->makeUser(id: 12);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRefreshRow($user, $pair, familyId: 'fam-12');

        $saved = [];
        $refreshRepo = $this->refreshRepo($row, $saved);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        self::assertSame(200, $this->refresh($pair->refreshToken)->getStatusCode());
        self::assertSame(200, $this->refresh($pair->refreshToken)->getStatusCode());

        self::assertCount(2, $saved);
        self::assertSame('fam-12', $saved[0]->getFamilyId());
        self::assertSame('fam-12', $saved[1]->getFamilyId());
    }

    #[Test]
    public function legacyRowWithoutFamilyStartsAFamilyFromItsJti(): void
    {
        // A row written before family_id existed (NULL in the DB): the
        // replacement inherits the row's jti as the family.
        $user = $this->makeUser(id: 8);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRefreshRow($user, $pair);
        $ref = new \ReflectionProperty(RefreshToken::class, 'familyId');
        $ref->setValue($row, null);

        $saved = [];
        $refreshRepo = $this->refreshRepo($row, $saved);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        self::assertSame(200, $this->refresh($pair->refreshToken)->getStatusCode());
        self::assertSame($pair->refreshTokenJti, $saved[0]->getFamilyId());
    }

    #[Test]
    public function rowUserWinsOverJwtSubject(): void
    {
        // Phone-claim merge re-points the row to the surviving account; the
        // JWT still names the merged-away account. The refresh must continue
        // as the ROW's user.
        $source = $this->makeUser(id: 30, email: 'social@example.com');
        $target = $this->makeUser(id: 31, email: 'real@example.com');
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($source); // sub = 30
        $row = $this->makeRefreshRow($target, $pair); // row re-pointed to 31

        $saved = [];
        $refreshRepo = $this->refreshRepo($row, $saved);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(31, $body['user']['id']);
        self::assertSame(31, $jwt->verifyAccessToken($body['access_token'])?->userId);
        self::assertSame($target, $saved[0]->getUser());
    }

    #[Test]
    public function logoutCommittedWhileTheRefreshWasInFlightWins(): void
    {
        // The token was live when first checked, but a logout of this
        // device committed before the rotation took the row lock: the
        // locked re-read sees 'logout', nothing is written (no 'rotated'
        // over 'logout', no new live row) and the refresh gets the 401.
        $user = $this->makeUser(id: 14);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRefreshRow($user, $pair, familyId: 'fam-14');

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $refreshRepo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($refreshRepo): void {
            $em->method('getRepository')->willReturn($refreshRepo);
            $em->method('refresh')->willReturnCallback(
                static fn (RefreshToken $locked) => $locked->revoke('logout'),
            );
            $em->expects(self::never())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        $this->assertRefreshInvalid($response);
        self::assertSame('logout', $row->getRevokedReason());
        $this->assertRejectedWith('revoked:logout', 14);
    }

    #[Test]
    public function mergeCommittedWhileTheRefreshWasInFlightContinuesAsTheTarget(): void
    {
        // A phone-claim merge re-pointed the row to the surviving account
        // after the first check: the locked re-read sees the new owner and
        // the device continues as that account (pair, row and profile).
        $source = $this->makeUser(id: 40, email: 'relay@example.com');
        $target = $this->makeUser(id: 41, email: 'real@example.com');
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($source);
        $row = $this->makeRefreshRow($source, $pair, familyId: 'fam-40');

        $saved = [];
        $refreshRepo = $this->refreshRepo($row, $saved);
        $em = $this->stubEm(function ($em) use ($refreshRepo, $target): void {
            $em->method('getRepository')->willReturn($refreshRepo);
            $em->method('refresh')->willReturnCallback(
                static fn (RefreshToken $locked) => (new \ReflectionProperty(RefreshToken::class, 'user'))
                    ->setValue($locked, $target),
            );
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(41, $body['user']['id']);
        $claims = $jwt->verifyAccessToken($body['access_token']);
        self::assertSame(41, $claims?->userId);
        self::assertSame('fam-40', $claims?->sessionFamilyId);
        self::assertCount(1, $saved);
        self::assertSame($target, $saved[0]->getUser());
        self::assertSame('fam-40', $saved[0]->getFamilyId());
    }

    #[Test]
    public function profileIsBuiltBeforeRotation(): void
    {
        // If building the response profile fails, the presented token must
        // NOT have been rotated (no rotate-then-500).
        $user = $this->makeUser(id: 5);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRefreshRow($user, $pair);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $refreshRepo->expects(self::never())->method('save');

        // UserSerializer::publicProfile() reads the user's stores through
        // getRepository(Vendor::class); make that lookup fail.
        $vendorRepo = $this->createMock(EntityRepository::class);
        $vendorRepo->method('findBy')->willThrowException(new \RuntimeException('db down'));

        $em = $this->stubEm(function ($em) use ($refreshRepo, $vendorRepo): void {
            $em->method('getRepository')->willReturnCallback(
                fn (string $class) => $class === Vendor::class ? $vendorRepo : $refreshRepo,
            );
            $em->expects(self::never())->method('wrapInTransaction');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($row->isRevoked(), 'token must not be rotated when the profile fails');
    }

    // -------------------------------------------------------------------
    // Session over: 401 AUTH_REFRESH_TOKEN_INVALID, nothing else touched
    // -------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function endedReasons(): iterable
    {
        yield 'explicit logout' => ['logout'];
        yield 'logout everywhere' => ['logout_all'];
        yield 'historical reuse_detected' => ['reuse_detected'];
        yield 'historical password_changed' => ['password_changed'];
        yield 'historical admin_password_reset' => ['admin_password_reset'];
    }

    #[Test]
    #[DataProvider('endedReasons')]
    public function endedTokenGets401AndNoOtherRowIsTouched(string $reason): void
    {
        $user = $this->makeUser(id: 42);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $row = $this->makeRefreshRow($user, $pair);
        $row->revoke($reason);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        // The old cascade: a single bad token wiped every session. Never again.
        $refreshRepo->expects(self::never())->method('revokeAllForUser');
        $refreshRepo->expects(self::never())->method('revokeFamily');
        $refreshRepo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($refreshRepo): void {
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo);
            $em->expects(self::never())->method('flush');
            $em->expects(self::never())->method('wrapInTransaction');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        $this->assertRefreshInvalid($response);
        self::assertSame($reason, $row->getRevokedReason(), 'the row itself is unchanged');
        $this->assertRejectedWith('revoked:' . $reason, 42);
    }

    #[Test]
    public function returns401ForMalformedToken(): void
    {
        $response = $this->refresh('not.a.valid.jwt');

        $this->assertRefreshInvalid($response);
        $this->assertRejectedWith('invalid_jwt', null);
    }

    #[Test]
    public function returns401WhenAccessTokenSentInsteadOfRefresh(): void
    {
        $user = $this->makeUser();
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->refresh($pair->accessToken); // wrong audience

        $this->assertRefreshInvalid($response);
        $this->assertRejectedWith('invalid_jwt', null);
    }

    #[Test]
    public function returns401WhenJtiNotInDb(): void
    {
        $user = $this->makeUser(id: 3);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        $this->assertRefreshInvalid($response);
        $this->assertRejectedWith('unknown_jti', 3);
    }

    #[Test]
    public function returns401OnHashMismatch(): void
    {
        $user = $this->makeUser(id: 4);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $row = new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: hash('sha256', 'a-different-token'),
            expiresAt: $pair->refreshTokenExpiresAt,
        );

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        $this->assertRefreshInvalid($response);
        self::assertFalse($row->isRevoked());
        $this->assertRejectedWith('hash_mismatch', 4);
    }

    #[Test]
    public function returns401WhenUserDeactivated(): void
    {
        $user = $this->makeUser(id: 6, active: true); // start active
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // Now deactivate AFTER issuing the token, simulates admin
        // disabling the account between login and refresh.
        $user->deactivate();

        $row = $this->makeRefreshRow($user, $pair);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        $this->assertRefreshInvalid($response);
        self::assertFalse($row->isRevoked());
        $this->assertRejectedWith('inactive_user', 6);
    }

    #[Test]
    public function returns401WhenUserSoftDeleted(): void
    {
        $user = $this->makeUser(id: 9);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $user->softDelete();

        $row = $this->makeRefreshRow($user, $pair);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $this->assertRefreshInvalid($this->refresh($pair->refreshToken));
        $this->assertRejectedWith('inactive_user', 9);
    }

    #[Test]
    public function returns401WhenRowExpiredInDb(): void
    {
        $user = $this->makeUser(id: 10);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // Forge a row whose expires_at is in the past.
        $row = new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: $pair->refreshTokenHash(),
            expiresAt: (new DateTimeImmutable())->modify('-1 second'),
        );

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->refresh($pair->refreshToken);

        $this->assertRefreshInvalid($response);
        $this->assertRejectedWith('expired', 10);
    }

    #[Test]
    public function returns422OnMissingRefreshToken(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', []));
        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectionLogsNeverContainTheToken(): void
    {
        $user = $this->makeUser(id: 11);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRefreshRow($user, $pair);
        $row->revoke('logout');

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $this->refresh($pair->refreshToken);

        $encoded = (string) json_encode($this->logger->records());
        self::assertStringNotContainsString($pair->refreshToken, $encoded);
        self::assertStringNotContainsString($pair->refreshTokenHash(), $encoded);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function refresh(string $token): \Psr\Http\Message\ResponseInterface
    {
        return $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $token,
        ]));
    }

    private function makeRefreshRow(User $user, TokenPair $pair, ?string $familyId = null): RefreshToken
    {
        return new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: $pair->refreshTokenHash(),
            expiresAt: $pair->refreshTokenExpiresAt,
            familyId: $familyId,
        );
    }

    /**
     * Repo double whose findByJti returns $row and whose save() records
     * the persisted replacement rows.
     *
     * @param list<RefreshToken> $saved
     */
    private function refreshRepo(RefreshToken $row, array &$saved): RefreshTokenRepository&\PHPUnit\Framework\MockObject\MockObject
    {
        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $refreshRepo->method('save')->willReturnCallback(
            function (RefreshToken $t) use (&$saved): void {
                $saved[] = $t;
            },
        );
        return $refreshRepo;
    }

    private function setRevokedAt(RefreshToken $row, DateTimeImmutable $at): void
    {
        $ref = new \ReflectionProperty(RefreshToken::class, 'revokedAt');
        $ref->setValue($row, $at);
    }

    private function assertRefreshInvalid(\Psr\Http\Message\ResponseInterface $response): void
    {
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::AUTH_REFRESH_TOKEN_INVALID,
            $this->jsonBody($response)['error']['code'],
        );
    }

    private function assertRejectedWith(string $reason, ?int $userId): void
    {
        $rejections = $this->logger->findByMessage('auth.refresh.rejected');
        self::assertCount(1, $rejections);
        self::assertSame('warning', $rejections[0]['level']);
        self::assertSame($reason, $rejections[0]['context']['reason']);
        self::assertSame($userId, $rejections[0]['context']['user_id']);
    }
}
