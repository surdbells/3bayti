<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

/**
 * A one-click rollback could not be performed (e.g. the target bundle's file
 * has been deleted, so re-publishing it would serve a broken download).
 */
final class OtaRollbackException extends \DomainException
{
    public static function fileMissing(OtaBundle $bundle): self
    {
        return new self(sprintf(
            'The file for %s %s is no longer on the server, so it cannot be rolled back to. Upload it again as a new version instead.',
            $bundle->getPlatform(),
            $bundle->getVersion(),
        ));
    }
}
