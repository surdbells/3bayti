<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http;

use Bayti\Api\Http\RequestContext;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Exercises RequestContext::extractIp — the Cloudflare-aware client-IP
 * resolution that the per-IP OTP rate limit keys on.
 */
#[CoversTrait(RequestContext::class)]
final class RequestContextIpTest extends TestCase
{
    /** Harness exposing the protected trait method. */
    private object $sut;

    protected function setUp(): void
    {
        $this->sut = new class {
            use RequestContext;

            public function ip(ServerRequestInterface $request): ?string
            {
                return $this->extractIp($request);
            }
        };
    }

    #[Test]
    public function prefersCloudflareConnectingIp(): void
    {
        $request = $this->makeRequest()
            ->withHeader('CF-Connecting-IP', '198.51.100.9')
            ->withHeader('X-Forwarded-For', '203.0.113.1, 10.0.0.1');

        self::assertSame('198.51.100.9', $this->sut->ip($request));
    }

    #[Test]
    public function fallsBackToXffLeftmostWhenNoCloudflareHeader(): void
    {
        $request = $this->makeRequest()
            ->withHeader('X-Forwarded-For', '203.0.113.1, 10.0.0.1');

        self::assertSame('203.0.113.1', $this->sut->ip($request));
    }

    #[Test]
    public function fallsBackToRemoteAddr(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest(
            'POST',
            '/v3/auth/send-otp',
            ['REMOTE_ADDR' => '192.0.2.44'],
        );

        self::assertSame('192.0.2.44', $this->sut->ip($request));
    }

    #[Test]
    public function ignoresGarbageCloudflareHeaderAndFallsThrough(): void
    {
        // A non-IP CF header must not be trusted; XFF (valid) wins.
        $request = $this->makeRequest()
            ->withHeader('CF-Connecting-IP', 'not-an-ip')
            ->withHeader('X-Forwarded-For', '203.0.113.7');

        self::assertSame('203.0.113.7', $this->sut->ip($request));
    }

    #[Test]
    public function ignoresGarbageXffEntry(): void
    {
        $request = $this->makeRequest()
            ->withHeader('X-Forwarded-For', 'garbage, 203.0.113.7');

        // Leftmost is garbage → not a valid IP → null (caller skips the
        // per-IP cap rather than keying on junk).
        self::assertNull($this->sut->ip($request));
    }

    #[Test]
    public function acceptsIpv6CloudflareHeader(): void
    {
        $request = $this->makeRequest()
            ->withHeader('CF-Connecting-IP', '2001:db8::1');

        self::assertSame('2001:db8::1', $this->sut->ip($request));
    }

    #[Test]
    public function returnsNullWhenNothingResolvable(): void
    {
        $request = $this->makeRequest();
        self::assertNull($this->sut->ip($request));
    }

    private function makeRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/v3/auth/send-otp');
    }
}
