<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Entity behaviour tests. No database — these exercise pure PHP
 * logic in the entity classes (constructors, mutators, computed
 * properties).
 *
 * Schema-level tests (do migrations apply cleanly, are FK constraints
 * correct, do bulk-update queries do what they claim) live separately;
 * those need a real PostgreSQL connection and run under M1.4+ when
 * the integration test setup is in place.
 */
#[CoversClass(User::class)]
#[CoversClass(Address::class)]
#[CoversClass(Measurement::class)]
#[CoversClass(RefreshToken::class)]
#[CoversClass(OtpAttempt::class)]
final class UserEntityTest extends TestCase
{
    // -------------------------------------------------------------------
    // User
    // -------------------------------------------------------------------

    #[Test]
    public function userConstructorNormalisesEmail(): void
    {
        $user = $this->makeUser('  Alice@Example.COM  ');
        self::assertSame('alice@example.com', $user->getEmail());
    }

    #[Test]
    public function userConstructorUppercasesCountryCode(): void
    {
        $user = new User('a@b.com', '+971501234567', 'pwhash', 'ae');
        self::assertSame('AE', $user->getCountryCode());
    }

    #[Test]
    public function userDefaultsCustomerFlagTrue(): void
    {
        $user = $this->makeUser();
        self::assertTrue($user->isCustomer());
        self::assertFalse($user->isVendor());
        self::assertFalse($user->isAdmin());
    }

    #[Test]
    public function setRolesUpdatesOnlyProvidedFlags(): void
    {
        $user = $this->makeUser();
        // Set vendor true; don't pass others — they keep their defaults.
        $user->setRoles(vendor: true);

        self::assertTrue($user->isCustomer()); // unchanged from default
        self::assertTrue($user->isVendor());   // newly set
        self::assertFalse($user->isAdmin());   // still default
    }

    #[Test]
    public function setPasswordHashRecordsChangeTimestamp(): void
    {
        $user = $this->makeUser();
        self::assertNull($user->getPasswordChangedAt());

        $user->setPasswordHash('newhash');

        self::assertSame('newhash', $user->getPasswordHash());
        self::assertNotNull($user->getPasswordChangedAt());
    }

    #[Test]
    public function softDeleteMakesIsDeletedTrue(): void
    {
        $user = $this->makeUser();
        self::assertFalse($user->isDeleted());

        $user->softDelete();
        self::assertTrue($user->isDeleted());
        self::assertNotNull($user->getDeletedAt());

        $user->restore();
        self::assertFalse($user->isDeleted());
        self::assertNull($user->getDeletedAt());
    }

    #[Test]
    public function bindLegacyIdRefusesRebind(): void
    {
        $user = $this->makeUser();
        $user->bindLegacyId(42);
        self::assertSame(42, $user->getLegacyUserId());

        $this->expectException(\LogicException::class);
        $user->bindLegacyId(43);
    }

    // -------------------------------------------------------------------
    // RefreshToken
    // -------------------------------------------------------------------

    #[Test]
    public function refreshTokenIsActiveWhenFresh(): void
    {
        $user = $this->makeUser();
        $token = new RefreshToken(
            $user,
            jti: 'test-jti',
            tokenHash: hash('sha256', 'rawtoken'),
            expiresAt: (new DateTimeImmutable())->modify('+7 days'),
        );

        self::assertTrue($token->isActive());
        self::assertFalse($token->isExpired());
        self::assertFalse($token->isRevoked());
    }

    #[Test]
    public function refreshTokenExpiresWhenPastExpiry(): void
    {
        $user = $this->makeUser();
        $token = new RefreshToken(
            $user,
            'jti-x',
            hash('sha256', 'r'),
            (new DateTimeImmutable())->modify('-1 second'),
        );

        self::assertTrue($token->isExpired());
        self::assertFalse($token->isActive());
    }

    #[Test]
    public function refreshTokenMatchesRawToken(): void
    {
        $user = $this->makeUser();
        $raw = 'thisIsTheRawRefreshToken123';
        $token = new RefreshToken(
            $user,
            'jti',
            hash('sha256', $raw),
            (new DateTimeImmutable())->modify('+7 days'),
        );

        self::assertTrue($token->matchesToken($raw));
        self::assertFalse($token->matchesToken('wrong'));
    }

    #[Test]
    public function refreshTokenRevokeIsIdempotent(): void
    {
        $user = $this->makeUser();
        $token = new RefreshToken(
            $user,
            'j',
            hash('sha256', 'r'),
            (new DateTimeImmutable())->modify('+7 days'),
        );

        $token->revoke('logout');
        $firstRevokedAt = $token->getRevokedAt();

        // Trying again — should not change the timestamp.
        $token->revoke('logout_all');
        self::assertSame($firstRevokedAt, $token->getRevokedAt());
        self::assertSame('logout', $token->getRevokedReason());
    }

