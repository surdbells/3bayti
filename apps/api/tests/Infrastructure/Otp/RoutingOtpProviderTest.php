<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Otp\OtpAlertConfig;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Bayti\Api\Infrastructure\Otp\OtpProviderHealthMonitor;
use Bayti\Api\Infrastructure\Otp\OtpProviderHealthObserver;
use Bayti\Api\Infrastructure\Otp\RoutingOtpProvider;
use Bayti\Api\Notification\InMemoryMailer;
use Bayti\Api\Tests\Support\Otp\FakeClockKeyValueStore;
use Bayti\Api\Tests\Support\Otp\RecordingOtpIncidentReporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RoutingOtpProvider::class)]
final class RoutingOtpProviderTest extends TestCase
{
    #[Test]
    public function sendUsesTheFirstEnabledProviderAndPrefixesTheId(): void
    {
        $mc = new FakeOtpProvider('mc-1');
        $cq = new FakeOtpProvider('cq-1');
        $router = $this->router(['messagecentral' => $mc, 'cequens' => $cq], ['messagecentral', 'cequens']);

        self::assertSame('messagecentral:mc-1', $router->send('+971500000001'));
        self::assertCount(1, $mc->sendCalls);
        self::assertCount(0, $cq->sendCalls, 'the second provider is not called once the first succeeds');
    }

    #[Test]
    public function sendFailsOverToTheNextProviderWhenOneErrors(): void
    {
        $mc = new FakeOtpProvider('mc-1', sendError: new OtpProviderException('upstream', 'Pricing not found'));
        $cq = new FakeOtpProvider('cq-1');
        $router = $this->router(['messagecentral' => $mc, 'cequens' => $cq], ['messagecentral', 'cequens']);

        self::assertSame('cequens:cq-1', $router->send('+971500000001'));
        self::assertCount(1, $mc->sendCalls);
        self::assertCount(1, $cq->sendCalls);
    }

    #[Test]
    public function sendRethrowsTheLastErrorWhenEveryProviderFails(): void
    {
        $mc = new FakeOtpProvider('mc-1', sendError: new OtpProviderException('upstream', 'mc down'));
        $cq = new FakeOtpProvider('cq-1', sendError: new OtpProviderException('network', 'cq down'));
        $router = $this->router(['messagecentral' => $mc, 'cequens' => $cq], ['messagecentral', 'cequens']);

        $this->expectException(OtpProviderException::class);
        $this->expectExceptionMessage('cq down');
        $router->send('+971500000001');
    }

    #[Test]
    public function verifyRoutesByTheIdPrefixToTheIssuingProvider(): void
    {
        $mc = new FakeOtpProvider('mc-1', verifyResult: false);
        $cq = new FakeOtpProvider('cq-1', verifyResult: true);
        $router = $this->router(['messagecentral' => $mc, 'cequens' => $cq], ['messagecentral', 'cequens']);

        self::assertTrue($router->verify('cequens:abc123', '654321'));
        self::assertSame([['abc123', '654321']], $cq->verifyCalls);
        self::assertCount(0, $mc->verifyCalls);
    }

    #[Test]
    public function verifyRoutesAnUnprefixedLegacyIdToTheDefaultProvider(): void
    {
        $mc = new FakeOtpProvider('mc-1', verifyResult: true);
        $cq = new FakeOtpProvider('cq-1');
        $router = $this->router(['messagecentral' => $mc, 'cequens' => $cq], ['messagecentral', 'cequens']);

        self::assertTrue($router->verify('9007199254', '112233'));
        self::assertSame([['9007199254', '112233']], $mc->verifyCalls);
        self::assertCount(0, $cq->verifyCalls);
    }

    #[Test]
    public function emptyConfigFallsBackToEveryWiredProviderInDefaultOrder(): void
    {
        $mc = new FakeOtpProvider('mc-1', sendError: new OtpProviderException('upstream', 'mc down'));
        $cq = new FakeOtpProvider('cq-1');
        $router = $this->router(['messagecentral' => $mc, 'cequens' => $cq], []); // no config

        self::assertSame('cequens:cq-1', $router->send('+971500000001'));
    }

    #[Test]
    public function sendSkipsAConfiguredButUnwiredProvider(): void
    {
        // Config lists cequens first, but only messagecentral is wired (no creds).
        $mc = new FakeOtpProvider('mc-1');
        $router = $this->router(['messagecentral' => $mc], ['cequens', 'messagecentral']);

        self::assertSame('messagecentral:mc-1', $router->send('+971500000001'));
    }

