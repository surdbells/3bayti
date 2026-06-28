<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Auth;

use Bayti\Api\Domain\User\SocialIdentity;
use Bayti\Api\Infrastructure\Auth\FirebaseIdTokenVerifier;
use Bayti\Api\Infrastructure\Auth\SocialTokenVerificationException;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit coverage for the Firebase ID-token verifier.
 *
 * We mint our OWN RSA keypair, build a matching self-signed x509 cert,
 * and serve it from a stubbed cert endpoint (Guzzle MockHandler) under a
 * known kid. Tokens are signed with the private key; the verifier
 * validates them against the served cert. This lets us exercise the full
 * decode + claim-assertion path without touching Google.
 */
#[CoversClass(FirebaseIdTokenVerifier::class)]
#[CoversClass(SocialTokenVerificationException::class)]
final class FirebaseIdTokenVerifierTest extends TestCase
{
    private const PROJECT_ID = 'demo-3bayti';
    private const KID = 'test-kid-1';

    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;
    private string $certPem = '';

    /** @var array<string, mixed> */
    private array $sslConfig;

    protected function setUp(): void
    {
        $this->sslConfig = self::opensslConfig();

        // Generate an RSA keypair + self-signed cert for signing/verifying.
        $res = openssl_pkey_new($this->sslConfig);
        self::assertNotFalse($res, 'failed to generate RSA key');
        $this->privateKey = $res;

        $dn = ['commonName' => 'securetoken.test'];
        $csr = openssl_csr_new($dn, $this->privateKey, $this->sslConfig);
        self::assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $this->privateKey, 1, $this->sslConfig);
        self::assertNotFalse($cert);
        openssl_x509_export($cert, $this->certPem);
    }

    /**
     * Build an OpenSSL config array, resolving an openssl.cnf path on
     * platforms (Windows dev boxes) where PHP can't find a default one.
     * Skips the test if no usable config can be located.
     *
     * @return array<string, mixed>
     */
    public static function opensslConfig(): array
    {
        $base = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // If the platform's default works, use it as-is.
        if (@openssl_pkey_new($base) !== false) {
            return $base;
        }

        foreach (self::candidateOpensslConfigs() as $cnf) {
            if (is_file($cnf) && @openssl_pkey_new($base + ['config' => $cnf]) !== false) {
                return $base + ['config' => $cnf];
            }
        }

        self::markTestSkipped('No usable OpenSSL config available in this environment.');
    }

    /** @return list<string> */
    private static function candidateOpensslConfigs(): array
    {
        $candidates = [];
        $env = getenv('OPENSSL_CONF');
        if (is_string($env) && $env !== '') {
            $candidates[] = $env;
        }
        $candidates[] = 'C:\\Program Files\\Git\\usr\\ssl\\openssl.cnf';
        $candidates[] = 'C:\\Program Files\\Git\\mingw64\\ssl\\openssl.cnf';
        $candidates[] = '/usr/lib/ssl/openssl.cnf';
        $candidates[] = '/etc/ssl/openssl.cnf';
        return $candidates;
    }

    private function makeVerifier(): FirebaseIdTokenVerifier
    {
        // Serve our cert as the kid => PEM map Google would return.
        $mock = new MockHandler([
            new Response(
                200,
                ['Cache-Control' => 'public, max-age=3600'],
                (string) json_encode([self::KID => $this->certPem]),
            ),
            // A second response in case the cache misses again — not
            // expected, but keeps the handler from throwing "queue empty".
            new Response(
                200,
                ['Cache-Control' => 'public, max-age=3600'],
                (string) json_encode([self::KID => $this->certPem]),
            ),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);

        return new FirebaseIdTokenVerifier(
            cache: new ArrayAdapter(),
            httpClient: $client,
            projectId: self::PROJECT_ID,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeToken(array $overrides = [], ?int $exp = null): string
    {
        $now = time();
        $claims = array_merge([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT_ID,
            'aud' => self::PROJECT_ID,
            'sub' => 'firebase-uid-123',
            'iat' => $now - 10,
            'exp' => $exp ?? ($now + 3600),
            'email' => 'alice@example.com',
            'email_verified' => true,
            'name' => 'Alice Example',
            'firebase' => [
                'sign_in_provider' => 'google.com',
                'identities' => [
                    'google.com' => ['google-sub-999'],
                    'email' => ['alice@example.com'],
                ],
            ],
        ], $overrides);

        $exportedKey = '';
        openssl_pkey_export($this->privateKey, $exportedKey, null, $this->sslConfig);

        return JWT::encode($claims, $exportedKey, 'RS256', self::KID);
    }

    #[Test]
    public function verifiesAValidGoogleToken(): void
    {
        $verifier = $this->makeVerifier();

        $result = $verifier->verify($this->makeToken());

        self::assertSame(SocialIdentity::PROVIDER_GOOGLE, $result->provider);
        self::assertSame('google-sub-999', $result->providerUid);
        self::assertSame('alice@example.com', $result->email);
        self::assertTrue($result->emailVerified);
        self::assertSame('Alice Example', $result->name);
    }

    #[Test]
    public function mapsAppleProvider(): void
    {
        $verifier = $this->makeVerifier();

        $token = $this->makeToken([
            'firebase' => [
                'sign_in_provider' => 'apple.com',
                'identities' => [
                    'apple.com' => ['apple-sub-abc'],
                ],
            ],
        ]);

        $result = $verifier->verify($token);

        self::assertSame(SocialIdentity::PROVIDER_APPLE, $result->provider);
        self::assertSame('apple-sub-abc', $result->providerUid);
    }

    #[Test]
    public function fallsBackToSubWhenIdentitiesMissing(): void
    {
        $verifier = $this->makeVerifier();

        $token = $this->makeToken([
            'sub' => 'fallback-sub-777',
            'firebase' => ['sign_in_provider' => 'google.com'],
        ]);

        $result = $verifier->verify($token);

        self::assertSame('fallback-sub-777', $result->providerUid);
    }

    #[Test]
    public function rejectsExpiredToken(): void
    {
        $verifier = $this->makeVerifier();

        $this->expectException(SocialTokenVerificationException::class);
        $verifier->verify($this->makeToken([], time() - 60));
    }

    #[Test]
    public function rejectsWrongAudience(): void
    {
        $verifier = $this->makeVerifier();

        $this->expectException(SocialTokenVerificationException::class);
        $verifier->verify($this->makeToken(['aud' => 'some-other-project']));
    }

    #[Test]
    public function rejectsWrongIssuer(): void
    {
        $verifier = $this->makeVerifier();

        $this->expectException(SocialTokenVerificationException::class);
        $verifier->verify($this->makeToken([
            'iss' => 'https://securetoken.google.com/evil-project',
        ]));
    }

    #[Test]
    public function rejectsUnsupportedProvider(): void
    {
        $verifier = $this->makeVerifier();

        $this->expectException(SocialTokenVerificationException::class);
        $verifier->verify($this->makeToken([
            'firebase' => ['sign_in_provider' => 'password'],
        ]));
    }

    #[Test]
    public function rejectsWhenSignedByWrongKey(): void
    {
        // Build a token signed by a DIFFERENT key than the served cert.
        $otherRes = openssl_pkey_new($this->sslConfig);
        self::assertNotFalse($otherRes);
        $otherKey = '';
        openssl_pkey_export($otherRes, $otherKey, null, $this->sslConfig);

        $now = time();
        $forged = JWT::encode([
            'iss' => 'https://securetoken.google.com/' . self::PROJECT_ID,
            'aud' => self::PROJECT_ID,
            'sub' => 'firebase-uid-123',
            'iat' => $now - 10,
            'exp' => $now + 3600,
            'firebase' => ['sign_in_provider' => 'google.com'],
        ], $otherKey, 'RS256', self::KID);

        $verifier = $this->makeVerifier();

        $this->expectException(SocialTokenVerificationException::class);
        $verifier->verify($forged);
    }

    #[Test]
    public function rejectsWhenProjectIdNotConfigured(): void
    {
        $verifier = new FirebaseIdTokenVerifier(
            cache: new ArrayAdapter(),
            httpClient: new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
            projectId: '',
        );

        $this->expectException(SocialTokenVerificationException::class);
        $verifier->verify($this->makeToken());
    }
}
