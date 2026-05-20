<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Container;

use Bayti\Api\Notification\Push\FcmHttpV1Sender;
use Bayti\Api\Notification\Push\NullPushSender;
use Bayti\Api\Notification\Push\PushSenderInterface;
use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the M3.2.Z.4-C env-gated PushSenderInterface binding.
 *
 * The binding logic + loadFcmServiceAccount() helper live in
 * config/di.php; this test replicates them standalone (the same
 * approach as NoonWebhookVerifierBindingTest) so we can vary the FCM
 * env vars without loading the full di.php.
 *
 * If config/di.php's push factory or loadFcmServiceAccount() changes,
 * this test must be updated to match — a deliberate touch-point so
 * divergence is caught.
 *
 * @see config/di.php
 */
#[CoversNothing]
final class PushSenderBindingTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $envBackup = [];

    private const ENV_KEYS = [
        'APP_ENV', 'PUSH_PROVIDER',
        'FCM_SERVICE_ACCOUNT_JSON', 'FCM_SERVICE_ACCOUNT_FILE',
        'FCM_PROJECT_ID', 'FCM_CLIENT_EMAIL', 'FCM_PRIVATE_KEY',
    ];

    /** A throwaway RSA private key (PEM) so FcmHttpV1Sender constructs. */
    private string $pem = '';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::ENV_KEYS as $k) {
            $this->envBackup[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($res);
        openssl_pkey_export($res, $this->pem);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function noCredentialsReturnsNullSenderInDev(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        self::assertInstanceOf(NullPushSender::class, $this->resolve());
    }

    #[Test]
    public function prodWithoutCredentialsReturnsNullSender(): void
    {
        // prod but no creds → degrade to Null rather than crash (useFcm
        // is false because creds absent).
        $_ENV['APP_ENV'] = 'prod';
        self::assertInstanceOf(NullPushSender::class, $this->resolve());
    }

    #[Test]
    public function overrideWithoutCredentialsThrows(): void
    {
        $_ENV['PUSH_PROVIDER'] = 'fcm';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('FCM service-account credentials');
        $this->resolve();
    }

    #[Test]
    public function overrideWithIndividualFieldsReturnsFcm(): void
    {
        $_ENV['PUSH_PROVIDER'] = 'fcm';
        $_ENV['FCM_PROJECT_ID'] = 'demo-project';
        $_ENV['FCM_CLIENT_EMAIL'] = 'svc@demo.iam.gserviceaccount.com';
        $_ENV['FCM_PRIVATE_KEY'] = $this->pem;
        self::assertInstanceOf(FcmHttpV1Sender::class, $this->resolve());
    }

    #[Test]
    public function prodWithCredentialsReturnsFcm(): void
    {
        $_ENV['APP_ENV'] = 'prod';
        $_ENV['FCM_PROJECT_ID'] = 'demo-project';
        $_ENV['FCM_CLIENT_EMAIL'] = 'svc@demo.iam.gserviceaccount.com';
        $_ENV['FCM_PRIVATE_KEY'] = $this->pem;
        self::assertInstanceOf(FcmHttpV1Sender::class, $this->resolve());
    }

    #[Test]
    public function inlineJsonCredentialsReturnFcm(): void
    {
        $_ENV['PUSH_PROVIDER'] = 'fcm';
        $_ENV['FCM_SERVICE_ACCOUNT_JSON'] = json_encode([
            'project_id' => 'demo-project',
            'client_email' => 'svc@demo.iam.gserviceaccount.com',
            'private_key' => $this->pem,
        ]);
        self::assertInstanceOf(FcmHttpV1Sender::class, $this->resolve());
    }

    #[Test]
    public function fileCredentialsReturnFcm(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode([
            'project_id' => 'demo-project',
            'client_email' => 'svc@demo.iam.gserviceaccount.com',
            'private_key' => $this->pem,
        ]));
        $_ENV['PUSH_PROVIDER'] = 'fcm';
        $_ENV['FCM_SERVICE_ACCOUNT_FILE'] = $path;
        try {
            self::assertInstanceOf(FcmHttpV1Sender::class, $this->resolve());
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function escapedNewlinesInPrivateKeyAreNormalised(): void
    {
        // Simulate an env-stored key with literal \n escapes.
        $escaped = str_replace("\n", '\n', $this->pem);
        $_ENV['PUSH_PROVIDER'] = 'fcm';
        $_ENV['FCM_PROJECT_ID'] = 'demo-project';
        $_ENV['FCM_CLIENT_EMAIL'] = 'svc@demo.iam.gserviceaccount.com';
        $_ENV['FCM_PRIVATE_KEY'] = $escaped;
        // If normalisation didn't happen the PEM would be malformed, but
        // FcmHttpV1Sender only validates non-empty at construction, so we
        // assert the loader produced a real-newline PEM directly.
        [, , $key] = loadFcmServiceAccount();
        self::assertStringContainsString("\n", $key);
        self::assertStringNotContainsString('\n', $key);
        self::assertInstanceOf(FcmHttpV1Sender::class, $this->resolve());
    }

    #[Test]
    public function malformedJsonDegradesToNull(): void
    {
        $_ENV['APP_ENV'] = 'dev';
        $_ENV['FCM_SERVICE_ACCOUNT_JSON'] = '{not valid json';
        self::assertInstanceOf(NullPushSender::class, $this->resolve());
    }

    /**
     * Replicate the config/di.php push factory standalone. Mirrors the
     * production binding; uses the real loadFcmServiceAccount() helper
     * (defined in di.php, loaded once below).
     */
    private function resolve(): PushSenderInterface
    {
        require_once __DIR__ . '/../../config/di.php';

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            \Psr\Log\LoggerInterface::class => new \Psr\Log\NullLogger(),
            PushSenderInterface::class => static function (
                ContainerInterface $c,
            ): PushSenderInterface {
                $env = $_ENV['APP_ENV'] ?? 'dev';
                $override = $_ENV['PUSH_PROVIDER'] ?? null;
                $logger = $c->get(\Psr\Log\LoggerInterface::class);

                [$projectId, $clientEmail, $privateKey] = loadFcmServiceAccount();
                $haveCreds = $projectId !== '' && $clientEmail !== '' && $privateKey !== '';

                $useFcm = ($env === 'prod' && $haveCreds) || $override === 'fcm';

                if (!$useFcm) {
                    return new NullPushSender($logger);
                }
                if (!$haveCreds) {
                    throw new \RuntimeException(
                        'FcmHttpV1Sender requires FCM service-account credentials. '
                        . 'Set FCM_SERVICE_ACCOUNT_JSON, FCM_SERVICE_ACCOUNT_FILE, or '
                        . 'FCM_PROJECT_ID + FCM_CLIENT_EMAIL + FCM_PRIVATE_KEY.',
                    );
                }
                return new FcmHttpV1Sender(
                    projectId: $projectId,
                    clientEmail: $clientEmail,
                    privateKey: $privateKey,
                    httpClient: null,
                    logger: $logger,
                );
            },
        ]);
        return $builder->build()->get(PushSenderInterface::class);
    }
}