    // ------------------------------------------------------------------
    // Provider health (account-problem alerting)
    // ------------------------------------------------------------------

    #[Test]
    public function primaryAccountFailureWithBackupSuccessStillDeliversAndTheAlertSaysTheBackupHandledIt(): void
    {
        $mc = new FakeOtpProvider('mc-1', sendError: new OtpProviderException(
            'upstream',
            'HTTP 400: {"responseCode":508,"message":"insufficient credits","data":{"responseCode":"508","errorMessage":"insufficient credits"}}',
        ));
        $cq = new FakeOtpProvider('cq-1');
        $mailer = new InMemoryMailer();
        $reporter = new RecordingOtpIncidentReporter();
        $monitor = new OtpProviderHealthMonitor(
            // A shared-store double (the per-process InMemoryKeyValueStore is
            // treated as "not shared" and would fall back to marker files).
            store: new FakeClockKeyValueStore(static fn (): int => 1_760_000_000),
            mailer: $mailer,
            reporter: $reporter,
            config: new OtpAlertConfig(['owner@3bayti.test']),
            clock: static fn (): int => 1_760_000_000,
        );
        $router = new RoutingOtpProvider(
            ['messagecentral' => $mc, 'cequens' => $cq],
            static fn (): array => ['messagecentral', 'cequens'],
            healthObserver: $monitor,
        );

        self::assertSame('cequens:cq-1', $router->send('+971500000001'), 'OTP behaviour unchanged');

        self::assertCount(1, $reporter->reports);
        self::assertSame('messagecentral', $reporter->reports[0]['provider']);
        self::assertSame('insufficient_credit', $reporter->reports[0]['reason']);
        self::assertFalse($reporter->reports[0]['customersBlocked']);
        self::assertSame('cequens', $reporter->reports[0]['details']['backup_provider']);

        $sent = $mailer->sent();
        self::assertCount(1, $sent);
        self::assertStringContainsString('backup Cequens is sending codes', $sent[0]['subject']);
        self::assertStringContainsString('the backup provider Cequens sent the code instead', $sent[0]['text_body']);
        self::assertStringNotContainsString('+971500000001', $sent[0]['text_body']);
    }

    #[Test]
    public function aSuccessfulSendRecordsSuccessForTheDeliveringProviderOnly(): void
    {
        $observer = new RecordingHealthObserver();
        $router = $this->router(['messagecentral' => new FakeOtpProvider('mc-1'), 'cequens' => new FakeOtpProvider('cq-1')], ['messagecentral', 'cequens'], $observer);

        $router->send('+971500000001');

        self::assertSame([], $observer->failures);
        self::assertSame(['messagecentral'], $observer->successes);
    }

    #[Test]
    public function failoverRecordsTheFailureWithTheBackupThenTheBackupsSuccess(): void
    {
        $observer = new RecordingHealthObserver();
        $mc = new FakeOtpProvider('mc-1', sendError: new OtpProviderException('upstream', 'Pricing not found'));
        $router = $this->router(['messagecentral' => $mc, 'cequens' => new FakeOtpProvider('cq-1')], ['messagecentral', 'cequens'], $observer);

        $router->send('+971500000001');

        self::assertSame([['messagecentral', 'upstream', 'cequens']], $observer->failures);
        self::assertSame(['cequens'], $observer->successes);
    }

    #[Test]
    public function whenEveryProviderFailsEachFailureIsRecordedWithNoBackupAndTheLastErrorIsRethrown(): void
    {
        $observer = new RecordingHealthObserver();
        $mc = new FakeOtpProvider('mc-1', sendError: new OtpProviderException('upstream', 'mc down'));
        $cq = new FakeOtpProvider('cq-1', sendError: new OtpProviderException('network', 'cq down'));
        $router = $this->router(['messagecentral' => $mc, 'cequens' => $cq], ['messagecentral', 'cequens'], $observer);

        try {
            $router->send('+971500000001');
            self::fail('expected the last provider error');
        } catch (OtpProviderException $e) {
            self::assertSame('network: cq down', $e->getMessage());
        }

        self::assertSame([['messagecentral', 'upstream', null], ['cequens', 'network', null]], $observer->failures);
        self::assertSame([], $observer->successes);
    }

