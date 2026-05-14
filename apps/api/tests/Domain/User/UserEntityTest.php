<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\Gender;
use Bayti\Api\Domain\User\Measurement;
use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserLocation;
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
#[CoversClass(Gender::class)]
#[CoversClass(Measurement::class)]
#[CoversClass(RefreshToken::class)]
#[CoversClass(OtpAttempt::class)]
#[CoversClass(UserLocation::class)]
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
    // Profile fields (M1.7.0)
    // -------------------------------------------------------------------

    #[Test]
    public function userDefaultsLocaleToEnglish(): void
    {
        $user = $this->makeUser();
        self::assertSame('en', $user->getLocale());
    }

    #[Test]
    public function userDefaultsTimezoneToDubai(): void
    {
        $user = $this->makeUser();
        self::assertSame('Asia/Dubai', $user->getTimezone());
    }

    #[Test]
    public function userDefaultsGenderAndDobToNull(): void
    {
        $user = $this->makeUser();
        self::assertNull($user->getGender());
        self::assertNull($user->getDob());
    }

    #[Test]
    public function setGenderStoresEnumStringValue(): void
    {
        $user = $this->makeUser();
        $user->setGender(Gender::Male);
        self::assertSame('male', $user->getGender());

        $user->setGender(Gender::PreferNotToSay);
        self::assertSame('prefer_not_to_say', $user->getGender());
    }

    #[Test]
    public function setGenderToNullClearsValue(): void
    {
        $user = $this->makeUser();
        $user->setGender(Gender::Female);
        $user->setGender(null);
        self::assertNull($user->getGender());
    }

    #[Test]
    public function setDobNormalisesToMidnight(): void
    {
        $user = $this->makeUser();
        // Intentionally pass a time component to ensure it gets normalised.
        $user->setDob(new DateTimeImmutable('1990-03-14 15:30:00'));

        $dob = $user->getDob();
        self::assertNotNull($dob);
        self::assertSame('1990-03-14', $dob->format('Y-m-d'));
        // Time component should be midnight.
        self::assertSame('00:00:00', $dob->format('H:i:s'));
    }

    #[Test]
    public function setLocaleTrimsWhitespace(): void
    {
        $user = $this->makeUser();
        $user->setLocale('  ar-AE  ');
        self::assertSame('ar-AE', $user->getLocale());
    }

    #[Test]
    public function setTimezoneTrimsWhitespace(): void
    {
        $user = $this->makeUser();
        $user->setTimezone('  Europe/London  ');
        self::assertSame('Europe/London', $user->getTimezone());
    }

    // -------------------------------------------------------------------
    // Gender enum
    // -------------------------------------------------------------------

    #[Test]
    public function genderValuesIncludesAllFour(): void
    {
        self::assertSame(
            ['male', 'female', 'other', 'prefer_not_to_say'],
            Gender::values(),
        );
    }

    #[Test]
    public function genderFromStringOrNullReturnsNullForEmptyOrInvalid(): void
    {
        self::assertNull(Gender::fromStringOrNull(null));
        self::assertNull(Gender::fromStringOrNull(''));
        self::assertNull(Gender::fromStringOrNull('invalid'));
    }

    #[Test]
    public function genderFromStringOrNullReturnsEnumForValidString(): void
    {
        self::assertSame(Gender::Male, Gender::fromStringOrNull('male'));
        self::assertSame(Gender::Female, Gender::fromStringOrNull('female'));
        self::assertSame(Gender::PreferNotToSay, Gender::fromStringOrNull('prefer_not_to_say'));
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
            verificationId: 'mc-xyz-123',
            phone: '+971501234567',
            purpose: 'totally_made_up',
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
        );
    }

    #[Test]
    public function otpAcceptsKnownPurposes(): void
    {
        foreach (OtpAttempt::ALL_PURPOSES as $purpose) {
            $otp = new OtpAttempt(
                verificationId: 'mc-' . bin2hex(random_bytes(8)),
                phone: '+971501234567',
                purpose: $purpose,
                expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            );
            self::assertSame($purpose, $otp->getPurpose());
        }
    }

    #[Test]
    public function otpStartsUsableWhenFresh(): void
    {
        $otp = $this->makeOtp();
        self::assertTrue($otp->isUsable());
        self::assertFalse($otp->isExpired());
        self::assertFalse($otp->isConsumed());
    }

    #[Test]
    public function otpMarkConsumedIsIdempotent(): void
    {
        $otp = $this->makeOtp();
        $otp->markConsumed();
        $firstConsumed = $otp->getConsumedAt();
        self::assertNotNull($firstConsumed);
        self::assertTrue($otp->isConsumed());
        self::assertFalse($otp->isUsable());

        // Re-consuming should not change the timestamp.
        $otp->markConsumed();
        self::assertSame($firstConsumed, $otp->getConsumedAt());
    }

    #[Test]
    public function otpExpiresAfterTtl(): void
    {
        $otp = new OtpAttempt(
            verificationId: 'mc-expired',
            phone: '+971500000000',
            purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
            expiresAt: (new DateTimeImmutable())->modify('-1 second'),
        );

        self::assertTrue($otp->isExpired());
        self::assertFalse($otp->isUsable());
    }

    #[Test]
    public function otpBindUserSetsAssociation(): void
    {
        $otp = $this->makeOtp();
        self::assertNull($otp->getUser());

        $user = $this->makeUser();
        $otp->bindUser($user);
        self::assertSame($user, $otp->getUser());
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
    // UserLocation
    // -------------------------------------------------------------------

    #[Test]
    public function userLocationConstructorDefaultsAreSafe(): void
    {
        $user = $this->makeUser();
        $loc = new UserLocation($user);

        // Everything optional defaults to null/false. permission_granted
        // is the only non-null default, and it starts false — the client
        // must explicitly grant.
        self::assertSame($user, $loc->getUser());
        self::assertNull($loc->getLatitude());
        self::assertNull($loc->getLongitude());
        self::assertNull($loc->getCity());
        self::assertNull($loc->getCountryCode());
        self::assertFalse($loc->isPermissionGranted());
        self::assertNull($loc->getLastCapturedAt());
    }

    #[Test]
    public function userLocationUpdateSetsCoordinatesAndBumpsCaptureTimestamp(): void
    {
        $user = $this->makeUser();
        $loc = new UserLocation($user);

        $loc->update(latitude: 25.276987, longitude: 55.296249);

        // DECIMAL columns come back as strings via Doctrine. We
        // format-on-write to ensure consistent 6-decimal precision.
        self::assertSame('25.276987', $loc->getLatitude());
        self::assertSame('55.296249', $loc->getLongitude());
        // lastCapturedAt should be set when fresh coordinates arrive.
        self::assertNotNull($loc->getLastCapturedAt());
    }

    #[Test]
    public function userLocationUpdateUppercasesCountryCode(): void
    {
        $user = $this->makeUser();
        $loc = new UserLocation($user);

        $loc->update(countryCode: 'ae');

        // ISO 3166-1 alpha-2 should always be uppercase per convention.
        self::assertSame('AE', $loc->getCountryCode());
    }

    #[Test]
    public function userLocationUpdateTrimsAndAcceptsCity(): void
    {
        $user = $this->makeUser();
        $loc = new UserLocation($user);

        $loc->update(city: '  Dubai  ');

        self::assertSame('Dubai', $loc->getCity());
    }

    #[Test]
    public function userLocationUpdateWithEmptyStringClearsFields(): void
    {
        $user = $this->makeUser();
        $loc = new UserLocation($user);
        $loc->update(city: 'Dubai', countryCode: 'AE');

        // Empty string clears (convention used elsewhere in entities).
        $loc->update(city: '', countryCode: '');

        self::assertNull($loc->getCity());
        self::assertNull($loc->getCountryCode());
    }

    #[Test]
    public function userLocationUpdateWithNullLeavesFieldUnchanged(): void
    {
        $user = $this->makeUser();
        $loc = new UserLocation($user);
        $loc->update(city: 'Dubai', countryCode: 'AE', permissionGranted: true);

        // Passing null for unrelated fields shouldn't touch them.
        $loc->update(city: null, countryCode: null);

        self::assertSame('Dubai', $loc->getCity());
        self::assertSame('AE', $loc->getCountryCode());
        self::assertTrue($loc->isPermissionGranted());
    }

    #[Test]
    public function userLocationUpdateAcceptsPermissionDenial(): void
    {
        $user = $this->makeUser();
        $loc = new UserLocation($user);

        // User denies the OS permission. Important to record so the
        // app doesn't re-prompt. No coordinates expected.
        $loc->update(permissionGranted: false);

        self::assertFalse($loc->isPermissionGranted());
        self::assertNull($loc->getLatitude());
        self::assertNull($loc->getLongitude());
        // lastCapturedAt stays null — no coordinates were captured.
        self::assertNull($loc->getLastCapturedAt());
    }

    #[Test]
    public function userLocationUpdateIgnoresPartialCoordinates(): void
    {
        $user = $this->makeUser();
        $loc = new UserLocation($user);

        // Pass only latitude — should be ignored (paired field).
        // The controller should reject this at validation, but the
        // entity defensively treats partial coords as "neither".
        $loc->update(latitude: 25.0);

        self::assertNull($loc->getLatitude());
        self::assertNull($loc->getLongitude());
        self::assertNull($loc->getLastCapturedAt());
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeUser(string $email = 'user@example.com'): User
    {
        return new User($email, '+971501234567', 'fake-bcrypt-hash', 'AE');
    }

    private function makeOtp(string $purpose = OtpAttempt::PURPOSE_REGISTRATION): OtpAttempt
    {
        return new OtpAttempt(
            verificationId: 'mc-' . bin2hex(random_bytes(8)),
            phone: '+971501234567',
            purpose: $purpose,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
        );
    }
}
