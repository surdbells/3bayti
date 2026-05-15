<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Middleware;

use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Middleware\VendorAuthMiddleware;
use Bayti\Api\Tests\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(VendorAuthMiddleware::class)]
final class VendorAuthMiddlewareTest extends HttpTestCase
{
    private function buildMiddleware(): VendorAuthMiddleware
    {
        /** @var ResponseFactoryInterface $factory */
        $factory = $this->app->getContainer()->get(ResponseFactoryInterface::class);
        return new VendorAuthMiddleware($factory, new NullLogger());
    }

    private function passingHandler(): RequestHandlerInterface
    {
        return new class($this->app->getContainer()->get(ResponseFactoryInterface::class))
            implements RequestHandlerInterface
        {
            public function __construct(private readonly ResponseFactoryInterface $factory) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };
    }

    #[Test]
    public function returns401WhenUserAttributeMissing(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/v3/vendor/orders');
        $response = $this->buildMiddleware()->process($request, $this->passingHandler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertNotEmpty($response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function returns403WhenUserIsNotVendor(): void
    {
        $user = $this->makeUser(id: 42);
        // user is plain customer by default — is_vendor=false

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/vendor/orders')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);

        $response = $this->buildMiddleware()->process($request, $this->passingHandler());

        self::assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame('vendor_required', $body['error']['code'] ?? null);
    }

    #[Test]
    public function passesThroughWhenUserIsVendor(): void
    {
        $user = $this->makeUser(id: 7);
        $user->setRoles(vendor: true);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/vendor/orders')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);

        $response = $this->buildMiddleware()->process($request, $this->passingHandler());

        self::assertSame(200, $response->getStatusCode());
    }
}
