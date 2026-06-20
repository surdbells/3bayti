<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\OtpRateLimitException;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
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
#[CoversClass(InMemoryKeyValueStore::class)]
final class OtpServiceTest extends TestCase
{
    private InMemoryOtpProvider $provider;
    /** @var OtpAttemptRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $repo;
    /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $em;
    private InMemoryKeyValueStore $cache;

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

        // M1.6.1.A: OtpService now takes a KeyValueStore for atomic
        // rate limiting. Tests use the in-memory implementation —
        // it gives the same atomic semantics within a single process,
        // which is what tests need.
        $this->cache = new InMemoryKeyValueStore();
    }

    /**
     * Build an OtpService with the test's standard collaborators.
     * Helper exists because every test needs the same construction
     * — keeping it in one place keeps the diff minimal when the
     * constructor signature evolves.
     */
    private function makeService(): OtpService
    {
        return new OtpService($this->provider, $this->em, $this->cache, $this->makeEmailProvider());
    }

    /**
     * Build a LocalEmailOtpProvider over the shared mock EM + an
     * in-memory mailer. The SMS-channel tests in this class never hit
     * the email path, but the constructor requires the dependency.
     */
    private function makeEmailProvider(): \Bayti\Api\Infrastructure\Otp\LocalEmailOtpProvider
    {
        return new \Bayti\Api\Infrastructure\Otp\LocalEmailOtpProvider(
            em: $this->em,
            mailer: new \Bayti\Api\Notification\InMemoryMailer(),
        );
    }

    // -------------------------------------------------------------------
    // send()
    // -------------------------------------------------------------------

    #[Test]
    public function sendIssuesAndPersists(): void
    {
        $service = $this->makeService();

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
        $service = $this->makeService();

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
        $service = $this->makeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->send('+971501234567', 'invented_purpose');
    }

    #[Test]
    public function sendEnforcesRateLimit(): void
    {
        $service = $this->makeService();

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

        $service = new OtpService($failingProvider, $this->em, $this->cache, $this->makeEmailProvider());

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
        $service = $this->makeService();
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $result = $service->verify($vid, '000000'); // default in-memory accept

        self::assertSame(VerifyResult::Success, $result);
        self::assertTrue($this->persisted[$vid]->isConsumed());
    }

    #[Test]
    public function verifyWrongCodeForIncorrectCode(): void
    {
        $service = $this->makeService();
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $result = $service->verify($vid, '111111');

        self::assertSame(VerifyResult::WrongCode, $result);
        self::assertFalse($this->persisted[$vid]->isConsumed());
    }

    #[Test]
    public function verifyNotFoundForUnknownVerificationId(): void
    {
        $service = $this->makeService();

        $result = $service->verify('does-not-exist', '000000');
        self::assertSame(VerifyResult::NotFound, $result);
    }

    #[Test]
    public function verifyConsumedForReusedRow(): void
    {
        $service = $this->makeService();
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // First verify — success.
        self::assertSame(VerifyResult::Success, $service->verify($vid, '000000'));

        // Second verify — same vid is already consumed.
        self::assertSame(VerifyResult::Consumed, $service->verify($vid, '000000'));
    }

