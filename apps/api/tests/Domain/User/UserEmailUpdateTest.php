<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The email-update domain logic behind the Apple private-relay fix:
 * detecting non-deliverable emails + the pending-email verification model.
 */
#[CoversClass(User::class)]
final class UserEmailUpdateTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function emails(): array
    {
        return [
            'apple private relay'     => ['abc123@privaterelay.appleid.com', true],
            'apple relay uppercased'  => ['ABC@PrivateRelay.AppleID.com', true],
            'social placeholder'      => ['apple_9f8a@social.3bayti.invalid', true],
            'any .invalid'            => ['someone@foo.invalid', true],
            'empty'                   => ['', true],
            'normal gmail'            => ['shopper@gmail.com', false],
            'normal domain'           => ['a@bayti.ae', false],
        ];
    }

    #[Test]
    #[DataProvider('emails')]
    public function detectsNonDeliverableEmails(string $email, bool $expected): void
    {
        self::assertSame($expected, User::isNonDeliverableEmail($email));
    }

    #[Test]
    public function needsEmailUpdateFollowsTheAccountEmail(): void
    {
        self::assertTrue($this->user('x@privaterelay.appleid.com')->needsEmailUpdate());
        self::assertTrue($this->user('apple_1@social.3bayti.invalid')->needsEmailUpdate());
        self::assertFalse($this->user('real@gmail.com')->needsEmailUpdate());
    }

    #[Test]
    public function setPendingEmailNormalisesAndClears(): void
    {
        $u = $this->user('x@privaterelay.appleid.com');
        $u->setPendingEmail('  New@Example.COM ');
        self::assertSame('new@example.com', $u->getPendingEmail());
        $u->setPendingEmail('');
        self::assertNull($u->getPendingEmail());
    }

    #[Test]
    public function promotePendingEmailSwitchesAndVerifies(): void
    {
        $u = $this->user('x@privaterelay.appleid.com');
        $u->setPendingEmail('real@example.com');
        self::assertFalse($u->isEmailVerified());
        self::assertTrue($u->needsEmailUpdate(), 'still on the relay email');

        $u->promotePendingEmail('Real@Example.com');

        self::assertSame('real@example.com', $u->getEmail());
        self::assertTrue($u->isEmailVerified(), 'the OTP proved deliverability');
        self::assertNull($u->getPendingEmail(), 'pending cleared');
        self::assertFalse($u->needsEmailUpdate(), 'now on a deliverable address');
    }

    private function user(string $email): User
    {
        return new User($email, '+971500000000', null, 'AE');
    }
}
