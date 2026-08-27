<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Middleware;

use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Middleware\PermissionGuard;
use Bayti\Api\Http\Middleware\PermissionMiddleware;
use Bayti\Api\Tests\Http\HttpTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(PermissionMiddleware::class)]
#[CoversClass(PermissionGuard::class)]
final class PermissionMiddlewareTest extends HttpTestCase
{
    private function guard(): PermissionGuard
    {
        /** @var ResponseFactoryInterface $factory */
        $factory = $this->app->getContainer()->get(ResponseFactoryInterface::class);
        return new PermissionGuard($factory, new NullLogger());
    }

    /** A staff user holding exactly the given permission keys (via one role). */
    private function staffUser(array $permissionKeys, int $id = 5): User
    {
        $user = $this->makeUser(id: $id);
        $role = new Role('test_role', 'Test Role');
        foreach ($permissionKeys as $key) {
            $role->addPermission(new Permission($key, explode('.', $key)[0], $key));
        }
        $user->addRole($role);
        return $user;
    }

    private function passingHandler(): RequestHandlerInterface
    {
        return new class($this->app->getContainer()->get(ResponseFactoryInterface::class))
            implements RequestHandlerInterface
        {
            public function __construct(private readonly ResponseFactoryInterface $factory) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->factory->createResponse(200);
            }
        };
    }

    private function requestFor(?User $user): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/v3/admin/orders/1/refund');
        if ($user !== null) {
            $request = $request->withAttribute(AuthMiddleware::ATTR_USER, $user);
        }
        return $request;
    }

    #[Test]
    public function returns401WhenUserAttributeMissing(): void
    {
        $response = $this->guard()->for('orders.refund')->process($this->requestFor(null), $this->passingHandler());

        self::assertSame(401, $response->getStatusCode());
        self::assertNotEmpty($response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function returns403WhenMissingPermission(): void
    {
        $user = $this->staffUser(['orders.view']); // has view, not refund
        $response = $this->guard()->for('orders.refund')->process($this->requestFor($user), $this->passingHandler());

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('insufficient_permissions', $body['error']['code'] ?? null);
        self::assertSame('orders.refund', $body['error']['required_permission'] ?? null);
    }

    #[Test]
    public function passesWhenUserHasPermission(): void
    {
        $user = $this->staffUser(['orders.view', 'orders.refund']);
        $response = $this->guard()->for('orders.refund')->process($this->requestFor($user), $this->passingHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function superAdminBypassesEveryPermission(): void
    {
        $user = $this->makeUser(id: 9);
        $user->setRoles(admin: true); // is_admin, no roles assigned at all

        $response = $this->guard()->for('orders.refund')->process($this->requestFor($user), $this->passingHandler());

        self::assertSame(200, $response->getStatusCode());
    }
}
