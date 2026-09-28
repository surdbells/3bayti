<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

use Doctrine\ORM\EntityManagerInterface;

/**
 * One-click OTA rollback.
 *
 * The update endpoint only serves a bundle whose version is strictly newer
 * than the device's current one, so re-activating an old row never reaches a
 * device that already installed a later bundle. Rolling back therefore
 * re-publishes the chosen bundle's existing file (same url, checksum, session
 * key and native gate, no upload) under the next unused version for its
 * app/platform/channel, and deactivates every other active bundle there so
 * the rollback is the one being served. Devices on the bad bundle see a newer
 * version, download the good content and apply it on their next cold start.
 */
final class OtaRollbackService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OtaBundleStorageService $storage,
    ) {
    }

    /**
     * @return array{bundle: OtaBundle, deactivated: list<OtaBundle>}
     *
     * @throws OtaRollbackException when the source bundle's file is gone
     */
    public function rollbackTo(OtaBundle $source): array
    {
        if (!$this->storage->isAvailable($source->getUrl())) {
            throw OtaRollbackException::fileMissing($source);
        }

        /** @var OtaBundleRepository $repo */
        $repo = $this->em->getRepository(OtaBundle::class);
        $appId = $source->getAppId();
        $platform = $source->getPlatform();
        $channel = $source->getChannel();

        $highest = OtaVersion::max($repo->versionsFor($appId, $platform, $channel)) ?? $source->getVersion();
        $rollback = OtaBundle::rollbackOf($source, OtaVersion::next($highest));

        /** @var list<OtaBundle> $deactivated */
        $deactivated = [];
        $this->em->wrapInTransaction(function () use ($repo, $appId, $platform, $channel, $rollback, &$deactivated): void {
            foreach ($repo->activeFor($appId, $platform, $channel) as $active) {
                $active->deactivate();
                $deactivated[] = $active;
            }
            $this->em->persist($rollback);
            $this->em->flush();
        });

        return ['bundle' => $rollback, 'deactivated' => $deactivated];
    }
}
