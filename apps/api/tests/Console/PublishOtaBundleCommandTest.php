<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Console;

use Bayti\Api\Console\PublishOtaBundleCommand;
use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PublishOtaBundleCommand::class)]
final class PublishOtaBundleCommandTest extends TestCase
{
    private const URL = 'https://api-v3.3bayti.ae/uploads/ota/com.threebayti.app/production/android/1.7.1.zip';

    /** @var list<OtaBundle> */
    private array $persisted = [];
    /** @var list<OtaBundle> what activeFor() returns */
    private array $active = [];

    #[Test]
    public function registersASignedBundleWithItsEncryptedChecksumAndNotes(): void
    {
        $tester = $this->publish($this->signed([
            '--platform' => 'android',
            '--version' => '1.7.1',
            '--url' => self::URL,
            '--min-native' => '1.7',
            '--notes' => 'Faster checkout',
        ]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount(1, $this->persisted);
        $bundle = $this->persisted[0];
        self::assertSame(self::encrypted(), $bundle->getChecksum());
        self::assertSame(self::sessionKey(), $bundle->getSessionKey());
        self::assertSame('Faster checkout', $bundle->getNotes());
        self::assertSame('1.7', $bundle->getMinNativeVersion());
    }

    #[Test]
    public function registersAnUnsignedAndroidBundleOffProduction(): void
    {
        $tester = $this->publish(['--platform' => 'android', '--channel' => 'beta', '--version' => '1.7.1', '--url' => self::URL, '--checksum' => hash('sha256', 'zip')]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNull($this->persisted[0]->getNotes());
        self::assertNull($this->persisted[0]->getSessionKey());
    }

    #[Test]
    public function refusesUnsignedBundlesForIosAndProduction(): void
    {
        $ios = $this->publish(['--platform' => 'ios', '--channel' => 'beta', '--version' => '1.7.1', '--url' => self::URL, '--checksum' => hash('sha256', 'zip')]);
        self::assertSame(Command::INVALID, $ios->getStatusCode());
        self::assertStringContainsString('iOS only installs SIGNED', $ios->getDisplay());

        $production = $this->publish(['--platform' => 'android', '--version' => '1.7.1', '--url' => self::URL, '--checksum' => hash('sha256', 'zip')]);
        self::assertSame(Command::INVALID, $production->getStatusCode());
        self::assertStringContainsString('Production bundles must be SIGNED', $production->getDisplay());

        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function refusesThePlainChecksumForASignedBundle(): void
    {
        $tester = $this->publish([
            '--platform' => 'android',
            '--version' => '1.7.1',
            '--url' => self::URL,
            '--checksum' => hash('sha256', 'zip'),
            '--session-key' => self::sessionKey(),
        ]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('PLAIN sha256', $tester->getDisplay());
        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function refusesAMalformedSessionKey(): void
    {
        $tester = $this->publish([
            '--platform' => 'android',
            '--version' => '1.7.1',
            '--url' => self::URL,
            '--checksum' => self::encrypted(),
            '--session-key' => 'iv:key',
        ]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('session_key must be the ivSessionKey', $tester->getDisplay());
        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function refusesPreReleaseVersions(): void
    {
        $tester = $this->publish($this->signed(['--platform' => 'android', '--version' => '1.7.1-beta', '--url' => self::URL]));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function refusesAVersionNoDeviceCouldEverBeServed(): void
    {
        $this->active = [new OtaBundle('com.threebayti.app', 'android', 'production', '1.7.3', 'https://x/1.7.3.zip', self::encrypted(), '1.7')];

        $tester = $this->publish($this->signed(['--platform' => 'android', '--version' => '1.7.2', '--url' => self::URL, '--min-native' => '1.7']));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('would never be served', $tester->getDisplay());
        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function maxNativeDefaultsToMinNativeAndIsStored(): void
    {
        $tester = $this->publish($this->signed(['--platform' => 'android', '--version' => '1.7.1', '--url' => self::URL, '--min-native' => '1.8']));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('1.8', $this->persisted[0]->getMinNativeVersion());
        self::assertSame('1.8', $this->persisted[0]->getMaxNativeVersion());
        self::assertStringContainsString('for native app version 1.8.', self::flat($tester));
    }

    #[Test]
    public function storesAnExplicitMaxNative(): void
    {
        $tester = $this->publish($this->signed([
            '--platform' => 'android',
            '--version' => '1.7.1',
            '--url' => self::URL,
            '--min-native' => '1.8',
            '--max-native' => '1.9',
        ]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('1.9', $this->persisted[0]->getMaxNativeVersion());
        self::assertStringContainsString('for native app version 1.8 - 1.9.', self::flat($tester));
    }

    #[Test]
    public function minNativeIsRequired(): void
    {
        $tester = $this->publish($this->signed(['--platform' => 'android', '--version' => '1.7.1', '--url' => self::URL, '--min-native' => null]));

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('--min-native is required', $tester->getDisplay());
        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function refusesAnInvalidNativeRange(): void
    {
        $base = ['--platform' => 'android', '--version' => '1.7.1', '--url' => self::URL];

        $below = $this->publish($this->signed($base + ['--min-native' => '1.8', '--max-native' => '1.7']));
        self::assertSame(Command::INVALID, $below->getStatusCode());
        self::assertStringContainsString('must not be lower than', $below->getDisplay());

        $malformed = $this->publish($this->signed($base + ['--min-native' => '1.8', '--max-native' => '1.9-beta']));
        self::assertSame(Command::INVALID, $malformed->getStatusCode());
        self::assertStringContainsString('--max-native must be numeric', $malformed->getDisplay());

        $zeroMax = $this->publish($this->signed($base + ['--min-native' => '1.8', '--max-native' => '0']));
        self::assertSame(Command::INVALID, $zeroMax->getStatusCode(), '"0" is validated, not replaced by the min');
        self::assertStringContainsString('--max-native (0) must not be lower than', $zeroMax->getDisplay());

        $zero = $this->publish($this->signed($base + ['--min-native' => '0.0.0']));
        self::assertSame(Command::INVALID, $zero->getStatusCode());
        self::assertStringContainsString('matches no installed app', $zero->getDisplay());

        self::assertSame([], $this->persisted);
    }

    #[Test]
    public function allowsALowerVersionForShellsAboveTheLiveBundlesMax(): void
    {
        // Production 1.7.3 for shell 1.8 only; a TestFlight 1.8.1 bundle is servable.
        $this->active = [new OtaBundle('com.threebayti.app', 'android', 'production', '1.7.3', 'https://x/1.7.3.zip', self::encrypted(), '1.8')];

        $tester = $this->publish($this->signed(['--platform' => 'android', '--version' => '1.7.0', '--url' => self::URL, '--min-native' => '1.8.1']));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('1.8.1', $this->persisted[0]->getMaxNativeVersion());
    }

    #[Test]
    public function dryRunWritesNothing(): void
    {
        $tester = $this->publish($this->signed(['--platform' => 'android', '--version' => '1.7.1', '--url' => self::URL, '--dry-run' => true]));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $this->persisted);
    }

    /** The command output with SymfonyStyle's line wrapping collapsed to single spaces. */
    private static function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private static function encrypted(): string
    {
        return str_repeat('ab', 256); // 512 hex, RSA-2048
    }

    private static function sessionKey(): string
    {
        return base64_encode(str_repeat("\x01", 16)) . ':' . base64_encode(str_repeat("\x02", 256));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function signed(array $input): array
    {
        return $input + ['--checksum' => self::encrypted(), '--session-key' => self::sessionKey()];
    }

    /**
     * --min-native is required, so it defaults to the production shell (1.8)
     * here unless the test sets it; a null value omits the option entirely.
     *
     * @param array<string, mixed> $input
     */
    private function publish(array $input): CommandTester
    {
        if (!array_key_exists('--min-native', $input)) {
            $input['--min-native'] = '1.8';
        }
        $input = array_filter($input, static fn (mixed $v): bool => $v !== null);

        $repo = $this->createMock(OtaBundleRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $repo->method('activeFor')->willReturnCallback(fn (): array => $this->active);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });

        $tester = new CommandTester(new PublishOtaBundleCommand($em));
        $tester->execute($input);

        return $tester;
    }
}