    #[Test]
    public function verifyExpiredForExpiredRow(): void
    {
        $service = $this->makeService();
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
    // Email channel — local generation + verification
    // -------------------------------------------------------------------

    #[Test]
    public function emailSendPersistsHashedCodeAndEmails(): void
    {
        $mailer = new \Bayti\Api\Notification\InMemoryMailer();
        $emailProvider = new \Bayti\Api\Infrastructure\Otp\LocalEmailOtpProvider(
            em: $this->em,
            mailer: $mailer,
        );
        $service = new OtpService($this->provider, $this->em, $this->cache, $emailProvider);

        $vid = $service->send(
            'user@example.com',
            OtpAttempt::PURPOSE_PASSWORD_RESET,
            OtpAttempt::CHANNEL_EMAIL,
        );

        self::assertStringStartsWith('em-', $vid);
        $row = $this->persisted[$vid];
        self::assertTrue($row->isEmailChannel());
        self::assertSame('user@example.com', $row->getEmail());
        // The plaintext code is NEVER persisted — only a hash.
        self::assertNotNull($row->getCodeHash());
        // An email was actually dispatched.
        self::assertCount(1, $mailer->sent());
    }

    #[Test]
    public function emailVerifyHappyPathConsumesRow(): void
    {
        // Stage an email-channel row directly with a known code.
        $attempt = new OtpAttempt(
            verificationId: 'em-known',
            phone: '',
            purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_EMAIL,
            codeHash: password_hash('424242', PASSWORD_BCRYPT),
            email: 'user@example.com',
        );
        $this->persisted['em-known'] = $attempt;

        $service = $this->makeService();
        self::assertSame(VerifyResult::Success, $service->verify('em-known', '424242'));
        self::assertTrue($attempt->isConsumed());
    }

    #[Test]
    public function emailVerifyWrongCodeIncrementsAttempts(): void
    {
        $attempt = new OtpAttempt(
            verificationId: 'em-known',
            phone: '',
            purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_EMAIL,
            codeHash: password_hash('424242', PASSWORD_BCRYPT),
            email: 'user@example.com',
        );
        $this->persisted['em-known'] = $attempt;

        $service = $this->makeService();
        self::assertSame(VerifyResult::WrongCode, $service->verify('em-known', '111111'));
        self::assertSame(1, $attempt->getAttempts());
        self::assertFalse($attempt->isConsumed());
    }

    #[Test]
    public function emailVerifyBurnsRowAfterAttemptCap(): void
    {
        $attempt = new OtpAttempt(
            verificationId: 'em-known',
            phone: '',
            purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_EMAIL,
            codeHash: password_hash('424242', PASSWORD_BCRYPT),
            email: 'user@example.com',
        );
        // Pre-load to the cap.
        for ($i = 0; $i < OtpAttempt::MAX_EMAIL_ATTEMPTS; $i++) {
            $attempt->incrementAttempts();
        }
        $this->persisted['em-known'] = $attempt;

        $service = $this->makeService();
        // Even the CORRECT code is refused once the cap is hit.
        self::assertSame(VerifyResult::WrongCode, $service->verify('em-known', '424242'));
        self::assertFalse($attempt->isConsumed());
    }

    // -------------------------------------------------------------------
    // findAttempt()
    // -------------------------------------------------------------------

    #[Test]
    public function findAttemptReturnsRowWhenKnown(): void
    {
        $service = $this->makeService();
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $row = $service->findAttempt($vid);
        self::assertNotNull($row);
        self::assertSame($vid, $row->getVerificationId());
    }

    #[Test]
    public function findAttemptReturnsNullForUnknown(): void
    {
        $service = $this->makeService();
        self::assertNull($service->findAttempt('whatever'));
    }

    // -------------------------------------------------------------------
    // M1.6.1.A — Redis-backed rate limit
    // -------------------------------------------------------------------

    #[Test]
    public function rateLimitCounterUsesCache(): void
    {
        // Verifies the rate limit reads/writes Redis (via the
        // InMemoryKeyValueStore here), not the DB.
        $service = $this->makeService();

        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // After one send, the cache key should hold "1".
        self::assertSame('1', $this->cache->get('otp:rl:+971501234567'));

        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        self::assertSame('2', $this->cache->get('otp:rl:+971501234567'));
    }

    #[Test]
    public function rateLimitIsPerPhone(): void
    {
        // Two phones, three sends each — all succeed because the
        // counter is keyed by phone.
        $service = $this->makeService();

        for ($i = 0; $i < 3; $i++) {
            $service->send('+971501111111', OtpAttempt::PURPOSE_REGISTRATION);
            $service->send('+971502222222', OtpAttempt::PURPOSE_REGISTRATION);
        }

        // Each phone's counter is at 3 — at the cap but not over.
        self::assertSame('3', $this->cache->get('otp:rl:+971501111111'));
        self::assertSame('3', $this->cache->get('otp:rl:+971502222222'));
    }

    #[Test]
    public function failsOpenWhenCacheThrows(): void
    {
        // When Redis is down (the cache throws), OtpService should
        // log + proceed without rate limiting. Nothing user-visible
        // changes — the OTP send still succeeds.
        $brokenCache = new class implements KeyValueStore {
            public function get(string $key): ?string
            {
                throw new \Bayti\Api\Infrastructure\Cache\KeyValueStoreException('simulated');
            }
            public function set(string $key, string $value, int $ttlSeconds = 0): void
            {
                throw new \Bayti\Api\Infrastructure\Cache\KeyValueStoreException('simulated');
            }
            public function incr(string $key): int
            {
                throw new \Bayti\Api\Infrastructure\Cache\KeyValueStoreException('simulated');
            }
            public function expire(string $key, int $ttlSeconds): void {}
            public function delete(string $key): void {}
            public function ping(): bool { return false; }
        };

        $service = new OtpService($this->provider, $this->em, $brokenCache, $this->makeEmailProvider());

        // Send should succeed despite Redis throwing.
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        self::assertNotEmpty($vid);
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
