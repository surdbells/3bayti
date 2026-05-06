<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\OtpRateLimitException;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpService::class)]
#[CoversClass(VerifyResult::class)]
#[CoversClass(OtpRateLimitException::class)]
final class OtpServiceTest extends TestCase
{
    private InMemoryOtpProvider $provider;
    /** @var OtpAttemptRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $repo;
    /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $em;

    /** Keep track of "persisted" attempts so tests can simulate the DB. */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->provider = new InMemoryOtpProvider();
        $this->repo = $this->createMock(OtpAttemptRepository::class);

        // save() captures the row into our in-memory store
        $this->repo->method('save')->willReturnCallback(
            function (OtpAttempt $a): void {
                $this->persisted[$a->getVerificationId()] = $a;
            }
        );

        // findByVerificationId returns from the in-memory store
        $this->repo->method('findByVerificationId')->willReturnCallback(
            fn (string $vid): ?OtpAttempt => $this->persisted[$vid] ?? null
        );

        // countRecentSendsForPhone tallies our in-memory store
        $this->repo->method('countRecentSendsForPhone')->willReturnCallback(
            function (string $phone, int $secs): int {
                $cutoff = (new DateTimeImmutable())->modify("-{$secs} seconds");
                return count(array_filter(
                    $this->persisted,
                    fn (OtpAttempt $a) => $a->getPhone() === $phone && $a->getCreatedAt() > $cutoff,
                ));
            }
        );

        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('getRepository')->with(OtpAttempt::class)->willReturn($this->repo);
    }

    // -------------------------------------------------------------------
    // send()
    // -------------------------------------------------------------------

    #[Test]
    public function sendIssuesAndPersists(): void
    {
        $service = new OtpService($this->provider, $this->em);

        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        self::assertNotEmpty($vid);
        self::assertArrayHasKey($vid, $this->persisted);

        $row = $this->persisted[$vid];
        self::assertSame('+971501234567', $row->getPhone());
        self::assertSame(OtpAttempt::PURPOSE_REGISTRATION, $row->getPurpose());
        self::assertFalse($row->isExpired());
        self::assertFalse($row->isConsumed());
    }

    #[Test]
    public function sendBindsToUserWhenProvided(): void
    {
        $user = $this->makeUser();
        $service = new OtpService($this->provider, $this->em);

        $vid = $service->send(
            '+971501234567',
            OtpAttempt::PURPOSE_PASSWORD_RESET,
            user: $user,
        );

        self::assertSame($user, $this->persisted[$vid]->getUser());
    }

    #[Test]
    public function sendRejectsUnknownPurpose(): void
    {
        $service = new OtpService($this->provider, $this->em);

        $this->expectException(\InvalidArgumentException::class);
        $service->send('+971501234567', 'invented_purpose');
    }

    #[Test]
    public function sendEnforcesRateLimit(): void
    {
        $service = new OtpService($this->provider, $this->em);

        // Three sends within the hour — all succeed.
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // Fourth — over the cap.
        $this->expectException(OtpRateLimitException::class);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
    }

    #[Test]
    public function sendDoesNotPersistOnProviderFailure(): void
    {
        $failingProvider = $this->createMock(OtpProvider::class);
        $failingProvider->method('send')->willThrowException(
            new \Bayti\Api\Infrastructure\Otp\OtpProviderException('network', 'simulated')
        );

        $service = new OtpService($failingProvider, $this->em);

        $threw = false;
        try {
            $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        } catch (\Bayti\Api\Infrastructure\Otp\OtpProviderException) {
            $threw = true;
        }

        self::assertTrue($threw);
        self::assertSame([], $this->persisted, 'Failed sends must not pollute the audit trail.');
    }

    // -------------------------------------------------------------------
    // verify()
    // -------------------------------------------------------------------

    #[Test]
    public function verifySuccessForCorrectCode(): void
    {
        $service = new OtpService($this->provider, $this->em);
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $result = $service->verify($vid, '000000'); // default in-memory accept

        self::assertSame(VerifyResult::Success, $result);
        self::assertTrue($this->persisted[$vid]->isConsumed());
    }

    #[Test]
    public function verifyWrongCodeForIncorrectCode(): void
    {
        $service = new OtpService($this->provider, $this->em);
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $result = $service->verify($vid, '111111');

        self::assertSame(VerifyResult::WrongCode, $result);
        self::assertFalse($this->persisted[$vid]->isConsumed());
    }

    #[Test]
    public function verifyNotFoundForUnknownVerificationId(): void
    {
        $service = new OtpService($this->provider, $this->em);

        $result = $service->verify('does-not-exist', '000000');
        self::assertSame(VerifyResult::NotFound, $result);
    }

    #[Test]
    public function verifyConsumedForReusedRow(): void
    {
        $service = new OtpService($this->provider, $this->em);
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // First verify — success.
        self::assertSame(VerifyResult::Success, $service->verify($vid, '000000'));

        // Second verify — same vid is already consumed.
        self::assertSame(VerifyResult::Consumed, $service->verify($vid, '000000'));
    }

    #[Test]
    public function verifyExpiredForExpiredRow(): void
    {
        $service = new OtpService($this->provider, $this->em);
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // Force the row's expiresAt into the past via reflection.
        $row = $this->persisted[$vid];
        $ref = new \ReflectionProperty(OtpAttempt::class, 'expiresAt');
        $ref->setAccessible(true);
        $ref->setValue($row, (new DateTimeImmutable())->modify('-1 second'));

        $result = $service->verify($vid, '000000');
        self::assertSame(VerifyResult::Expired, $result);
    }

    // -------------------------------------------------------------------
    // findAttempt()
    // -------------------------------------------------------------------

    #[Test]
    public function findAttemptReturnsRowWhenKnown(): void
    {
        $service = new OtpService($this->provider, $this->em);
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $row = $service->findAttempt($vid);
        self::assertNotNull($row);
        self::assertSame($vid, $row->getVerificationId());
    }

    #[Test]
    public function findAttemptReturnsNullForUnknown(): void
    {
        $service = new OtpService($this->provider, $this->em);
        self::assertNull($service->findAttempt('whatever'));
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeUser(): User
    {
        $user = new User('alice@example.com', '+971501234567', 'fake-hash', 'AE');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, 1);
        return $user;
    }
}
