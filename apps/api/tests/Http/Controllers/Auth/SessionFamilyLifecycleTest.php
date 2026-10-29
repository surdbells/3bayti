<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\SocialIdentity;
use Bayti\Api\Domain\User\SocialIdentityRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\LogoutAllController;
use Bayti\Api\Http\Controllers\Auth\LogoutController;
use Bayti\Api\Http\Controllers\Auth\RefreshController;
use Bayti\Api\Http\Controllers\Auth\ResetConfirmController;
use Bayti\Api\Http\Controllers\Me\ClaimPhoneVerifyController;
use Bayti\Api\Http\Controllers\Profile\ChangePasswordController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\TokenPair;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use Bayti\Api\Tests\Support\InMemoryRefreshTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * One device = one session family, end to end across requests (in-memory
 * user_refresh_tokens):
 *
 *   - a password change, a forgotten-password reset made while signed in,
 *     and a phone-claim merge hand the device a new pair IN ITS OWN family,
 *     so its later explicit logout also ends every token it held before
 *     (a stale stored copy can't bring the session back);
 *   - none of them, nor the logout, touches another device's session;
 *   - logout-all ends rotated tokens too.
 */
#[CoversClass(ChangePasswordController::class)]
#[CoversClass(ResetConfirmController::class)]
#[CoversClass(ClaimPhoneVerifyController::class)]
#[CoversClass(LogoutController::class)]
#[CoversClass(LogoutAllController::class)]
#[CoversClass(RefreshController::class)]
final class SessionFamilyLifecycleTest extends HttpTestCase
{
    private InMemoryRefreshTokenRepository $tokens;
    private InMemoryOtpProvider $otpProvider;
    private ?OtpAttempt $attempt = null;

    /** @var array<int, User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    #[Test]
    public function changePasswordThenLogoutEndsThePreChangeTokensToo(): void
    {
        $user = $this->makeUser(id: 50, passwordPlain: 'OldPass123');
        $this->world([$user]);

        $phone0 = $this->signIn($user);
        $web0 = $this->signIn($user); // another device

        $phone1 = $this->refreshOk($phone0->refreshToken);

        $changed = $this->handle($this->jsonRequest('PATCH', '/v3/me/password', [
            'current_password' => 'OldPass123',
            'new_password' => 'NewPass4567',
        ], ['Authorization' => 'Bearer ' . $phone1['access_token']]));
        self::assertSame(200, $changed->getStatusCode());
        $phone2 = $this->jsonBody($changed);
        self::assertSame(
            $phone0->familyId,
            $this->tokens->findByJti($this->jti($phone2['refresh_token']))?->getFamilyId(),
            'the new pair stays in the phone\'s family',
        );

        self::assertSame(204, $this->logout($phone2['refresh_token'])->getStatusCode());

        // Every token the phone ever held is over: the one in use before the
        // change (never revoked by it), its rotated ancestor, the new one.
        $this->assertSessionOver($phone1['refresh_token']);
        $this->assertSessionOver($phone0->refreshToken);
        $this->assertSessionOver($phone2['refresh_token']);

        // The other device never noticed.
        $this->refreshOk($web0->refreshToken);
    }

    #[Test]
    public function resetWhileSignedInThenLogoutEndsThePreResetTokensToo(): void
    {
        $user = $this->makeUser(id: 51, passwordPlain: 'OldPass123');
        $verificationId = $this->otpProvider->send($user->getPhone());
        $this->attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: $user->getPhone(),
            purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $user,
        );
        $this->world([$user]);

        $phone0 = $this->signIn($user);
        $web0 = $this->signIn($user);
        $phone1 = $this->refreshOk($phone0->refreshToken);

        $reset = $this->handle($this->jsonRequest('POST', '/v3/auth/reset/confirm', [
            'verification_id' => $verificationId,
            'code' => '000000',
            'new_password' => 'NewPass4567',
        ], ['Authorization' => 'Bearer ' . $phone1['access_token']]));
        self::assertSame(200, $reset->getStatusCode());
        $phone2 = $this->jsonBody($reset);

        self::assertSame(204, $this->logout($phone2['refresh_token'])->getStatusCode());

        $this->assertSessionOver($phone1['refresh_token']);
        $this->assertSessionOver($phone0->refreshToken);
        $this->refreshOk($web0->refreshToken);
    }

    #[Test]
    public function mergeThenLogoutEndsTheCallersPreMergeTokensAndKeepsOtherDevices(): void
    {
        $source = $this->makeUser(id: 60, email: 'relay@privaterelay.appleid.com', phone: '+971500000060');
        $target = $this->makeUser(id: 61, email: 'real@example.com', phone: '+971501112222');
        $verificationId = $this->otpProvider->send('+971501112222');
        $this->attempt = new OtpAttempt(
            verificationId: $verificationId,
            phone: '+971501112222',
            purpose: OtpAttempt::PURPOSE_ACCOUNT_LINK,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $source,
        );
        $this->world([$source, $target], phoneOwners: [$target]);

        $caller0 = $this->signIn($source);
        $tablet0 = $this->signIn($source); // the throwaway account's other device
        $caller1 = $this->refreshOk($caller0->refreshToken);

        $merged = $this->handle($this->jsonRequest('POST', '/v3/me/phone/claim/verify', [
            'verification_id' => $verificationId,
            'code' => '000000',
        ], ['Authorization' => 'Bearer ' . $caller1['access_token']]));
        self::assertSame(200, $merged->getStatusCode());
        $caller2 = $this->jsonBody($merged);
        self::assertSame(61, $caller2['user']['id']);

        self::assertSame(204, $this->logout($caller2['refresh_token'])->getStatusCode());

        // The caller's pre-merge tokens were moved (not revoked) by the
        // merge; its logout ends them with the rest of its family.
        $this->assertSessionOver($caller1['refresh_token']);
        $this->assertSessionOver($caller0->refreshToken);

        // The other device of the merged-away account carries on as the
        // surviving account.
        self::assertSame(61, $this->refreshOk($tablet0->refreshToken)['user']['id']);
    }

    #[Test]
    public function logoutAllEndsRotatedTokensOfEveryDevice(): void
    {
        $user = $this->makeUser(id: 52);
        $this->world([$user]);

        $phone0 = $this->signIn($user);
        $web0 = $this->signIn($user);
        $phone1 = $this->refreshOk($phone0->refreshToken);

        $all = $this->handle($this->jsonRequest('POST', '/v3/auth/logout-all', [], [
            'Authorization' => 'Bearer ' . $phone1['access_token'],
        ]));
        self::assertSame(204, $all->getStatusCode());

        $this->assertSessionOver($phone0->refreshToken); // rotated
        $this->assertSessionOver($phone1['refresh_token']);
        $this->assertSessionOver($web0->refreshToken);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * @param list<User> $users
     * @param list<User> $phoneOwners what findActiveOwnersByPhone returns
     */
    private function world(array $users, array $phoneOwners = []): void
    {
        foreach ($users as $u) {
            $this->users[(int) $u->getId()] = $u;
        }

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturnCallback(fn (int $id): ?User => $this->users[$id] ?? null);
        $userRepo->method('findActiveOwnersByPhone')->willReturn($phoneOwners);

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturnCallback(fn (): ?OtpAttempt => $this->attempt);

        $identities = $this->createMock(SocialIdentityRepository::class);

        // publicProfile() (in every 200 envelope) reads the user's stores.
        $vendorRepo = $this->createMock(EntityRepository::class);
        $vendorRepo->method('findBy')->willReturn([]);

        $em = $this->stubEm(function ($em) use ($userRepo, $otpRepo, $identities, $vendorRepo): void {
            $this->tokens = new InMemoryRefreshTokenRepository($em);
            $em->method('getRepository')->willReturnCallback(fn (string $class): EntityRepository => match ($class) {
                RefreshToken::class => $this->tokens,
                User::class => $userRepo,
                OtpAttempt::class => $otpRepo,
                SocialIdentity::class => $identities,
                Vendor::class => $vendorRepo,
                default => $this->createMock(EntityRepository::class),
            });
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    /** A sign-in (LoginController): a fresh pair in a new family. */
    private function signIn(User $user): TokenPair
    {
        $pair = $this->app->getContainer()->get(JwtService::class)->issueTokenPair($user);
        $this->tokens->save(new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: $pair->refreshTokenHash(),
            expiresAt: $pair->refreshTokenExpiresAt,
        ));
        return $pair;
    }

    /** @return array<string, mixed> the 200 body */
    private function refreshOk(string $refreshToken): array
    {
        $response = $this->refresh($refreshToken);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        return $this->jsonBody($response);
    }

    private function assertSessionOver(string $refreshToken): void
    {
        $response = $this->refresh($refreshToken);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame(ErrorCodes::AUTH_REFRESH_TOKEN_INVALID, $this->jsonBody($response)['error']['code']);
    }

    private function refresh(string $refreshToken): ResponseInterface
    {
        return $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]));
    }

    /** Fire-and-forget logout, as the clients send it: no bearer. */
    private function logout(string $refreshToken): ResponseInterface
    {
        return $this->handle($this->jsonRequest('POST', '/v3/auth/logout', [
            'refresh_token' => $refreshToken,
        ]));
    }

    private function jti(string $refreshToken): string
    {
        $claims = $this->app->getContainer()->get(JwtService::class)->verifyRefreshToken($refreshToken);
        self::assertNotNull($claims);
        return $claims->jti;
    }
}
