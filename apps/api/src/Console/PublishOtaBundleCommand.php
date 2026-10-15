<?php

declare(strict_types=1);

namespace Bayti\Api\Console;

use Bayti\Api\Domain\Ota\OtaBundle;
use Bayti\Api\Domain\Ota\OtaBundleRepository;
use Bayti\Api\Domain\Ota\OtaChecksum;
use Bayti\Api\Domain\Ota\OtaSigning;
use Bayti\Api\Domain\Ota\OtaVersion;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Register an ALREADY-HOSTED OTA web bundle in `ota_bundles` so devices polling
 * POST /v3/ota/updates can download it. Run on the API server; there is no
 * auth surface (a CLI, not an HTTP endpoint).
 *
 * This is the escape hatch, not the standard path. Normal releases are
 * uploaded through the portal (OTA updates page, or `npm run ota:release`),
 * which stores the .zip on this server under var/uploads/ota/… and registers
 * the row in one step. Use ota:publish only when the .zip is already reachable
 * over HTTPS, e.g. you copied it into var/uploads yourself:
 *
 *   bin/console ota:publish --platform android --version 1.0.7 \
 *     --url https://api-v3.3bayti.ae/uploads/ota/com.threebayti.app/production/android/1.0.7.zip \
 *     --checksum <sha256 | encrypted checksum> [--session-key <ivSessionKey>] \
 *     --min-native 1.6.0 [--notes "What's new"]
 *
 * --checksum is the plain 64-hex SHA256 for an unsigned bundle, or, with
 * --session-key, the ENCRYPTED checksum printed by `@capgo/cli bundle encrypt`
 * (exactly 512 hex for RSA-2048), never the plain one (see OtaChecksum).
 * Unsigned bundles are only accepted for android on a non-production channel
 * (iOS can't install them, production must be signed; see OtaSigning), and a
 * version that an active bundle would always shadow is refused.
 *
 * The highest-version ACTIVE compatible bundle per app/platform/channel is
 * served, and only to devices on an older version. Deactivating a bundle is
 * therefore NOT a rollback: devices that already installed it keep it. To move
 * them back, use the portal's "Roll back to this" (OtaRollbackService), which
 * republishes the good bundle's file under the next unused version.
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
            ->addOption('checksum', null, InputOption::VALUE_REQUIRED, 'Unsigned: SHA256 of the .zip (64 hex). Signed: the encrypted checksum from @capgo/cli bundle encrypt')
            ->addOption('min-native', null, InputOption::VALUE_REQUIRED, 'Lowest compatible native build', '0.0.0')
            ->addOption('channel', null, InputOption::VALUE_REQUIRED, 'Release channel', OtaBundle::DEFAULT_CHANNEL)
            ->addOption('app-id', null, InputOption::VALUE_REQUIRED, 'App id', self::DEFAULT_APP_ID)
            ->addOption('session-key', null, InputOption::VALUE_REQUIRED, 'IV session key (signed/encrypted bundles only)')
            ->addOption('notes', null, InputOption::VALUE_REQUIRED, '"What\'s new" summary shown to customers after the update')
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
        $sessionKey = is_string($sessionKeyRaw) && trim($sessionKeyRaw) !== '' ? trim($sessionKeyRaw) : null;
        $notesRaw = $input->getOption('notes');
        $notes = is_string($notesRaw) && trim($notesRaw) !== '' ? trim($notesRaw) : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $errors = [];
        if (!in_array($platform, OtaBundle::ALL_PLATFORMS, true)) {
            $errors[] = 'platform must be one of: ' . implode(', ', OtaBundle::ALL_PLATFORMS);
        }
        if (!OtaVersion::isValid($version)) {
            $errors[] = 'version must be numeric with 1-4 segments (e.g. 1.0.7); pre-release suffixes are not supported';
        }
        if (!OtaVersion::isValid($minNative)) {
            $errors[] = 'min-native must be numeric with 1-4 segments (e.g. 1.6.0)';
        }
        if (!str_starts_with($url, 'https://')) {
            $errors[] = 'url must be an https:// URL';
        }
        $checksumError = OtaChecksum::validate($checksum, $sessionKey !== null);
        if ($checksumError !== null) {
            $errors[] = $checksumError;
        }
        $signingError = $sessionKey !== null
            ? OtaSigning::sessionKeyError($sessionKey)
            : OtaSigning::unsignedError([$platform], $channel);
        if ($signingError !== null) {
            $errors[] = $signingError;
        }
        if ($errors !== []) {
            foreach ($errors as $message) {
                $io->error($message);
            }
            return Command::INVALID;
        }

        // Duplicate guard, mirrors uniq_ota_bundle_version.
        /** @var OtaBundleRepository $repo */
        $repo = $this->em->getRepository(OtaBundle::class);
        $existing = $repo->findOneBy([
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
        // A version no device could ever be served (see OtaBundle::shadows).
        foreach ($repo->activeFor($appId, $platform, $channel) as $active) {
            if ($active->shadows($version, $minNative)) {
                $io->error(sprintf(
                    'Version %s would never be served: active bundle %s (min native %s) is at least as high and '
                    . 'runs on the same app builds. Use a version above %s, or deactivate %s first.',
                    $version,
                    $active->getVersion(),
                    $active->getMinNativeVersion(),
                    $active->getVersion(),
                    $active->getVersion(),
                ));
                return Command::FAILURE;
            }
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
            ['notes' => $notes ?? '-'],
        );

        if ($dryRun) {
            $io->note('Dry run — nothing written.');
            return Command::SUCCESS;
        }

        $bundle = new OtaBundle($appId, $platform, $channel, $version, $url, $checksum, $minNative, $sessionKey, $notes);
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
}
