<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\TryOn;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Visibility;

/**
 * Storage for the RAW customer photo uploaded for a virtual try-on.
 *
 * Deliberately backed by its OWN Flysystem operator rooted OUTSIDE the
 * web-served uploads tree (see the DI factory — var/private, which the Apache
 * `/uploads` alias does not cover), because a person's uploaded photo must NOT
 * be HTTP-reachable at all. This is stronger than the generic "private
 * visibility" on the alias-served uploads root, which is only a 0600 chmod and
 * would still be readable by the web-server user if the (unguessable) path
 * leaked. The photo is read once by the worker to generate the try-on and then
 * deleted; it is never served to any client.
 */
final class TryOnPhotoStorage
{
    public function __construct(
        private readonly FilesystemOperator $filesystem,
    ) {
    }

    /**
     * Persist the uploaded photo bytes privately.
     *
     * @throws FilesystemException on backing-store failure.
     */
    public function storeInput(string $bytes, string $storagePath): void
    {
        $tmp = tmpfile();
        if ($tmp === false) {
            throw new \RuntimeException('Failed to allocate temp file for try-on input write.');
        }
        try {
            fwrite($tmp, $bytes);
            rewind($tmp);
            $previousUmask = umask(0077);
            try {
                $this->filesystem->writeStream($storagePath, $tmp, ['visibility' => Visibility::PRIVATE]);
            } finally {
                umask($previousUmask);
            }
        } finally {
            fclose($tmp);
        }
    }

    /** Read the stored photo bytes. Returns null when absent. */
    public function readInput(string $storagePath): ?string
    {
        try {
            if (!$this->filesystem->fileExists($storagePath)) {
                return null;
            }
            return $this->filesystem->read($storagePath);
        } catch (FilesystemException) {
            return null;
        }
    }

    /** Delete the stored photo. Idempotent. */
    public function deleteInput(string $storagePath): void
    {
        try {
            $this->filesystem->delete($storagePath);
        } catch (FilesystemException) {
            // Already gone, fine.
        }
    }
}
