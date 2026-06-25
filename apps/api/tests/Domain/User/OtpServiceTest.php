<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\User;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpAttemptRepository;
use Bayti\Api\Domain\User\OtpRateLimitConfig;
use Bayti\Api\Domain\User\OtpRateLimitException;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Infrastructure\Cache\InMemoryKeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStore;
use Bayti\Api\Infrastructure\Cache\KeyValueStoreException;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OtpService::class)]
#[CoversClass(VerifyResult::class)]
#[CoversClass(OtpRateLimitException::class)]
#[CoversClass(OtpRateLimitConfig::class)]
#[CoversClass(InMemoryKeyValueStore::class)]
final class OtpServiceTest extends TestCase
{
    private InMemoryOtpProvider $provider;
    /** @var OtpAttemptRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $repo;
    /** @var \Doctrine\ORM\EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $em;
    private InMemoryKeyValueStore $cache;

    /** Keep track of "persisted" attempts so tests can simulate the DB. */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->provider = new InMemoryOtpProvider();
        $this->repo = $this->createMock(OtpAttemptRepository::class);

        $this->repo->method('save')->willReturnCallback(
            function (OtpAttempt $a): void {
                $this->persisted[$a->getVerificationId()] = $a;
            }
        );

        $this->repo->method('findByVerificationId')->willReturnCallback(
            fn (string $vid): ?OtpAttempt => $this->persisted[$vid] ?? null
        );

        $this->repo->method('countRecentSendsForPhone')->willReturnCallback(
            function (string $phone, int $secs): int {
                $cutoff = (new DateTimeImmutable())->modify("-{$secs} seconds");
                return count(array_filter(
                    $this->persisted,
                    fn (OtpAttempt $a) => $a->getPhone() === $phone && $a->getCreatedAt() > $cutoff,
                ));
            }
        );

        // Channel-aware destination count (DB fail-closed fallback).
        $this->repo->method('countRecentSendsForDestination')->willReturnCallback(
            function (string $dest, string $channel, int $secs): int {
                $cutoff = (new DateTimeImmutable())->modify("-{$secs} seconds");
                return count(array_filter(
                    $this->persisted,
                    function (OtpAttempt $a) use ($dest, $channel, $cutoff): bool {
                        if ($a->getChannel() !== $channel || $a->getCreatedAt() <= $cutoff) {
                            return false;
                        }
                        return $channel === OtpAttempt::CHANNEL_EMAIL
                            ? $a->getEmail() === $dest
                            : $a->getPhone() === $dest;
                    },
                ));
            }
        );

        // Channel-aware "latest usable" lookup (cooldown dedup).
        $this->repo->method('findLatestUsableForDestination')->willReturnCallback(
            function (string $dest, string $purpose, string $channel): ?OtpAttempt {
                $matches = array_filter(
                    $this->persisted,
                    function (OtpAttempt $a) use ($dest, $purpose, $channel): bool {
                        if ($a->getPurpose() !== $purpose || $a->getChannel() !== $channel) {
                            return false;
                        }
                        if (!$a->isUsable()) {
                            return false;
                        }
                        return $channel === OtpAttempt::CHANNEL_EMAIL
                            ? $a->getEmail() === $dest
                            : $a->getPhone() === $dest;
                    },
                );
                if ($matches === []) {
                    return null;
                }
                usort($matches, fn (OtpAttempt $a, OtpAttempt $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
                return $matches[0];
            }
        );

        $this->em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->em->method('getRepository')->with(OtpAttempt::class)->willReturn($this->repo);

        $this->cache = new InMemoryKeyValueStore();
    }

    /**
     * Build an OtpService. Defaults to a config that DISABLES the resend
     * cooldown so each send mints a fresh verification_id — the dedup
     * behaviour gets its own dedicated tests. Per-test overrides flow
     * through the $config param.
     */
    private function makeService(?OtpRateLimitConfig $config = null): OtpService
    {
        return new OtpService(
            $this->provider,
            $this->em,
            $this->cache,
            $this->makeEmailProvider(),
            $config ?? $this->noCooldownConfig(),
        );
    }

    /** Cooldown disabled; generous caps — the everyday test baseline. */
    private function noCooldownConfig(): OtpRateLimitConfig
    {
        return new OtpRateLimitConfig(
            resendCooldownSeconds: 0,
            sendsPerHour: 5,
            sendsPerDay: 10,
            sendsPerIpHour: 20,
            sendsPerIpDay: 40,
            maxVerifyAttempts: 5,
        );
    }

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
    public function sendEnforcesHourlyCap(): void
    {
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 0, sendsPerHour: 3, sendsPerDay: 100);
        $service = $this->makeService($config);

        // Three sends within the hour — all succeed.
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // Fourth — over the cap.
        $this->expectException(OtpRateLimitException::class);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
    }