    #[Test]
    public function noProviderAtAllIsRecordedAsANoProviderFailure(): void
    {
        $observer = new RecordingHealthObserver();
        $router = $this->router([], [], $observer);

        try {
            $router->send('+971500000001');
            self::fail('expected no_provider');
        } catch (OtpProviderException $e) {
            self::assertSame('no_provider', $e->kind);
        }

        self::assertSame([[OtpProviderHealthObserver::NO_PROVIDER_KEY, 'no_provider', null]], $observer->failures);
    }

    #[Test]
    public function theObserverGetsTheDestinationDialCodeNeverThePhoneNumber(): void
    {
        $observer = new RecordingHealthObserver();
        $mc = new FakeOtpProvider('mc-1', sendError: new OtpProviderException('upstream', 'HTTP 400: {"responseCode":400,"message":"Pricing not found"}'));
        $router = $this->router(['messagecentral' => $mc, 'cequens' => new FakeOtpProvider('cq-1')], ['messagecentral', 'cequens'], $observer);

        $router->send('+97335175253'); // Bahrain
        $this->router(['messagecentral' => new FakeOtpProvider('mc-2')], ['messagecentral'], $observer)->send('0501234567'); // bare local

        self::assertSame(['973', '973', null], $observer->destinations, 'failure + success carry the dial code; unknown → null');
        self::assertStringNotContainsString('35175253', (string) json_encode($observer->destinations));
    }

    #[Test]
    public function aThrowingObserverNeverChangesTheOtpResult(): void
    {
        $observer = new RecordingHealthObserver(throw: true);
        $ok = $this->router(['messagecentral' => new FakeOtpProvider('mc-1')], ['messagecentral'], $observer);
        self::assertSame('messagecentral:mc-1', $ok->send('+971500000001'));

        $failing = $this->router(
            ['messagecentral' => new FakeOtpProvider('mc-1', sendError: new OtpProviderException('upstream', 'HTTP 400: insufficient credits'))],
            ['messagecentral'],
            $observer,
        );
        $this->expectException(OtpProviderException::class);
        $this->expectExceptionMessage('insufficient credits'); // the provider's error, not the observer's
        $failing->send('+971500000001');
    }

    /**
     * @param array<string, OtpProvider> $providers
     * @param list<string> $order
     */
    private function router(array $providers, array $order, ?OtpProviderHealthObserver $observer = null): RoutingOtpProvider
    {
        return new RoutingOtpProvider($providers, static fn (): array => $order, healthObserver: $observer);
    }
}

/**
 * OtpProviderHealthObserver double: records [provider, kind, handledBy] per
 * failure and the provider per success; can throw to prove the router guards it.
 */
final class RecordingHealthObserver implements OtpProviderHealthObserver
{
    /** @var list<array{0: string, 1: string, 2: string|null}> */
    public array $failures = [];
    /** @var list<string> */
    public array $successes = [];
    /** @var list<string|null> destination dial code passed with every call, in order */
    public array $destinations = [];

    public function __construct(private readonly bool $throw = false)
    {
    }

    public function recordFailure(string $provider, OtpProviderException $e, ?string $handledBy = null, ?string $destination = null): void
    {
        $this->failures[] = [$provider, $e->kind, $handledBy];
        $this->destinations[] = $destination;
        if ($this->throw) {
            throw new \RuntimeException('observer exploded');
        }
    }

    public function recordSuccess(string $provider, ?string $destination = null): void
    {
        $this->successes[] = $provider;
        $this->destinations[] = $destination;
        if ($this->throw) {
            throw new \RuntimeException('observer exploded');
        }
    }
}

/**
 * Minimal OtpProvider double: records calls, optionally throws on send, and
 * returns a fixed verify result.
 */
final class FakeOtpProvider implements OtpProvider
{
    /** @var list<string> */
    public array $sendCalls = [];
    /** @var list<array{0: string, 1: string}> */
    public array $verifyCalls = [];

    public function __construct(
        private readonly string $id = 'vid',
        private readonly ?OtpProviderException $sendError = null,
        private readonly bool $verifyResult = true,
    ) {
    }

    public function send(string $toPhone): string
    {
        $this->sendCalls[] = $toPhone;
        if ($this->sendError !== null) {
            throw $this->sendError;
        }
        return $this->id;
    }

    public function verify(string $verificationId, string $code): bool
    {
        $this->verifyCalls[] = [$verificationId, $code];
        return $this->verifyResult;
    }
}
