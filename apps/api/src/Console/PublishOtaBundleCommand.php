<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Ota\OtaBundle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Register a self-hosted OTA web bundle in `ota_bundles` so devices polling
 * POST /v3/ota/updates can download it. Run from the deploy box as part of the
 * release workflow — there is no auth surface (a CLI on the server, not an HTTP
 * endpoint).
 *
 * Typical release:
 *   cd apps/mobile && npm run build
 *   npx @capgo/cli bundle zip com.threebayti.app --path www --json   # -> checksum
 *   wrangler r2 object put 3bayti-ota/ota/android/1.0.7.zip --file <zip>
 *   bin/console ota:publish --platform android --version 1.0.7 \
 *     --url https://cdn.3bayti.ae/ota/android/1.0.7.zip --checksum <sha256> \
 *     --min-native 1.6.0
 *
 * The newest ACTIVE bundle per app/platform/channel wins; retire a bad one with
 * a manual `UPDATE ota_bundles SET is_active = false WHERE id = ...` and the
 * previous bundle is served again.
 */
#[AsCommand(
    name: 'ota:publish',
    description: 'Register a self-hosted OTA web bundle so devices can download it',
)]
final class PublishOtaBundleCommand extends Command
{
    private const DEFAULT_APP_ID = 'com.threebayti.app';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, 'android | ios')
            ->addOption('version', null, InputOption::VALUE_REQUIRED, 'Bundle semver, e.g. 1.0.7')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Public HTTPS URL of the bundle .zip')
            ->addOption('checksum', null, InputOption::VALUE_REQUIRED, 'SHA256 of the .zip (from @capgo/cli bundle zip)')
            ->addOption('min-native', null, InputOption::VALUE_REQUIRED, 'Lowest compatible native build', '0.0.0')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Release channel', OtaBundle::DEFAULT_CHANNEL)
            ->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'App id', self::DEFAULT_APP_ID)
            ->addOption('session-key', null, InputOption::VALUE_REQUIRED, 'IV session key (signed/encrypted bundles only)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and print without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $platform = (string) $input->getOption('platform');
        $version = trim((string) $input->getOption('version'));
        $url = trim((string) $input->getOption('url'));
        $checksum = trim((string) $input->getOption('checksum'));
        $minNative = trim((string) $input->getOption('min-native')) ?: '0.0.0';
        $channel = trim((string) $input->getOption('channel')) ?: OtaBundle::DEFAULT_CHANNEL;
        $appId = trim((string) $input->getOption('app-id')) ?: self::DEFAULT_APP_ID;
        $sessionKeyRaw = $input->getOption('session-key');
        $sessionKey = is_string($sessionKeyRaw) && $sessionKeyRaw !== '' ? $sessionKeyRaw : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $errors = [];
        if (!in_array($platform, OtaBundle::ALL_PLATFORMS, true)) {
            $errors[] = 'platform must be one of: ' . implode(', ', OtaBundle::ALL_PLATFORMS);
        }
        if (!$this->isSemver($version)) {
            $errors[] = 'version must be semver (e.g. 1.0.7)';
        }
        if (!$this->isSemver($minNative)) {
            $errors[] = 'min-native must be semver (e.g. 1.6.0)';
        }
        if (!str_starts_with($url, 'https://')) {
            $errors[] = 'url must be an https:// URL';
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $checksum)) {
            $errors[] = 'checksum must be a 64-char hex SHA256';
        }
        if ($errors !== []) {
            foreach ($errors as $message) {
                $io->error($message);
            }
            return Command::INVALID;
        }

        // Duplicate guard — mirrors uniq_ota_bundle_version.
        $existing = $this->em->getRepository(OtaBundle::class)->findOneBy([
            'appId' => $appId,
            'platform' => $platform,
            'channel' => $channel,
            'version' => $version,
        ]);
        if ($existing !== null) {
            $io->error(sprintf(
                'A bundle already exists for %s / %s / %s version %s (id %s). Bump the version.',
                $appId,
                $platform,
                $channel,
                $version,
                (string) $existing->getId(),
            ));
            return Command::FAILURE;
        }

        $io->title('Publish OTA bundle' . ($dryRun ? ' [DRY RUN]' : ''));
        $io->definitionList(
            ['app_id' => $appId],
            ['platform' => $platform],
            ['channel' => $channel],
            ['version' => $version],
            ['url' => $url],
            ['checksum' => $checksum],
            ['min_native_version' => $minNative],
            ['signed' => $sessionKey !== null ? 'yes' : 'no'],
        );

        if ($dryRun) {
            $io->note('Dry run — nothing written.');
            return Command::SUCCESS;
        }

        $bundle = new OtaBundle($appId, $platform, $channel, $version, $url, $checksum, $minNative, $sessionKey);
        $this->em->persist($bundle);
        $this->em->flush();

        $io->success(sprintf(
            'Published bundle id %s — %s %s (%s) is now live on channel "%s".',
            (string) $bundle->getId(),
            $platform,
            $version,
            $appId,
            $channel,
        ));

        return Command::SUCCESS;
    }

    private function isSemver(string $value): bool
    {
        return (bool) preg_match('/^\d+(\.\d+){0,3}(-[0-9A-Za-z.-]+)?$/', $value);
    }
}
