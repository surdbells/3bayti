<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Middleware;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Middleware\VendorAuthMiddleware;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
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
    /**
     * Build middleware WITHOUT an EM. Preserves the legacy two-gate
     * behavior (role check only) used by existing tests that pre-date
     * the M3.2.X.6 lifecycle gate.
     */
    private function buildMiddleware(): VendorAuthMiddleware
    {
        /** @var ResponseFactoryInterface $factory */
        $factory = $this->app->getContainer()->get(ResponseFactoryInterface::class);
        return new VendorAuthMiddleware($factory, new NullLogger());
    }

    /**
     * Build middleware WITH an EM mock whose VendorRepository returns
     * a fixed bool from existsApprovedForOwnerUser. Used by the
     * M3.2.X.6-B lifecycle gate tests.
     */
    private function buildMiddlewareWithApprovalCheck(bool $hasApproved): VendorAuthMiddleware
    {
        /** @var ResponseFactoryInterface $factory */
        $factory = $this->app->getContainer()->get(ResponseFactoryInterface::class);

        $repo = new class($hasApproved) extends VendorRepository {
            public function __construct(private readonly bool $hasApproved) {}
            public function existsApprovedForOwnerUser(User $user): bool
            {
                return $this->hasApproved;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static function (string $class) use ($repo): ?object {
                if ($class === Vendor::class) {
                    return $repo;
                }
                return null;
            }
        );

        return new VendorAuthMiddleware($factory, new NullLogger(), $em);
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

    // -----------------------------------------------------------------
    // M3.2.X.6-B lifecycle gate tests
    // -----------------------------------------------------------------

    #[Test]
    public function returns403WithVendorNotApprovedWhenAllStoresPending(): void
    {
        // Vendor user with is_vendor=true but no approved stores
        // (all in pending status). Should be blocked by lifecycle gate.
        $user = $this->makeUser(id: 10);
        $user->setRoles(vendor: true);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/vendor/orders')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);

        $response = $this->buildMiddlewareWithApprovalCheck(hasApproved: false)
            ->process($request, $this->passingHandler());

        self::assertSame(403, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(
            ErrorCodes::VENDOR_NOT_APPROVED,
            $body['error']['code'] ?? null,
            'Lifecycle gate emits VENDOR_NOT_APPROVED, distinct from vendor_required role-check failure',
        );
    }

    #[Test]
    public function returns403WithVendorNotApprovedWhenAllStoresSuspended(): void
    {
        // Same as pending — suspended-only vendors should be blocked.
        // existsApprovedForOwnerUser returns false for any user with
        // zero approved stores, regardless of why.
        $user = $this->makeUser(id: 11);
        $user->setRoles(vendor: true);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/vendor/orders')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);

        $response = $this->buildMiddlewareWithApprovalCheck(hasApproved: false)
            ->process($request, $this->passingHandler());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(ErrorCodes::VENDOR_NOT_APPROVED, $body['error']['code'] ?? null);
    }

    #[Test]
    public function passesThroughWhenUserHasAtLeastOneApprovedStore(): void
    {
        // Critical M3.2.X.6 case: mixed approved + suspended stores.
        // existsApprovedForOwnerUser returns true; middleware lets
        // through. Per-controller logic filters to approved stores via
        // VendorRepository::findApprovedByOwnerUser.
        $user = $this->makeUser(id: 12);
        $user->setRoles(vendor: true);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/vendor/orders')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);

        $response = $this->buildMiddlewareWithApprovalCheck(hasApproved: true)
            ->process($request, $this->passingHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns403WhenVendorUserHasNoStoresAtAll(): void
    {
        // Edge case: User has is_vendor=true (somehow set by admin) but
        // owns zero Vendor entities. existsApprovedForOwnerUser returns
        // false. Middleware blocks them with VENDOR_NOT_APPROVED — they
        // need to either submit onboarding (sub-phase D) or contact
        // admin to fix the role flag.
        $user = $this->makeUser(id: 13);
        $user->setRoles(vendor: true);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/vendor/orders')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);

        $response = $this->buildMiddlewareWithApprovalCheck(hasApproved: false)
            ->process($request, $this->passingHandler());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(ErrorCodes::VENDOR_NOT_APPROVED, $body['error']['code'] ?? null);
    }

    #[Test]
    public function nonVendorRoleCheckPreemptsLifecycleGate(): void
    {
        // A plain customer (is_vendor=false) hits the vendor endpoint.
        // Even though the EM mock would say hasApproved=true (which
        // shouldn't happen for a non-vendor), the role check fires
        // FIRST and returns the vendor_required code — not
        // VENDOR_NOT_APPROVED. Order of operations matters for the
        // error message clarity.
        $user = $this->makeUser(id: 14);
        // No setRoles(vendor: true) — user is plain customer

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v3/vendor/orders')
            ->withAttribute(AuthMiddleware::ATTR_USER, $user);

        $response = $this->buildMiddlewareWithApprovalCheck(hasApproved: true)
            ->process($request, $this->passingHandler());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame(
            'vendor_required',
            $body['error']['code'] ?? null,
            'Role check fires before lifecycle gate; vendor_required code preserved',
        );
    }
}
