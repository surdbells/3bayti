<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Otp\SentryOtpProviderIncidentReporter;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\Integration\RequestIntegration;
use Sentry\Severity;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;
use Sentry\UserDataBag;

/**
 * Drives the REAL Sentry SDK with an in-memory transport, so the event the
 * reporter builds (message, level, fingerprint, tags, context, no user) is
 * asserted exactly as it would be sent. The global hub is restored afterwards.
 */
#[CoversClass(SentryOtpProviderIncidentReporter::class)]
final class SentryOtpProviderIncidentReporterTest extends TestCase
{
    private HubInterface $previousHub;
    private CapturingTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousHub = SentrySdk::getCurrentHub();
        $this->transport = new CapturingTransport();
        $client = ClientBuilder::create([
            'dsn' => 'https://public@sentry.example.test/1',
            'default_integrations' => false,
        ])->setTransport($this->transport)->getClient();
        SentrySdk::setCurrentHub(new Hub($client));
    }

    protected function tearDown(): void
    {
        SentrySdk::setCurrentHub($this->previousHub);
        parent::tearDown();
    }

    #[Test]
    public function reportsOneGroupedFatalMessageEventWithoutTheUser(): void
    {
        // A signed-in request (AuthMiddleware sets the user on the scope).
        \Sentry\configureScope(static function (\Sentry\State\Scope $scope): void {
            $scope->setUser(UserDataBag::createFromUserIdentifier('42'));
        });

        (new SentryOtpProviderIncidentReporter())->reportAccountFailure(
            provider: 'messagecentral',
            reason: 'insufficient_credit',
            message: 'SMS provider messagecentral: out of credit — customers cannot receive login/verification codes',
            customersBlocked: true,
            details: ['provider_reply' => 'upstream: HTTP 400: {"responseCode":508}', 'backup_provider' => null],
        );

        self::assertCount(1, $this->transport->events);
        $event = $this->transport->events[0];
        self::assertSame('SMS provider messagecentral: out of credit — customers cannot receive login/verification codes', $event->getMessage());
        self::assertEquals(Severity::fatal(), $event->getLevel());
        self::assertSame(['otp-provider-account', 'messagecentral', 'insufficient_credit'], $event->getFingerprint());
        self::assertSame('messagecentral', $event->getTags()['provider'] ?? null);
        self::assertSame('insufficient_credit', $event->getTags()['reason'] ?? null);
        self::assertSame('yes', $event->getTags()['customers_blocked'] ?? null);
        self::assertArrayNotHasKey('backup_provider', $event->getTags());
        self::assertSame('upstream: HTTP 400: {"responseCode":508}', $event->getContexts()['otp_provider_incident']['provider_reply'] ?? null);
        self::assertNull($event->getUser(), 'provider incident, not a customer event');
    }

    #[Test]
    public function aBackupCoveredIncidentIsAnErrorTaggedWithTheBackup(): void
    {
        (new SentryOtpProviderIncidentReporter())->reportAccountFailure(
            provider: 'messagecentral',
            reason: 'pricing_not_configured',
            message: 'm',
            customersBlocked: false,
            details: ['backup_provider' => 'cequens'],
        );

        $event = $this->transport->events[0];
        self::assertEquals(Severity::error(), $event->getLevel());
        self::assertSame('no', $event->getTags()['customers_blocked'] ?? null);
        self::assertSame('cequens', $event->getTags()['backup_provider'] ?? null);
    }

    #[Test]
    public function aTestDrillIsGroupedSeparatelyAndMarked(): void
    {
        (new SentryOtpProviderIncidentReporter())->reportAccountFailure(
            provider: 'messagecentral',
            reason: 'insufficient_credit',
            message: 'm',
            customersBlocked: true,
            test: true,
        );

        $event = $this->transport->events[0];
        self::assertSame('[TEST] m', $event->getMessage());
        self::assertSame(['otp-provider-account', 'messagecentral', 'insufficient_credit', 'test'], $event->getFingerprint());
        self::assertSame('yes', $event->getTags()['otp_alert_test'] ?? null);
    }

    /**
     * Under PHP-FPM the SDK's default RequestIntegration attaches the request
     * (url, query string, BODY = the customer's phone on the OTP endpoints) to
     * EVERY event. The incident event must not carry it. CLI tests normally
     * have no request (RequestFetcher returns null under CLI), so a fake
     * fetcher supplies one; the control event proves the fixture works.
     */
    #[Test]
    public function theCustomersHttpRequestAndBreadcrumbsAreStrippedFromTheIncidentEvent(): void
    {
        $client = ClientBuilder::create([
            'dsn' => 'https://public@sentry.example.test/1',
            'default_integrations' => false,
            'integrations' => [new RequestIntegration(new FakeOtpRequestFetcher())],
        ])->setTransport($this->transport)->getClient();
        SentrySdk::setCurrentHub(new Hub($client));

        FakeOtpRequestFetcher::$request = (new ServerRequest(
            'POST',
            'https://api.3bayti.test/v3/auth/otp-login/send?phone=%2B971501234567',
            ['Content-Type' => 'application/json', 'Content-Length' => '25'],
        ))->withParsedBody(['phone' => '+971501234567']);
        \Sentry\addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'otp', 'sending to +971501234567'));

        try {
            \Sentry\captureMessage('control: an ordinary event in the same request');
            (new SentryOtpProviderIncidentReporter())->reportAccountFailure('messagecentral', 'insufficient_credit', 'm', true);
        } finally {
            FakeOtpRequestFetcher::$request = null;
        }

        self::assertCount(2, $this->transport->events);
        [$control, $incident] = $this->transport->events;
        self::assertSame(['phone' => '+971501234567'], $control->getRequest()['data'] ?? null, 'fixture check: the SDK does attach the body');
        self::assertNotEmpty($control->getBreadcrumbs(), 'fixture check: the breadcrumb is on the scope');

        self::assertSame([], $incident->getRequest(), 'no url / query string / body on the incident event');
        self::assertSame([], $incident->getBreadcrumbs());
        self::assertStringNotContainsString('501234567', (string) json_encode([
            $incident->getRequest(), $incident->getBreadcrumbs(), $incident->getContexts(), $incident->getTags(), $incident->getExtra(),
        ]));
    }

    #[Test]
    public function theDestinationIsTaggedAsADialCodeOnly(): void
    {
        (new SentryOtpProviderIncidentReporter())->reportAccountFailure(
            provider: 'messagecentral',
            reason: 'pricing_not_configured',
            message: 'm',
            customersBlocked: true,
            details: ['destination' => '973'],
        );
        (new SentryOtpProviderIncidentReporter())->reportAccountFailure(
            provider: 'messagecentral',
            reason: 'pricing_not_configured',
            message: 'm',
            customersBlocked: true,
            details: ['destination' => '+97335175253'], // never a number
        );

        self::assertSame('+973', $this->transport->events[0]->getTags()['destination'] ?? null);
        self::assertArrayNotHasKey('destination', $this->transport->events[1]->getTags());
    }

    #[Test]
    public function theOuterScopeIsLeftUntouched(): void
    {
        (new SentryOtpProviderIncidentReporter())->reportAccountFailure('messagecentral', 'insufficient_credit', 'm', true);
        \Sentry\captureMessage('unrelated');

        self::assertCount(2, $this->transport->events);
        self::assertSame([], $this->transport->events[1]->getFingerprint());
        self::assertArrayNotHasKey('provider', $this->transport->events[1]->getTags());
    }
}

/**
 * RequestFetcher double: hands RequestIntegration the request in a static
 * (static because the SDK sets an integration up once per process).
 */
final class FakeOtpRequestFetcher implements RequestFetcherInterface
{
    public static ?ServerRequestInterface $request = null;

    public function fetchRequest(): ?ServerRequestInterface
    {
        return self::$request;
    }
}

final class CapturingTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;
        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}
