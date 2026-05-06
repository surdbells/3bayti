<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http;

use Bayti\Api\Bootstrap;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Base class for HTTP integration tests.
 *
 * Drives the full Slim app: builds it via Bootstrap::createApp(),
 * lets us swap the EntityManager for a mock, then makes real
 * request/response cycles. This is one step less synthetic than
 * unit-testing controllers in isolation — it exercises routes,
 * middleware, error handling, body parsing, JSON serialisation —
 * which is where most M1.4 bugs would actually live.
 *
 * What we DON'T exercise here:
 *   - The real PostgreSQL connection. Tests in CI have no Postgres
 *     yet (M1.5 wires it). EntityManager is mocked; repositories
 *     are stubbed to return canned data.
 *   - The real MessageCentral CPaaS. The DI container resolves to
 *     InMemoryOtpProvider in test env (APP_ENV=test).
 *
 * Subclasses typically override stubEm() to install whatever
 * repository fakes they need for that endpoint's flow.
 */
abstract class HttpTestCase extends TestCase
{
    protected App $app;

    /**
     * Build the full Slim app once per test. Each test starts with
     * a fresh DI container; if a test wants to swap a binding, it
     * does so on $this->app->getContainer() before invoking.
     */
    protected function setUp(): void
    {
        $this->app = Bootstrap::createApp();
    }

    /**
     * Replace a service in the live container. Used by tests to
     * install mock EntityManagers, fake providers, etc.
     */
    protected function bind(string $id, mixed $value): void
    {
        $container = $this->app->getContainer();
        if ($container === null) {
            self::fail('Container missing — Bootstrap is broken.');
        }
        $container->set($id, $value);
    }

    /**
     * Build a JSON request. The body is JSON-encoded into the
     * stream AND set as parsedBody — the real BodyParsingMiddleware
     * would do the latter from the stream, but since we're skipping
     * a real HTTP server, setting both keeps things consistent.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function jsonRequest(
        string $method,
        string $uri,
        array $body = [],
        array $headers = [],
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        $request = $request->withHeader('Content-Type', 'application/json');
        $request = $request->withHeader('Accept', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request->getBody()->write(json_encode($body, JSON_UNESCAPED_UNICODE));
        $request->getBody()->rewind();

        // Slim's body parser also sets parsedBody; do that here so
        // our RequestValidator sees the decoded array.
        $request = $request->withParsedBody($body);

        return $request;
    }

    /**
     * Run a request through the app and return the response. Sugar
     * around $this->app->handle($request) so tests stay readable.
     */
    protected function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->app->handle($request);
    }

    /**
     * Decode the response body as a JSON array (object shape).
     *
     * @return array<string, mixed>
     */
    protected function jsonBody(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            self::fail("Response body wasn't valid JSON: " . substr($raw, 0, 200));
        }
        return $decoded;
    }

    /**
     * Build a User instance with a forced id (no setter). Useful for
     * tests that need a "persisted" user without an actual database.
     */
    protected function makeUser(
        int $id = 1,
        string $email = 'alice@example.com',
        string $phone = '+971501234567',
        string $passwordPlain = 'p4ssword!',
        bool $active = true,
        bool $phoneVerified = true,
    ): User {
        $hash = password_hash($passwordPlain, PASSWORD_BCRYPT);
        $user = new User($email, $phone, $hash, 'AE');
        if (!$active) { $user->deactivate(); }
        if ($phoneVerified) { $user->markPhoneVerified(); }

        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, $id);

        return $user;
    }

    /**
     * Stub the EntityManager. Subclasses pass a callback that
     * configures the mock with the repositories they need.
     *
     * @param callable(\PHPUnit\Framework\MockObject\MockObject): void $configure
     */
    protected function stubEm(callable $configure): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // wrapInTransaction just runs the callback synchronously
        // for tests — no real transaction.
        $em->method('wrapInTransaction')->willReturnCallback(
            fn (callable $cb) => $cb($em)
        );
        $configure($em);
        return $em;
    }
}
