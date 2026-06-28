<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\SocialIdentity;
use Bayti\Api\Domain\User\SocialIdentityRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\SocialLoginController;
use Bayti\Api\Infrastructure\Auth\FirebaseIdTokenVerifier;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Bayti\Api\Tests\Infrastructure\Auth\FirebaseIdTokenVerifierTest;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Exercises the three find-or-create branches of POST /v3/auth/social
 * end-to-end through the real controller + a real
 * FirebaseIdTokenVerifier wired to a stubbed cert endpoint (so we sign
 * tokens locally and the verifier truly validates them).
 */
#[CoversClass(SocialLoginController::class)]
final class SocialLoginControllerTest extends HttpTestCase
{
    private const PROJECT_ID = 'demo-3bayti';
    private const KID = 'kid-social';

    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;
    private string $certPem = '';
    /** @var array<string, mixed> */
    private array $sslConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sslConfig = FirebaseIdTokenVerifierTest::opensslConfig();
        $this->privateKey = openssl_pkey_new($this->sslConfig);
        $csr = openssl_csr_new(['commonName' => 'securetoken.test'], $this->privateKey, $this->sslConfig);
        $cert = openssl_csr_sign($csr, null, $this->privateKey, 1, $this->sslConfig);
        openssl_x509_export($cert, $this->certPem);

        // Install a real verifier backed by our cert + key.
        $mock = new MockHandler([
            new Response(200, ['Cache-Control' => 'max-age=3600'], (string) json_encode([self::KID => $this->certPem])),
        ]);
        $verifier = new FirebaseIdTokenVerifier(
            cache: new ArrayAdapter(),
            httpClient: new Client(['handler' => HandlerStack::create($mock)]),
            projectId: self::PROJECT_ID,
        );
        $this->bind(FirebaseIdTokenVerifier::class, $verifier);
    }

    /**
     * UserSerializer::publicProfile() looks up the user's vendor(s) via
     * EntityManager::getRepository(Vendor). Provide an empty-result repo
     * mock so the success-path serialization doesn't trip on the stubbed
     * EM (these accounts are plain customers).
     */
    private function emptyVendorRepo(): \Doctrine\ORM\EntityRepository
    {
        $repo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repo->method('findBy')->willReturn([]);
        return $repo;
    }

    private function token(string $providerUid, string $email, bool $emailVerified, ?string $name = 'Sam Social'): string
    {
        $now = time();
        $key = '';
        openssl_pkey_export($this->privateKey, $key, null, $this->sslConfig);

        return JWT::encode([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT_ID,
            'aud' => self::PROJECT_ID,
            'sub' => 'firebase-' . $providerUid,
            'iat' => $now - 5,
            'exp' => $now + 3600,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => $name,
            'firebase' => [
                'sign_in_provider' => 'google.com',
                'identities' => ['google.com' => [$providerUid]],
            ],
        ], $key, 'RS256', self::KID);
    }

    #[Test]
    public function knownIdentityLogsInItsUser(): void
    {
        $user = $this->makeUser(id: 42, email: 'known@example.com', phoneVerified: true);
        $identity = new SocialIdentity($user, SocialIdentity::PROVIDER_GOOGLE, 'g-known', 'known@example.com');

        $socialRepo = $this->createMock(SocialIdentityRepository::class);
        $socialRepo->method('findByProviderUid')->with('google', 'g-known')->willReturn($identity);

        $userRepo = $this->createMock(UserRepository::class);
        $refreshRepo = $this->createMock(\Bayti\Api\Domain\User\RefreshTokenRepository::class);

        $em = $this->stubEm(fn ($em) => $em->method('getRepository')->willReturnMap([
            [SocialIdentity::class, $socialRepo],
            [User::class, $userRepo],
            [RefreshToken::class, $refreshRepo],
            [Vendor::class, $this->emptyVendorRepo()],
        ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/social', [
            'id_token' => $this->token('g-known', 'known@example.com', true),
        ]));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertSame('known@example.com', $body['user']['email']);
    }

    #[Test]
    public function autoLinksVerifiedEmailToExistingUser(): void
    {
        $user = $this->makeUser(id: 7, email: 'linkme@example.com', phoneVerified: true);

        $linked = [];
        $socialRepo = $this->createMock(SocialIdentityRepository::class);
        $socialRepo->method('findByProviderUid')->willReturn(null);
        $socialRepo->method('save')->willReturnCallback(
            function (SocialIdentity $i) use (&$linked) { $linked[] = $i; },
        );

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->with('linkme@example.com')->willReturn($user);

        $refreshRepo = $this->createMock(\Bayti\Api\Domain\User\RefreshTokenRepository::class);

        $em = $this->stubEm(fn ($em) => $em->method('getRepository')->willReturnMap([
            [SocialIdentity::class, $socialRepo],
            [User::class, $userRepo],
            [RefreshToken::class, $refreshRepo],
            [Vendor::class, $this->emptyVendorRepo()],
        ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/social', [
            'id_token' => $this->token('g-link', 'linkme@example.com', true),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $linked, 'a SocialIdentity should be created for the matched user');
        self::assertSame('linkme@example.com', $this->jsonBody($response)['user']['email']);
    }

    #[Test]
    public function unverifiedEmailDoesNotAutoLinkAndCreatesNewUser(): void
    {
        $created = [];
        $socialRepo = $this->createMock(SocialIdentityRepository::class);
        $socialRepo->method('findByProviderUid')->willReturn(null);
        $socialRepo->method('save');

        $userRepo = $this->createMock(UserRepository::class);
        // Even if a user with that email exists, an UNVERIFIED email must
        // NOT be used to auto-link — so findByEmail must never be the
        // basis for linking. We assert createUser path by capturing save().
        $userRepo->method('save')->willReturnCallback(
            function (User $u) use (&$created) {
                $created[] = $u;
                $ref = new \ReflectionProperty(User::class, 'id');
                $ref->setAccessible(true);
                $ref->setValue($u, 99);
            },
        );

        $refreshRepo = $this->createMock(\Bayti\Api\Domain\User\RefreshTokenRepository::class);

        $em = $this->stubEm(fn ($em) => $em->method('getRepository')->willReturnMap([
            [SocialIdentity::class, $socialRepo],
            [User::class, $userRepo],
            [RefreshToken::class, $refreshRepo],
            [Vendor::class, $this->emptyVendorRepo()],
        ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/social', [
            'id_token' => $this->token('g-new', 'unverified@example.com', false),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $created, 'a new social-only user should be created');
        self::assertFalse($created[0]->hasPassword(), 'social-only user has no password');
        self::assertTrue($created[0]->isEmailVerified());
        self::assertFalse($created[0]->isPhoneVerified());
    }
}
