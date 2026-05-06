<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Middleware;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\TokenClaims;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Required authentication middleware.
 *
 * Reads `Authorization: Bearer <token>`, verifies the token, looks up
 * the user, and attaches both to the request as attributes for
 * downstream handlers. On any failure, returns 401 Unauthorized.
 *
 * Wire into Slim per-route:
 *
 *   $app->get('/v3/account/profile', ProfileController::class)
 *       ->add(AuthMiddleware::class);
 *
 * Or for an entire route group:
 *
 *   $app->group('/v3/account', function ($group) { ... })
 *       ->add(AuthMiddleware::class);
 *
 * Inside controllers:
 *
 *   public function __invoke(ServerRequestInterface $req): ResponseInterface
 *   {
 *       $user = $req->getAttribute('user'); // type User
 *       $claims = $req->getAttribute('claims'); // type TokenClaims
 *       ...
 *   }
 *
 * Failure modes that produce 401
 * ------------------------------
 *   - No Authorization header
 *   - Malformed header (not 'Bearer <token>')
 *   - Token signature invalid
 *   - Token expired
 *   - Token wrong audience (refresh sent as access, etc.)
 *   - Token references a non-existent or soft-deleted user
 *   - User account is_active = false
 *   - Token's pwd_changed_at is older than the user's current value
 *     (i.e. the password was changed after the token was issued)
 *
 * Each path returns the SAME 401 response — no oracle leak about
 * which check failed.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const ATTR_USER = 'user';
    public const ATTR_CLAIMS = 'claims';

    public function __construct(
        private readonly JwtService $jwt,
        private readonly EntityManagerInterface $em,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractBearerToken($request);
        if ($token === null) {
            return $this->unauthorized('AUTH_MISSING_TOKEN');
        }

        $claims = $this->jwt->verifyAccessToken($token);
        if ($claims === null) {
            return $this->unauthorized('AUTH_INVALID_TOKEN');
        }

        $user = $this->loadAndValidateUser($claims);
        if ($user === null) {
            return $this->unauthorized('AUTH_INVALID_TOKEN');
        }

        // Decorate the request with user + claims and continue.
        $request = $request
            ->withAttribute(self::ATTR_USER, $user)
            ->withAttribute(self::ATTR_CLAIMS, $claims);

        return $handler->handle($request);
    }

    /**
     * Extract the bearer token from the Authorization header.
     * Returns null if header missing or malformed.
     *
     * Format: 'Bearer <token>'. Case-insensitive on 'Bearer' per
     * RFC 7235; we also tolerate leading/trailing spaces.
     */
    private function extractBearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header === '') {
            return null;
        }

        if (!preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $header, $matches)) {
            return null;
        }
        return $matches[1];
    }

    /**
     * Load the user referenced by the token's `sub` claim and
     * verify they're still allowed to authenticate.
     */
    private function loadAndValidateUser(TokenClaims $claims): ?User
    {
        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $users->findById($claims->userId);
        if ($user === null) {
            return null;
        }

        // Account-level disable check.
        if (!$user->isActive()) {
            return null;
        }

        // pwd_changed_at staleness check. If the token has a
        // pwd_changed_at claim and the user's current value is
        // newer, the token is stale — reject.
        $userPwdChanged = $user->getPasswordChangedAt();
        $tokenPwdChanged = $claims->passwordChangedAt;
        if ($userPwdChanged !== null && $tokenPwdChanged !== null) {
            if ($tokenPwdChanged < $userPwdChanged) {
                return null;
            }
        } elseif ($userPwdChanged !== null && $tokenPwdChanged === null) {
            // Edge case: user changed their password (so they have
            // a pwd_changed_at value) but the token was issued before
            // that change (so it has no claim). Reject — the token
            // pre-dates the password change.
            return null;
        }

        return $user;
    }

    private function unauthorized(string $code): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(401);
        $payload = json_encode([
            'error' => [
                'code' => $code,
                'message' => 'Authentication required.',
            ],
        ], JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($payload !== false ? $payload : '');
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('WWW-Authenticate', 'Bearer realm="3bayti API"');
    }
}
