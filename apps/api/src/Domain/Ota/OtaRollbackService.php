<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One-click OTA rollback.
 *
 * The update endpoint only serves a bundle whose version is strictly newer
 * than the device's current one, so re-activating an old row never reaches a
 * device that already installed a later bundle. Rolling back therefore
 * re-publishes the chosen bundle's existing file (same url, checksum, session
 * key and native range, no upload) under the next unused version for its
 * app/platform/channel. Devices on the bad bundle see a newer version,
 * download the good content and apply it on their next cold start.
 *
 * Native range: the rollback row keeps the source's EFFECTIVE range
 * [min_native, max_native ?? min_native] ({@see OtaBundle::rollbackOf()}), and
 * the update endpoint applies it like any other row. So a rollback reaches
 * only devices whose native version the source supports.
 *
 * Deactivation is scoped to that range: the active bundles whose native range
 * OVERLAPS the rollback's ({@see OtaBundle::nativeRangeOverlaps()}) are
 * deactivated, i.e. the release being pulled, including its reach outside the
 * rollback's range (a bad [1.7 - 1.9] bundle is pulled by a [1.8] rollback).
 * Active bundles for other shells only (e.g. production [1.8] while rolling
 * back the TestFlight [1.8.1] bundle, or the reverse) are left alone: they
 * never compete with the rollback, and inside its own range the rollback
 * already wins because its version is above every version ever published
 * here.
 *
 * Refused (OtaRollbackException, 400) when the source's range ends at 0
 * ({@see OtaBundle::reachesNoShell()}, e.g. a legacy row from the old "0.0.0"
 * min default): the rollback would reach no device while still pulling the
 * live bundle, so the operator must widen the source's range or re-upload.
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
     * @throws OtaRollbackException when the source's native range matches no
     *         installed app, or the source bundle's file is gone
     * @throws UniqueConstraintViolationException when a concurrent publish
     *         claimed the same next version first (uniq_ota_bundle_version)
     */
    public function rollbackTo(OtaBundle $source): array
    {
        if ($source->reachesNoShell()) {
            throw OtaRollbackException::unreachableRange($source);
        }
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
                if (!$active->nativeRangeOverlaps($rollback)) {
                    continue; // a bundle for other shells only: keep serving them
                }
                $active->deactivate();
                $deactivated[] = $active;
            }
            $this->em->persist($rollback);
            $this->em->flush();
        });

        return ['bundle' => $rollback, 'deactivated' => $deactivated];
    }
}
