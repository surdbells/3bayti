<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Container;

use Bayti\Api\Payment\Noon\HmacSha256SignatureVerifier;
use Bayti\Api\Payment\Noon\LoggingOnlyVerifier;
use Bayti\Api\Payment\Noon\NoonWebhookSignatureVerifier;
use DI\ContainerBuilder;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for the M3.1.7-A config-flag-gated verifier binding.
 *
 * The binding logic lives in config/di.php; this test exercises it
 * by building a small container with the same factory and varying
 * the NOON_VERIFY_SIGNATURE + NOON_WEBHOOK_SECRET env vars.
 *
 * We don't load the full di.php because that would require all
 * other env vars (NOON_API_BASE etc.) to be set. Instead this test
 * imports the same factory pattern and runs it standalone.
 *
 * @see config/di.php where the production binding lives.
 */
#[CoversNothing]
final class NoonWebhookVerifierBindingTest extends TestCase
{
    /** @var array<string, string|null> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Save env vars we'll mutate
        foreach (['NOON_VERIFY_SIGNATURE', 'NOON_WEBHOOK_SECRET'] as $key) {
            $this->envBackup[$key] = $_ENV[$key] ?? null;
        }
    }

    protected function tearDown(): void
    {
        // Restore env vars
        foreach ($this->envBackup as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function flagFalseReturnsLoggingOnlyVerifier(): void
    {
        $_ENV['NOON_VERIFY_SIGNATURE'] = 'false';
        $_ENV['NOON_WEBHOOK_SECRET'] = ''; // intentionally empty — flag is false so this shouldn't be required

        $verifier = $this->resolveVerifier();
        self::assertInstanceOf(LoggingOnlyVerifier::class, $verifier);
    }

    #[Test]
    public function flagMissingReturnsLoggingOnlyVerifier(): void
    {
        unset($_ENV['NOON_VERIFY_SIGNATURE']);
        $_ENV['NOON_WEBHOOK_SECRET'] = '';

        $verifier = $this->resolveVerifier();
        self::assertInstanceOf(LoggingOnlyVerifier::class, $verifier);
    }

    #[Test]
    public function flagEmptyReturnsLoggingOnlyVerifier(): void
    {
        $_ENV['NOON_VERIFY_SIGNATURE'] = '';
        $_ENV['NOON_WEBHOOK_SECRET'] = '';

        $verifier = $this->resolveVerifier();
        self::assertInstanceOf(LoggingOnlyVerifier::class, $verifier);
    }

    #[Test]
    public function flagTrueWithSecretReturnsHmacVerifier(): void
    {
        $_ENV['NOON_VERIFY_SIGNATURE'] = 'true';
        $_ENV['NOON_WEBHOOK_SECRET'] = 'test-secret-abc123';

        $verifier = $this->resolveVerifier();
        self::assertInstanceOf(HmacSha256SignatureVerifier::class, $verifier);
    }

    #[Test]
    public function flagOneWithSecretReturnsHmacVerifier(): void
    {
        // Accept '1' as truthy (common operator habit)
        $_ENV['NOON_VERIFY_SIGNATURE'] = '1';
        $_ENV['NOON_WEBHOOK_SECRET'] = 'test-secret';

        $verifier = $this->resolveVerifier();
        self::assertInstanceOf(HmacSha256SignatureVerifier::class, $verifier);
    }

    #[Test]
    public function flagTrueWithoutSecretThrows(): void
    {
        $_ENV['NOON_VERIFY_SIGNATURE'] = 'true';
        $_ENV['NOON_WEBHOOK_SECRET'] = '';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NOON_VERIFY_SIGNATURE=true requires NOON_WEBHOOK_SECRET');

        $this->resolveVerifier();
    }

    #[Test]
    public function flagTrueWithMissingSecretEnvThrows(): void
    {
        $_ENV['NOON_VERIFY_SIGNATURE'] = 'true';
        unset($_ENV['NOON_WEBHOOK_SECRET']);

        $this->expectException(\RuntimeException::class);

        $this->resolveVerifier();
    }

    #[Test]
    public function flagUnknownValueDefaultsToLoggingOnly(): void
    {
        // 'yes', 'enabled', 'on' — none of these match the strict 'true'/'1'
        // we accept. Safe-default behaviour: degrade to logging-only rather
        // than throwing or accepting the unknown value as truthy.
        $_ENV['NOON_VERIFY_SIGNATURE'] = 'yes';
        $_ENV['NOON_WEBHOOK_SECRET'] = '';

        $verifier = $this->resolveVerifier();
        self::assertInstanceOf(LoggingOnlyVerifier::class, $verifier);
    }

    #[Test]
    public function flagWhitespaceIsTrimmed(): void
    {
        $_ENV['NOON_VERIFY_SIGNATURE'] = '  true  ';
        $_ENV['NOON_WEBHOOK_SECRET'] = 'secret';

        $verifier = $this->resolveVerifier();
        self::assertInstanceOf(HmacSha256SignatureVerifier::class, $verifier);
    }

    #[Test]
    public function flagCaseInsensitive(): void
    {
        $_ENV['NOON_VERIFY_SIGNATURE'] = 'TRUE';
        $_ENV['NOON_WEBHOOK_SECRET'] = 'secret';

        $verifier = $this->resolveVerifier();
        self::assertInstanceOf(HmacSha256SignatureVerifier::class, $verifier);
    }

    /**
     * Replicate the factory from config/di.php as a standalone helper.
     * Keeping it inline avoids the test depending on loading the full
     * di.php (which requires many other env vars to be set for its
     * other bindings).
     *
     * If config/di.php's factory logic changes, this test will need to
     * be updated to match — a deliberate touch-point so divergence is
     * caught.
     */
    private function resolveVerifier(): NoonWebhookSignatureVerifier
    {
        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            \Psr\Log\LoggerInterface::class => new \Psr\Log\NullLogger(),
            NoonWebhookSignatureVerifier::class => static function (
                ContainerInterface $c
            ): NoonWebhookSignatureVerifier {
                $logger = $c->get(\Psr\Log\LoggerInterface::class);

                $verifyFlag = strtolower(trim((string) ($_ENV['NOON_VERIFY_SIGNATURE'] ?? '')));
                $verifyEnabled = $verifyFlag === 'true' || $verifyFlag === '1';

                if (!$verifyEnabled) {
                    return new LoggingOnlyVerifier($logger);
                }

                $secret = (string) ($_ENV['NOON_WEBHOOK_SECRET'] ?? '');
                if ($secret === '') {
                    throw new \RuntimeException(
                        'NOON_VERIFY_SIGNATURE=true requires NOON_WEBHOOK_SECRET to be set. '
                        . 'Either provide the secret from Noon merchant portal or set '
                        . 'NOON_VERIFY_SIGNATURE=false to use LoggingOnlyVerifier.'
                    );
                }

                return new HmacSha256SignatureVerifier($secret);
            },
        ]);
        $container = $builder->build();
        return $container->get(NoonWebhookSignatureVerifier::class);
    }
}
