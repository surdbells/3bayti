<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Me;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\SocialIdentity;
use Bayti\Api\Domain\User\SocialIdentityRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Me\ClaimPhoneVerifyController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /v3/me/phone/claim/verify, the phone-claim account merge.
 *
 * Product rule: a merge must never sign anyone out. The merged-away
 * (source) account's sessions are RE-POINTED to the surviving (target)
 * account instead of revoked, so its other devices continue as the target
 * on their next refresh.
 */
#[CoversClass(ClaimPhoneVerifyController::class)]
final class ClaimPhoneVerifyControllerTest extends HttpTestCase
{
    private InMemoryOtpProvider $otpProvider;

    /** Session family of the caller's access token in the last verify(). */
    private ?string $callerFamilyId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otpProvider = new InMemoryOtpProvider();
        $this->bind(OtpProvider::class, $this->otpProvider);
    }

    #[Test]
    public function mergeReassignsSessionsToTargetInsteadOfRevokingThem(): void
    {
        $source = $this->makeUser(id: 20, email: 'apple-relay@privaterelay.appleid.com', phone: '+971500000020');
        $target = $this->makeUser(id: 21, email: 'real@example.com', phone: '+971501112222');

        $verificationId = $this->otpProvider->send('+971501112222');
        $attempt = $this->attempt($verificationId, $source);

        $saved = [];
        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::once())
            ->method('reassignUser')
            ->with(self::identicalTo($source), self::identicalTo($target))
            ->willReturn(2);
        // Never revoke: not the source's sessions, not anyone's.
        $refreshRepo->expects(self::never())->method('revokeAllForUser');
        $refreshRepo->expects(self::never())->method('revokeFamily');
        $refreshRepo->expects(self::once())->method('save')->willReturnCallback(
            function (RefreshToken $t) use (&$saved): void {
                $saved[] = $t;
            },
        );

        $identities = $this->createMock(SocialIdentityRepository::class);
        $identities->expects(self::once())->method('reassignToUser')->with(20, 21);

        $this->bindEm($source, [$target], $attempt, $refreshRepo, $identities);

        $response = $this->verify($source, $verificationId, '000000');

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(21, $body['user']['id']);

        // The caller's new pair is for the TARGET account, and stays in the
        // caller's own session family (whose rows were just moved to the
        // target), so this device still has ONE family to log out of.
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $claims = $jwt->verifyAccessToken($body['access_token']);
        self::assertSame(21, $claims?->userId);
        self::assertCount(1, $saved);
        self::assertSame($target, $saved[0]->getUser());
        self::assertTrue($saved[0]->matchesToken($body['refresh_token']));
        self::assertNotNull($this->callerFamilyId);
        self::assertSame($this->callerFamilyId, $saved[0]->getFamilyId());
        self::assertSame($this->callerFamilyId, $claims?->sessionFamilyId);

        // The throwaway account is still retired.
        self::assertFalse($source->isActive());
        self::assertTrue($source->isDeleted());
        self::assertTrue($target->isPhoneVerified());
    }

    #[Test]
    public function wrongCodeMergesNothing(): void
    {
        $source = $this->makeUser(id: 20, email: 'apple-relay@privaterelay.appleid.com', phone: '+971500000020');
        $target = $this->makeUser(id: 21, email: 'real@example.com', phone: '+971501112222');

        $verificationId = $this->otpProvider->send('+971501112222');
        $attempt = $this->attempt($verificationId, $source);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::never())->method('reassignUser');
        $refreshRepo->expects(self::never())->method('revokeAllForUser');

        $identities = $this->createMock(SocialIdentityRepository::class);
        $identities->expects(self::never())->method('reassignToUser');

        $this->bindEm($source, [$target], $attempt, $refreshRepo, $identities);

        $response = $this->verify($source, $verificationId, '999999');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(ErrorCodes::OTP_VERIFICATION_FAILED, $this->jsonBody($response)['error']['code']);
        self::assertTrue($source->isActive());
        self::assertFalse($source->isDeleted());
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function attempt(string $verificationId, User $source): OtpAttempt
    {
        return new OtpAttempt(
            verificationId: $verificationId,
            phone: '+971501112222',
            purpose: OtpAttempt::PURPOSE_ACCOUNT_LINK,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            user: $source,
        );
    }

    /** @param list<User> $owners phone owners findActiveOwnersByPhone returns */
    private function bindEm(
        User $caller,
        array $owners,
        OtpAttempt $attempt,
        RefreshTokenRepository $refreshRepo,
        SocialIdentityRepository $identities,
    ): void {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturnCallback(
            fn (int $id): ?User => $id === $caller->getId() ? $caller : null,
        );
        $userRepo->method('findActiveOwnersByPhone')->willReturn($owners);

        $otpRepo = $this->createMock(OtpAttemptRepository::class);
        $otpRepo->method('findByVerificationId')->willReturn($attempt);

        // publicProfile() (in the 200 envelope) reads the user's stores.
        $vendorRepo = $this->createMock(EntityRepository::class);
        $vendorRepo->method('findBy')->willReturn([]);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [OtpAttempt::class, $otpRepo],
                [RefreshToken::class, $refreshRepo],
                [SocialIdentity::class, $identities],
                [Vendor::class, $vendorRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function verify(User $caller, string $verificationId, string $code): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($caller);
        $this->callerFamilyId = $pair->familyId;
        return $this->handle($this->jsonRequest('POST', '/v3/me/phone/claim/verify', [
            'verification_id' => $verificationId,
            'code' => $code,
        ], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }
}
