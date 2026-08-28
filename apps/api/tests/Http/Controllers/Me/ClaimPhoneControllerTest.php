<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Me;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Me\ClaimPhoneController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the phone→single-account resolution shared by the
 * account-link endpoints. This is the security-critical decision: the OTP
 * proves control of a NUMBER, so a link is only allowed when the number maps
 * to exactly one other active account. Shared legacy numbers (>1 owner) must
 * be refused, not guessed.
 */
#[CoversClass(ClaimPhoneController::class)]
final class ClaimPhoneControllerTest extends TestCase
{
    private function user(int $id): User
    {
        $u = new User("u{$id}@example.com", null, null, 'AE');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($u, $id);
        return $u;
    }

    /** Fake UserRepository whose findActiveOwnersByPhone returns a fixed list. */
    private function repo(array $owners): UserRepository
    {
        return new class ($owners) extends UserRepository {
            /** @param list<User> $owners */
            public function __construct(private readonly array $owners)
            {
                // Skip parent (EntityManager/metadata) — this is a stand-in.
            }

            public function findActiveOwnersByPhone(string $phone, int $limit = 5): array
            {
                return $this->owners;
            }
        };
    }

    #[Test]
    public function noOtherOwnerThrowsNoAccount(): void
    {
        try {
            ClaimPhoneController::resolveSingleTarget($this->repo([]), '+971500000000', $this->user(1));
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(ErrorCodes::PHONE_LINK_NO_ACCOUNT, $e->errorCode);
        }
    }

    #[Test]
    public function onlySelfOwnsThrowsNoAccount(): void
    {
        $current = $this->user(1);
        try {
            // The number resolves only to the caller → nothing to link into.
            ClaimPhoneController::resolveSingleTarget($this->repo([$current]), '+971500000000', $current);
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(ErrorCodes::PHONE_LINK_NO_ACCOUNT, $e->errorCode);
        }
    }

    #[Test]
    public function multipleOtherOwnersThrowAmbiguous(): void
    {
        try {
            ClaimPhoneController::resolveSingleTarget(
                $this->repo([$this->user(2), $this->user(3)]),
                '+971500000000',
                $this->user(1),
            );
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertSame(ErrorCodes::PHONE_LINK_AMBIGUOUS, $e->errorCode);
        }
    }

    #[Test]
    public function singleOtherOwnerIsReturned(): void
    {
        $target = $this->user(2);
        $resolved = ClaimPhoneController::resolveSingleTarget(
            $this->repo([$target]),
            '+971500000000',
            $this->user(1),
        );
        self::assertSame($target, $resolved);
    }

    #[Test]
    public function callerIsExcludedThenSingleTargetResolves(): void
    {
        $current = $this->user(1);
        $target = $this->user(2);
        // Owners include the caller + one other → caller filtered → single target.
        $resolved = ClaimPhoneController::resolveSingleTarget(
            $this->repo([$current, $target]),
            '+971500000000',
            $current,
        );
        self::assertSame($target, $resolved);
    }
}
