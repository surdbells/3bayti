<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Notification;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Notification\UnsubscribeController;
use Bayti\Api\Infrastructure\Auth\JwtSettings;
use Bayti\Api\Notification\UnsubscribeTokenIssuer;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP-level tests for the M3.2.X.11-G unsubscribe endpoint.
 *
 * Public endpoint — no Authorization header, no JWT in the
 * usual access-token sense. The signed unsubscribe token in
 * the query string is the only auth.
 *
 * Returns HTML, not JSON. Content-Type: text/html; charset=utf-8.
 *
 * Test matrix covers the 6 outcomes from the controller's
 * decision tree:
 *   1. Missing/empty token → 400, generic error page
 *   2. Invalid token (verify fails) → 400, same generic page
 *   3. Valid token + user not found → 400, same generic page
 *       (no enumeration leak)
 *   4. Valid token + user already opted out → 200, success page
 *       (idempotent — no second flush)
 *   5. Valid token + user opts out for the first time → 200,
 *       opt_out flag set, em->flush called, success page
 *   6. Persistence failure → 500, transient error page
 */
#[CoversClass(UnsubscribeController::class)]
final class UnsubscribeControllerTest extends HttpTestCase
{
    private UnsubscribeTokenIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->issuer = new UnsubscribeTokenIssuer(JwtSettings::forTesting());
        // Bind the same issuer instance into the container so the
        // controller verifies tokens issued by THIS test's instance.
        $this->bind(UnsubscribeTokenIssuer::class, $this->issuer);
    }

    // =================================================================
    // Error paths
    // =================================================================

    #[Test]
    public function missingTokenReturns400HtmlError(): void
    {
        $this->bindUserRepo(returnedUser: null);

        $response = $this->get('/v3/notifications/unsubscribe');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString(
            'text/html',
            $response->getHeaderLine('Content-Type'),
        );
        $body = (string) $response->getBody();
        self::assertStringContainsString('invalid or has expired', $body);
    }

    #[Test]
    public function emptyTokenReturns400(): void
    {
        $this->bindUserRepo(returnedUser: null);

        $response = $this->get('/v3/notifications/unsubscribe?token=');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function malformedTokenReturns400(): void
    {
        $this->bindUserRepo(returnedUser: null);

        $response = $this->get('/v3/notifications/unsubscribe?token=not-a-jwt');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('invalid or has expired', (string) $response->getBody());
    }

    #[Test]
    public function expiredTokenReturns400(): void
    {
        $this->bindUserRepo(returnedUser: null);

        // Issue with a 31-day-ago timestamp (TTL is 30 days)
        $token = $this->issuer->issue(
            userId: 12345,
            now: new \DateTimeImmutable('-31 days'),
        );

        $response = $this->get('/v3/notifications/unsubscribe?token=' . urlencode($token));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function validTokenButUserMissingReturns400Opaque(): void
    {
        // No enumeration leak: same 400 page as invalid token.
        $token = $this->issuer->issue(userId: 99999);
        $this->bindUserRepo(returnedUser: null);

        $response = $this->get('/v3/notifications/unsubscribe?token=' . urlencode($token));

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('invalid or has expired', (string) $response->getBody());
    }

    // =================================================================
    // Happy paths
    // =================================================================

    #[Test]
    public function firstUnsubscribeSetsFlagAndFlushes(): void
    {
        $user = $this->makeOptedUser(optedOut: false);
        $em = $this->bindUserRepo(returnedUser: $user);
        $em->expects(self::once())->method('flush');

        $token = $this->issuer->issue(userId: 100);

        $response = $this->get('/v3/notifications/unsubscribe?token=' . urlencode($token));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('been unsubscribed', $body);
        self::assertStringContainsString('transactional emails', $body);

        // The flag IS set
        self::assertTrue($user->isMarketingEmailsOptedOut());
    }

    #[Test]
    public function alreadyOptedOutIdempotentlyShowsSuccess(): void
    {
        $user = $this->makeOptedUser(optedOut: true);
        $em = $this->bindUserRepo(returnedUser: $user);
        // No flush on the idempotent path
        $em->expects(self::never())->method('flush');

        $token = $this->issuer->issue(userId: 100);

        $response = $this->get('/v3/notifications/unsubscribe?token=' . urlencode($token));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('been unsubscribed', (string) $response->getBody());
    }

    // =================================================================
    // Persistence failure
    // =================================================================

    #[Test]
    public function persistenceFailureReturns500TransientError(): void
    {
        $user = $this->makeOptedUser(optedOut: false);
        $em = $this->bindUserRepo(returnedUser: $user);
        $em->method('flush')->willThrowException(new \RuntimeException('connection refused'));

        $token = $this->issuer->issue(userId: 100);

        $response = $this->get('/v3/notifications/unsubscribe?token=' . urlencode($token));

        self::assertSame(500, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Something went wrong', $body);
        // NOT the success page
        self::assertStringNotContainsString('been unsubscribed', $body);
    }

    // =================================================================
    // Wrong-action token rejection (security regression guard)
    // =================================================================

    #[Test]
    public function tokenWithWrongActionRejected(): void
    {
        // Manually craft a JWT with the same secret but action='login'.
        // The endpoint MUST reject it.
        $settings = JwtSettings::forTesting();
        $maliciousToken = \Firebase\JWT\JWT::encode(
            [
                'sub' => '100',
                'action' => 'login',  // wrong action
                'iat' => time(),
                'exp' => time() + 300,
            ],
            $settings->signingSecret,
            'HS256',
        );

        $this->bindUserRepo(returnedUser: null);

        $response = $this->get('/v3/notifications/unsubscribe?token=' . urlencode($maliciousToken));

        self::assertSame(400, $response->getStatusCode());
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeOptedUser(bool $optedOut): User
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(User::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($user, 100);
        $emailRef = new \ReflectionProperty(User::class, 'email');
        $emailRef->setAccessible(true);
        $emailRef->setValue($user, 'user@example.com');
        $optRef = new \ReflectionProperty(User::class, 'marketingEmailsOptOut');
        $optRef->setAccessible(true);
        $optRef->setValue($user, $optedOut);
        return $user;
    }

    private function bindUserRepo(?User $returnedUser): EntityManagerInterface
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($returnedUser);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        return $em;
    }

    private function get(string $uri): ResponseInterface
    {
        return $this->handle($this->jsonRequest('GET', $uri));
    }
}
