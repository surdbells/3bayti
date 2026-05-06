<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Middleware;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Middleware\OptionalAuthMiddleware;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\JwtSettings;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

#[CoversClass(AuthMiddleware::class)]
#[CoversClass(OptionalAuthMiddleware::class)]
final class AuthMiddlewareTest extends TestCase
{
    private JwtService $jwt;
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->jwt = new JwtService(JwtSettings::forTesting());
        $this->responseFactory = new ResponseFactory();
    }

    // -------------------------------------------------------------------
    // AuthMiddleware (required)
    // -------------------------------------------------------------------

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $middleware = new AuthMiddleware(
            $this->jwt,
            $this->stubEm(null),
            $this->responseFactory,
        );

        $response = $middleware->process(
            $this->makeRequest(),
            $this->failingHandler(), // should not reach handler
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('AUTH_MISSING_TOKEN', (string) $response->getBody());
    }

    #[Test]
    public function returns401WhenAuthHeaderMalformed(): void
    {
        $middleware = new AuthMiddleware(
            $this->jwt,
            $this->stubEm(null),
            $this->responseFactory,
        );

        $request = $this->makeRequest()->withHeader('Authorization', 'Basic abc123');
        $response = $middleware->process($request, $this->failingHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenTokenInvalid(): void
    {
        $middleware = new AuthMiddleware(
            $this->jwt,
            $this->stubEm(null),
            $this->responseFactory,
        );

        $request = $this->makeRequest()->withHeader('Authorization', 'Bearer not.a.valid.jwt');
        $response = $middleware->process($request, $this->failingHandler());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('AUTH_INVALID_TOKEN', (string) $response->getBody());
    }

    #[Test]
    public function returns401WhenUserNotFound(): void
    {
        $user = $this->makeUser();
        $pair = $this->jwt->issueTokenPair($user);

        // EM returns null — user was deleted between token issuance and now.
        $middleware = new AuthMiddleware(
            $this->jwt,
            $this->stubEm(null),
            $this->responseFactory,
        );

        $request = $this->makeRequest()->withHeader('Authorization', "Bearer {$pair->accessToken}");
        $response = $middleware->process($request, $this->failingHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenUserInactive(): void
    {
        $user = $this->makeUser();
        $user->deactivate();

        $pair = $this->jwt->issueTokenPair($user);

        $middleware = new AuthMiddleware(
            $this->jwt,
            $this->stubEm($user),
            $this->responseFactory,
        );

        $request = $this->makeRequest()->withHeader('Authorization', "Bearer {$pair->accessToken}");
        $response = $middleware->process($request, $this->failingHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenPasswordChangedAfterTokenIssued(): void
    {
        $user = $this->makeUser();
        $pair = $this->jwt->issueTokenPair($user);

        // Simulate the user changing their password AFTER the token was issued.
        // The token's pwd_changed_at will be null (or the old value); the user's
        // current pwd_changed_at is "now" — newer than the token.
        $user->setPasswordHash('rotated-hash');

        $middleware = new AuthMiddleware(
            $this->jwt,
            $this->stubEm($user),
            $this->responseFactory,
        );

        $request = $this->makeRequest()->withHeader('Authorization', "Bearer {$pair->accessToken}");
        $response = $middleware->process($request, $this->failingHandler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function passesThroughAndDecoratesRequestWhenTokenValid(): void
    {
        $user = $this->makeUser();
        $pair = $this->jwt->issueTokenPair($user);

        $capturedRequest = null;
        $handler = new class ($capturedRequest, $this->responseFactory) implements RequestHandlerInterface {
            public function __construct(
                private mixed &$captured,
                private readonly ResponseFactory $rf,
            ) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return $this->rf->createResponse(200);
            }
        };

        $middleware = new AuthMiddleware(
            $this->jwt,
            $this->stubEm($user),
            $this->responseFactory,
        );

        $request = $this->makeRequest()->withHeader('Authorization', "Bearer {$pair->accessToken}");
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($capturedRequest);
        self::assertSame($user, $capturedRequest->getAttribute(AuthMiddleware::ATTR_USER));
        self::assertNotNull($capturedRequest->getAttribute(AuthMiddleware::ATTR_CLAIMS));
    }

    // -------------------------------------------------------------------
    // OptionalAuthMiddleware
    // -------------------------------------------------------------------

    #[Test]
    public function optionalProceedsWithoutTokenWhenNoHeader(): void
    {
        $reachedHandler = false;
        $handler = $this->capturingHandler($reachedHandler);

        $middleware = new OptionalAuthMiddleware(
            $this->jwt,
            $this->stubEm(null),
        );

        $response = $middleware->process($this->makeRequest(), $handler);

        self::assertTrue($reachedHandler);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function optionalProceedsWithoutTokenWhenInvalid(): void
    {
        // Even a garbage token → silently drop; don't 401, just proceed anonymous.
        $reachedHandler = false;
        $handler = $this->capturingHandler($reachedHandler);

        $middleware = new OptionalAuthMiddleware(
            $this->jwt,
            $this->stubEm(null),
        );

        $request = $this->makeRequest()->withHeader('Authorization', 'Bearer garbage');
        $response = $middleware->process($request, $handler);

        self::assertTrue($reachedHandler);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function optionalDecoratesWhenValidToken(): void
    {
        $user = $this->makeUser();
        $pair = $this->jwt->issueTokenPair($user);

        $capturedRequest = null;
        $handler = new class ($capturedRequest, $this->responseFactory) implements RequestHandlerInterface {
            public function __construct(
                private mixed &$captured,
                private readonly ResponseFactory $rf,
            ) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                return $this->rf->createResponse(200);
            }
        };

        $middleware = new OptionalAuthMiddleware(
            $this->jwt,
            $this->stubEm($user),
        );

        $request = $this->makeRequest()->withHeader('Authorization', "Bearer {$pair->accessToken}");
        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($user, $capturedRequest->getAttribute(AuthMiddleware::ATTR_USER));
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

    private function makeRequest(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', '/v3/account/profile');
    }

    /**
     * EntityManager stub whose getRepository(User::class) returns a
     * UserRepository stub that finds the given user by id (or null).
     */
    private function stubEm(?User $userToFind): EntityManagerInterface
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findById')->willReturn($userToFind);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(User::class)->willReturn($repo);

        return $em;
    }

    private function failingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('handler should not be reached on auth failure');
            }
        };
    }

    private function capturingHandler(bool &$reached): RequestHandlerInterface
    {
        return new class ($reached, $this->responseFactory) implements RequestHandlerInterface {
            public function __construct(
                private bool &$reached,
                private readonly ResponseFactory $rf,
            ) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->reached = true;
                return $this->rf->createResponse(200);
            }
        };
    }
}
