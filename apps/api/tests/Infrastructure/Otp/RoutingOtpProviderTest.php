<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Otp;

use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Bayti\Api\Infrastructure\Otp\RoutingOtpProvider;
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

    /**
     * @param array<string, OtpProvider> $providers
     * @param list<string> $order
     */
    private function router(array $providers, array $order): RoutingOtpProvider
    {
        return new RoutingOtpProvider($providers, static fn (): array => $order);
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
