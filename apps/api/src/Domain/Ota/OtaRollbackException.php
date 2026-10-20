<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

/**
 * A one-click rollback could not be performed (e.g. the target bundle's file
 * has been deleted, so re-publishing it would serve a broken download, or its
 * native range matches no installed app, so the rollback would serve nobody).
 */
final class OtaRollbackException extends \DomainException
{
    /**
     * The source's effective native range ends at 0 (a legacy row published
     * with the old "0.0.0" min default and no max): the rollback row would
     * copy that range and reach no device, while still deactivating the live
     * bundles it overlaps.
     */
    public static function unreachableRange(OtaBundle $bundle): self
    {
        return new self(sprintf(
            '%s %s is published for native app version %s, which no installed app reports, so a rollback to it would '
            . 'reach no device. Widen its native range first (set its max_native_version to the app version it was '
            . 'built for, e.g. 1.8: see OTA-DEPLOY.md), or upload its build again as a new version with Min native set '
            . 'to that app version.',
            $bundle->getPlatform(),
            $bundle->getVersion(),
            $bundle->nativeRangeLabel(),
        ));
    }

    public static function fileMissing(OtaBundle $bundle): self
    {
        return new self(sprintf(
            'The file for %s %s is no longer on the server, so it cannot be rolled back to. Upload it again as a new version instead.',
            $bundle->getPlatform(),
            $bundle->getVersion(),
        ));
    }
}
