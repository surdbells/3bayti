<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Checkout;

use Bayti\Api\Http\Controllers\Checkout\CheckoutReturnRedirectController;
use Bayti\Api\Tests\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CheckoutReturnRedirectController::class)]
final class CheckoutReturnRedirectControllerTest extends HttpTestCase
{
    private ?string $previousWebAppUrl = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Capture and pin WEB_APP_URL so assertions are deterministic
        // regardless of the surrounding environment.
        $this->previousWebAppUrl = $_ENV['WEB_APP_URL'] ?? null;
        $_ENV['WEB_APP_URL'] = 'https://web.example.test';
    }

    protected function tearDown(): void
    {
        if ($this->previousWebAppUrl === null) {
            unset($_ENV['WEB_APP_URL']);
        } else {
            $_ENV['WEB_APP_URL'] = $this->previousWebAppUrl;
        }
        parent::tearDown();
    }

    #[Test]
    public function redirectsToWebAppPollingPageWithReference(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/return/V3-ORDER-001'),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            'https://web.example.test/checkout/return?ref=V3-ORDER-001',
            $response->getHeaderLine('Location'),
        );
    }

    #[Test]
    public function redirectIsNotCacheable(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/return/V3-ORDER-001'),
        );

        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('no-cache', $response->getHeaderLine('Pragma'));
    }

    #[Test]
    public function urlEncodesTheReferenceInTheRedirect(): void
    {
        // A reference containing characters that must be percent-encoded
        // in a query value. (Our real refs are V3-… alnum, but the
        // controller must not blindly concatenate.)
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/return/' . rawurlencode('V3 x&y')),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            'https://web.example.test/checkout/return?ref=V3%20x%26y',
            $response->getHeaderLine('Location'),
        );
    }

    #[Test]
    public function fallsBackToDefaultWebUrlWhenEnvUnset(): void
    {
        unset($_ENV['WEB_APP_URL']);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/return/V3-ORDER-001'),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            'https://3bayti.ae/checkout/return?ref=V3-ORDER-001',
            $response->getHeaderLine('Location'),
        );
    }

    #[Test]
    public function trimsTrailingSlashOnWebUrl(): void
    {
        $_ENV['WEB_APP_URL'] = 'https://web.example.test/';

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/return/V3-ORDER-001'),
        );

        self::assertSame(
            'https://web.example.test/checkout/return?ref=V3-ORDER-001',
            $response->getHeaderLine('Location'),
        );
    }

    #[Test]
    public function rejectsOverLongReferenceWith404(): void
    {
        $tooLong = str_repeat('A', 33);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/return/' . $tooLong),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function isReachableWithoutAuthentication(): void
    {
        // No Authorization header supplied, must still 302, proving
        // the route is mounted OUTSIDE the AuthMiddleware group.
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/checkout/return/V3-ORDER-001'),
        );

        self::assertSame(302, $response->getStatusCode());
    }
}