    #[Test]
    public function rateLimitExceptionCarriesRetryAfter(): void
    {
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 0, sendsPerHour: 1, sendsPerDay: 100);
        $service = $this->makeService($config);

        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        try {
            $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
            self::fail('Expected OtpRateLimitException');
        } catch (OtpRateLimitException $e) {
            // hourly window → retry_after is the (full) window in seconds.
            self::assertGreaterThanOrEqual(1, $e->retryAfter);
            self::assertLessThanOrEqual(3600, $e->retryAfter);
        }
    }

    #[Test]
    public function sendEnforcesDailyCap(): void
    {
        // Hourly generous, daily tight — the daily cap should trip first.
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 0, sendsPerHour: 100, sendsPerDay: 2);
        $service = $this->makeService($config);

        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $this->expectException(OtpRateLimitException::class);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
    }

    #[Test]
    public function zeroCapDisablesThatCheck(): void
    {
        // Hourly disabled (0); daily disabled (0). No per-destination cap.
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 0, sendsPerHour: 0, sendsPerDay: 0);
        $service = $this->makeService($config);

        for ($i = 0; $i < 20; $i++) {
            $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        }
        self::assertCount(20, $this->persisted);
    }

    #[Test]
    public function sendDoesNotPersistOnProviderFailure(): void
    {
        $failingProvider = $this->createMock(OtpProvider::class);
        $failingProvider->method('send')->willThrowException(
            new \Bayti\Api\Infrastructure\Otp\OtpProviderException('network', 'simulated')
        );

        $service = new OtpService(
            $failingProvider,
            $this->em,
            $this->cache,
            $this->makeEmailProvider(),
            $this->noCooldownConfig(),
        );

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
    // Resend cooldown + code reuse (dedup)
    // -------------------------------------------------------------------

    #[Test]
    public function resendWithinCooldownReusesCodeAndDoesNotDispatch(): void
    {
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60, sendsPerHour: 5, sendsPerDay: 10);
        $service = $this->makeService($config);

        $first = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        $second = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // Same verification_id returned — true resend-dedup.
        self::assertSame($first, $second);
        // Only ONE provider send actually happened.
        self::assertCount(1, $this->provider->allIssued());
        self::assertCount(1, $this->persisted);
    }

    #[Test]
    public function resendWithinCooldownReusesViaDbWhenCacheEmpty(): void
    {
        // Stage a usable row directly (no cooldown marker in cache), so
        // the dedup must fall back to the row's created_at.
        $attempt = new OtpAttempt(
            verificationId: 'inmem-existing',
            phone: '+971501234567',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_SMS,
        );
        $this->persisted['inmem-existing'] = $attempt;

        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60);
        $service = $this->makeService($config);

        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        self::assertSame('inmem-existing', $vid, 'Should reuse the existing usable row via DB created_at.');
        self::assertCount(0, $this->provider->allIssued(), 'No fresh dispatch within cooldown.');
    }

    #[Test]
    public function cooldownDedupIsPerPurpose(): void
    {
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60, sendsPerHour: 5, sendsPerDay: 10);
        $service = $this->makeService($config);

        $reg = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        $reset = $service->send('+971501234567', OtpAttempt::PURPOSE_PASSWORD_RESET);

        // Different purpose → not deduped → distinct vids.
        self::assertNotSame($reg, $reset);
        self::assertCount(2, $this->provider->allIssued());
    }

    #[Test]
    public function atomicClaimStampsCooldownMarkerOnFirstSend(): void
    {
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60, sendsPerHour: 5, sendsPerDay: 10);
        $service = $this->makeService($config);

        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // The claim writes the cooldown marker via setIfAbsent BEFORE
        // dispatch — so the per-purpose key is present after one send.
        self::assertNotNull(
            $this->cache->get('otp:cooldown:' . OtpAttempt::PURPOSE_REGISTRATION . ':+971501234567'),
            'First send must atomically claim (stamp) the cooldown marker.',
        );
    }

    #[Test]
    public function lostClaimReusesLatestCommittedCodeWithoutDispatching(): void
    {
        // Simulate the race-loser path: a usable row is already committed
        // AND the cooldown marker is already owned by a concurrent send.
        // claimCooldown() returns false → reuse the committed code.
        $attempt = new OtpAttempt(
            verificationId: 'inmem-committed',
            phone: '+971501234567',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_SMS,
        );
        $this->persisted['inmem-committed'] = $attempt;

        // Pre-own the marker so the next claim loses. Stamp it FAR in the
        // future-ish (just-now) so dedupWithinCooldown lets us through to
        // the claim — actually dedup reads the same marker, so to exercise
        // the CLAIM path we delete any created_at advantage: use a fresh
        // service and pre-claim directly.
        $cooldownKey = 'otp:cooldown:' . OtpAttempt::PURPOSE_REGISTRATION . ':+971501234567';

        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60, sendsPerHour: 5, sendsPerDay: 10);
        $service = $this->makeService($config);

        // Manually own the claim with a timestamp OLDER than the cooldown
        // so dedupWithinCooldown (which reads the same marker) sees it as
        // "past cooldown" and lets the request reach the atomic claim,
        // which then loses because the key still exists.
        // NOTE: dedup uses seconds-since; an old timestamp => past cooldown.
        $this->cache->set($cooldownKey, (string) (time() - 120), 60);

        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        self::assertSame('inmem-committed', $vid, 'Lost claim must reuse the committed code.');
        self::assertCount(0, $this->provider->allIssued(), 'Lost claim must NOT dispatch.');
    }

    #[Test]
    public function lostClaimThrows429WhenNoCommittedCodeYet(): void
    {
        // Marker owned (stamped older than cooldown so dedup passes), but
        // NO committed usable row exists — the racing send hasn't flushed
        // yet. The loser must 429 rather than dispatch.
        $cooldownKey = 'otp:cooldown:' . OtpAttempt::PURPOSE_REGISTRATION . ':+971509999999';

        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60, sendsPerHour: 5, sendsPerDay: 10);
        $service = $this->makeService($config);

        $this->cache->set($cooldownKey, (string) (time() - 120), 60);

        $this->expectException(OtpRateLimitException::class);
        $service->send('+971509999999', OtpAttempt::PURPOSE_REGISTRATION);
    }

    #[Test]
    public function claimReleasedOnDispatchFailureSoRetryNotBlocked(): void
    {
        // Provider throws on dispatch → the claim we made must be RELEASED
        // so a legit retry isn't blocked by an orphaned cooldown marker.
        $failingProvider = $this->createMock(OtpProvider::class);
        $failingProvider->method('send')->willThrowException(
            new \Bayti\Api\Infrastructure\Otp\OtpProviderException('network', 'simulated')
        );

        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60, sendsPerHour: 5, sendsPerDay: 10);
        $service = new OtpService(
            $failingProvider,
            $this->em,
            $this->cache,
            $this->makeEmailProvider(),
            $config,
        );

        $cooldownKey = 'otp:cooldown:' . OtpAttempt::PURPOSE_REGISTRATION . ':+971501234567';

        try {
            $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
            self::fail('Expected provider failure to propagate.');
        } catch (\Bayti\Api\Infrastructure\Otp\OtpProviderException) {
            // expected
        }

        self::assertNull(
            $this->cache->get($cooldownKey),
            'A dispatch failure must release the cooldown claim so retries are not blocked.',
        );
    }

    #[Test]
    public function claimReleasedWhenCapTripsAfterClaim(): void
    {
        // Hourly cap of 1; cooldown ON. The 2nd distinct-purpose send to
        // the same dest claims the cooldown, then trips the per-dest cap
        // (shared across purposes) — the claim must be released.
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60, sendsPerHour: 1, sendsPerDay: 100);
        $service = $this->makeService($config);

        // First send (registration) consumes the single hourly slot.
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // Second send, DIFFERENT purpose → dedup doesn't reuse → reaches
        // the claim (new per-purpose key, claim succeeds) → cap trips.
        $resetKey = 'otp:cooldown:' . OtpAttempt::PURPOSE_PASSWORD_RESET . ':+971501234567';
        try {
            $service->send('+971501234567', OtpAttempt::PURPOSE_PASSWORD_RESET);
            self::fail('Expected the hourly cap to trip.');
        } catch (OtpRateLimitException) {
            // expected
        }

        self::assertNull(
            $this->cache->get($resetKey),
            'A cap tripping after the claim must release the claim.',
        );
    }

    #[Test]
    public function claimFallsBackToDispatchWhenCacheUnavailable(): void
    {
        // Redis down (setIfAbsent throws) must NOT hard-fail the send —
        // we fall back to dispatching (DB created_at still gates dedup).
        $brokenCache = $this->brokenCache();
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 60, sendsPerHour: 0, sendsPerDay: 0);
        $service = new OtpService(
            $this->provider,
            $this->em,
            $brokenCache,
            $this->makeEmailProvider(),
            $config,
        );

        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        self::assertNotEmpty($vid, 'Redis unavailability must not block sends.');
        self::assertCount(1, $this->provider->allIssued());
    }

    // -------------------------------------------------------------------
    // Per-IP caps
    // -------------------------------------------------------------------

    #[Test]
    public function perIpCapTripsAcrossDestinations(): void
    {
        // IP cap of 2; destination caps generous. Two different phones
        // from the same IP — the third send (any phone) trips the IP cap.
        $config = new OtpRateLimitConfig(
            resendCooldownSeconds: 0,
            sendsPerHour: 100,
            sendsPerDay: 100,
            sendsPerIpHour: 2,
            sendsPerIpDay: 100,
        );
        $service = $this->makeService($config);

        $service->send('+971501111111', OtpAttempt::PURPOSE_REGISTRATION, requestedIp: '203.0.113.7');
        $service->send('+971502222222', OtpAttempt::PURPOSE_REGISTRATION, requestedIp: '203.0.113.7');

        $this->expectException(OtpRateLimitException::class);
        $service->send('+971503333333', OtpAttempt::PURPOSE_REGISTRATION, requestedIp: '203.0.113.7');
    }

    #[Test]
    public function perIpCapSkippedWhenIpNull(): void
    {
        $config = new OtpRateLimitConfig(
            resendCooldownSeconds: 0,
            sendsPerHour: 100,
            sendsPerDay: 100,
            sendsPerIpHour: 1,
            sendsPerIpDay: 1,
        );
        $service = $this->makeService($config);

        // No IP supplied — the per-IP cap must be skipped entirely.
        $service->send('+971501111111', OtpAttempt::PURPOSE_REGISTRATION, requestedIp: null);
        $service->send('+971502222222', OtpAttempt::PURPOSE_REGISTRATION, requestedIp: null);
        $service->send('+971503333333', OtpAttempt::PURPOSE_REGISTRATION, requestedIp: null);

        self::assertCount(3, $this->persisted);
    }

    // -------------------------------------------------------------------
    // Fail-CLOSED on Redis error (DB fallback for destination cap)
    // -------------------------------------------------------------------

    #[Test]
    public function failsClosedViaDbWhenCacheThrows(): void
    {
        // Pre-load the DB with sends at the hourly cap for this phone.
        for ($i = 0; $i < 3; $i++) {
            $attempt = new OtpAttempt(
                verificationId: "inmem-pre-{$i}",
                phone: '+971501234567',
                purpose: OtpAttempt::PURPOSE_REGISTRATION,
                expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
                channel: OtpAttempt::CHANNEL_SMS,
            );
            $this->persisted["inmem-pre-{$i}"] = $attempt;
        }

        $brokenCache = $this->brokenCache();
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 0, sendsPerHour: 3, sendsPerDay: 100);
        $service = new OtpService(
            $this->provider,
            $this->em,
            $brokenCache,
            $this->makeEmailProvider(),
            $config,
        );

        // Redis down → DB COUNT shows 3 already (== cap) → refuse.
        $this->expectException(OtpRateLimitException::class);
        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
    }

    #[Test]
    public function failsClosedAllowsUnderCapWhenCacheThrows(): void
    {
        // One prior send in DB, cap of 3 → under cap → allowed.
        $attempt = new OtpAttempt(
            verificationId: 'inmem-pre',
            phone: '+971501234567',
            purpose: OtpAttempt::PURPOSE_REGISTRATION,
            expiresAt: (new DateTimeImmutable())->modify('+5 minutes'),
            channel: OtpAttempt::CHANNEL_SMS,
        );
        $this->persisted['inmem-pre'] = $attempt;

        $brokenCache = $this->brokenCache();
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 0, sendsPerHour: 3, sendsPerDay: 100);
        $service = new OtpService(
            $this->provider,
            $this->em,
            $brokenCache,
            $this->makeEmailProvider(),
            $config,
        );

        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        self::assertNotEmpty($vid);
    }

    // -------------------------------------------------------------------
    // verify()
    // -------------------------------------------------------------------

    #[Test]
    public function verifySuccessForCorrectCode(): void
    {
        $service = $this->makeService();
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $result = $service->verify($vid, '000000');

        self::assertSame(VerifyResult::Success, $result);
        self::assertTrue($this->persisted[$vid]->isConsumed());
    }

    #[Test]
    public function verifyWrongCodeIncrementsSmsAttempts(): void
    {
        $service = $this->makeService();
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        $result = $service->verify($vid, '111111');

        self::assertSame(VerifyResult::WrongCode, $result);
        self::assertFalse($this->persisted[$vid]->isConsumed());
        // SMS path now mirrors the email attempt-cap: a wrong guess
        // increments the row's local attempts counter.
        self::assertSame(1, $this->persisted[$vid]->getAttempts());
    }

    #[Test]
    public function smsRowBurnsAfterAttemptCap(): void
    {
        // maxVerifyAttempts default 5. Use a smaller cap to keep it tight.
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 0, maxVerifyAttempts: 3);
        $service = $this->makeService($config);
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

        // 3 wrong guesses fill the per-code Redis counter to the cap.
        $service->verify($vid, '111111');
        $service->verify($vid, '222222');
        $service->verify($vid, '333333');

        // 4th verify (even the CORRECT code) trips the per-code 429.
        $this->expectException(OtpRateLimitException::class);
        $service->verify($vid, '000000');
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

        self::assertSame(VerifyResult::Success, $service->verify($vid, '000000'));
        self::assertSame(VerifyResult::Consumed, $service->verify($vid, '000000'));
    }

    #[Test]
    public function verifyExpiredForExpiredRow(): void
    {
        $service = $this->makeService();
        $vid = $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);

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
        $service = new OtpService(
            $this->provider,
            $this->em,
            $this->cache,
            $emailProvider,
            $this->noCooldownConfig(),
        );

        $vid = $service->send(
            'user@example.com',
            OtpAttempt::PURPOSE_PASSWORD_RESET,
            OtpAttempt::CHANNEL_EMAIL,
        );

        self::assertStringStartsWith('em-', $vid);
        $row = $this->persisted[$vid];
        self::assertTrue($row->isEmailChannel());
        self::assertSame('user@example.com', $row->getEmail());
        self::assertNotNull($row->getCodeHash());
        self::assertCount(1, $mailer->sent());
    }

    #[Test]
    public function emailVerifyHappyPathConsumesRow(): void
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
        for ($i = 0; $i < OtpAttempt::MAX_EMAIL_ATTEMPTS; $i++) {
            $attempt->incrementAttempts();
        }
        $this->persisted['em-known'] = $attempt;

        // Disable the per-code Redis verify cap so this exercises the
        // ROW-level burn (attempts >= cap) rather than the Redis counter.
        $config = new OtpRateLimitConfig(resendCooldownSeconds: 0, maxVerifyAttempts: 0);
        $service = $this->makeService($config);

        // Even the CORRECT code is refused once the row cap is hit.
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
    // Redis-backed counters
    // -------------------------------------------------------------------

    #[Test]
    public function rateLimitCounterUsesCache(): void
    {
        $service = $this->makeService();

        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        self::assertSame('1', $this->cache->get('otp:rl:+971501234567'));

        $service->send('+971501234567', OtpAttempt::PURPOSE_REGISTRATION);
        self::assertSame('2', $this->cache->get('otp:rl:+971501234567'));
    }

    #[Test]
    public function rateLimitIsPerDestination(): void
    {
        $service = $this->makeService();

        for ($i = 0; $i < 3; $i++) {
            $service->send('+971501111111', OtpAttempt::PURPOSE_REGISTRATION);
            $service->send('+971502222222', OtpAttempt::PURPOSE_REGISTRATION);
        }

        self::assertSame('3', $this->cache->get('otp:rl:+971501111111'));
        self::assertSame('3', $this->cache->get('otp:rl:+971502222222'));
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function brokenCache(): KeyValueStore
    {
        return new class implements KeyValueStore {
            public function get(string $key): ?string
            {
                throw new KeyValueStoreException('simulated');
            }
            public function set(string $key, string $value, int $ttlSeconds = 0): void
            {
                throw new KeyValueStoreException('simulated');
            }
            public function incr(string $key): int
            {
                throw new KeyValueStoreException('simulated');
            }
            public function setIfAbsent(string $key, string $value, int $ttlSeconds): bool
            {
                throw new KeyValueStoreException('simulated');
            }
            public function expire(string $key, int $ttlSeconds): void {}
            public function delete(string $key): void {}
            public function ping(): bool { return false; }
        };
    }

    private function makeUser(): User
    {
        $user = new User('alice@example.com', '+971501234567', 'fake-hash', 'AE');
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, 1);
        return $user;
    }
}