    // -------------------------------------------------------------------
    // OtpAttempt
    // -------------------------------------------------------------------

    #[Test]
    public function otpRejectsUnknownPurpose(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OtpAttempt(
            phone: '+971501234567',
            purpose: 'totally_made_up',
            codeHash: hash('sha256', '123456' . 'salt'),
            salt: 'salt',
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
        );
    }

    #[Test]
    public function otpVerifyAcceptsCorrectCodeOnce(): void
    {
        $code = '654321';
        $salt = bin2hex(random_bytes(16));
        $otp = new OtpAttempt(
            phone: '+971501234567',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            codeHash: hash('sha256', $code . $salt),
            salt: $salt,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
        );

        self::assertTrue($otp->verify($code));
        self::assertTrue($otp->isConsumed());

        // Re-verify with the same code — the row is consumed, so reject.
        self::assertFalse($otp->verify($code));
    }

    #[Test]
    public function otpVerifyRejectsWrongCode(): void
    {
        $otp = $this->makeOtp('999999');
        self::assertFalse($otp->verify('111111'));
        self::assertFalse($otp->isConsumed());
        self::assertSame(1, $otp->getVerifyAttempts());
    }

    #[Test]
    public function otpExhaustsAfter5FailedAttempts(): void
    {
        $otp = $this->makeOtp('correct');

        for ($i = 0; $i < 5; $i++) {
            $otp->verify('wrong');
        }

        self::assertTrue($otp->isExhausted());
        // Even the right code now fails — row is exhausted.
        self::assertFalse($otp->verify('correct'));
    }

    #[Test]
    public function otpExpiresAfterTtl(): void
    {
        $otp = new OtpAttempt(
            phone: '+971500000000',
            purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
            codeHash: hash('sha256', '123456' . 's'),
            salt: 's',
            expiresAt: (new DateTimeImmutable())->modify('-1 second'),
        );

        self::assertTrue($otp->isExpired());
        self::assertFalse($otp->isUsable());
        self::assertFalse($otp->verify('123456'));
    }

    // -------------------------------------------------------------------
    // Address
    // -------------------------------------------------------------------

    #[Test]
    public function addressTrimsFields(): void
    {
        $user = $this->makeUser();
        $addr = new Address(
            user: $user,
            recipientName: '  Alice Smith  ',
            recipientPhone: ' +971501234567 ',
            emirate: ' Dubai ',
            area: ' Jumeirah ',
        );

        self::assertSame('Alice Smith', $addr->getRecipientName());
        self::assertSame('+971501234567', $addr->getRecipientPhone());
        self::assertSame('Dubai', $addr->getEmirate());
        self::assertSame('Jumeirah', $addr->getArea());
    }

    #[Test]
    public function addressDefaultsToAEAndNotDefaultFlags(): void
    {
        $user = $this->makeUser();
        $addr = new Address($user, 'A', '+971500000000', 'Dubai', 'Marina');

        self::assertSame('AE', $addr->getCountry());
        self::assertFalse($addr->isDefaultShipping());
        self::assertFalse($addr->isDefaultBilling());
    }

    // -------------------------------------------------------------------
    // Measurement
    // -------------------------------------------------------------------

    #[Test]
    public function measurementStoresValuesAsAssociativeArray(): void
    {
        $user = $this->makeUser();
        $values = ['arm' => 60.0, 'bust' => 92.0, 'hip' => 96.0];
        $m = new Measurement($user, $values);

        self::assertSame($values, $m->getValues());
        self::assertNull($m->getCategoryId());
    }

    #[Test]
    public function measurementUpdatePartiallyOverwrites(): void
    {
        $user = $this->makeUser();
        $m = new Measurement($user, ['arm' => 60.0]);

        $m->update(values: ['arm' => 62.0, 'bust' => 92.0], notes: 'updated');

        self::assertSame(['arm' => 62.0, 'bust' => 92.0], $m->getValues());
        self::assertSame('updated', $m->getNotes());
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeUser(string $email = 'user@example.com'): User
    {
        return new User($email, '+971501234567', 'fake-bcrypt-hash', 'AE');
    }

    private function makeOtp(string $code, string $purpose = OtpAttempt::PURPOSE_REGISTRATION): OtpAttempt
    {
        $salt = bin2hex(random_bytes(16));
        return new OtpAttempt(
            phone: '+971501234567',
            purpose: $purpose,
            codeHash: hash('sha256', $code . $salt),
            salt: $salt,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
        );
    }
}
