<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for User::preferredLocale (M3.2.X.7-A).
 *
 * Locks the validation semantics + supported-locale list for the
 * notification system's locale routing.
 */
#[CoversClass(User::class)]
final class UserPreferredLocaleTest extends TestCase
{
    #[Test]
    public function localeConstantsExposed(): void
    {
        self::assertSame('en', User::LOCALE_EN);
        self::assertSame('ar', User::LOCALE_AR);
        self::assertSame(['en', 'ar'], User::SUPPORTED_LOCALES);
    }

    #[Test]
    public function initialStateIsNull(): void
    {
        $user = $this->makeUser();
        self::assertNull(
            $user->getPreferredLocale(),
            'Null means no preference; falls back to English at send time',
        );
    }

    #[Test]
    public function setPreferredLocaleAcceptsEn(): void
    {
        $user = $this->makeUser();
        $user->setPreferredLocale('en');
        self::assertSame('en', $user->getPreferredLocale());
    }

    #[Test]
    public function setPreferredLocaleAcceptsAr(): void
    {
        $user = $this->makeUser();
        $user->setPreferredLocale('ar');
        self::assertSame('ar', $user->getPreferredLocale());
    }

    #[Test]
    public function setPreferredLocaleAcceptsNull(): void
    {
        $user = $this->makeUser();
        $user->setPreferredLocale('en');
        $user->setPreferredLocale(null);
        self::assertNull(
            $user->getPreferredLocale(),
            'Null resets to default; explicit unsetting is valid',
        );
    }

    #[Test]
    public function setPreferredLocaleRejectsInvalidValue(): void
    {
        $user = $this->makeUser();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid locale 'fr'");
        $user->setPreferredLocale('fr');
    }

    #[Test]
    public function setPreferredLocaleRejectsEmptyString(): void
    {
        // Empty string is not the same as null — caller should use
        // null to clear; explicit empty string is a bug.
        $user = $this->makeUser();
        $this->expectException(\InvalidArgumentException::class);
        $user->setPreferredLocale('');
    }

    #[Test]
    public function setPreferredLocaleRejectsRegionTaggedLocale(): void
    {
        // Q-LocaleValues = A locked: only 'en' / 'ar' (no region
        // variants). Reject 'en-AE' to surface accidental misuse.
        $user = $this->makeUser();
        $this->expectException(\InvalidArgumentException::class);
        $user->setPreferredLocale('en-AE');
    }

    private function makeUser(): User
    {
        return new User(
            'test@example.com',
            '+971501234567',
            password_hash('p', PASSWORD_BCRYPT),
            'AE',
        );
    }
}
