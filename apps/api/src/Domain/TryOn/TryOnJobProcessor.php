<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\TryOn;

use Bayti\Api\Ai\TryOn\VirtualTryOnProviderInterface;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Media\ImageStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Runs one queued {@see TryOnJob}: reads the customer photo + the garment image,
 * calls the env-gated {@see VirtualTryOnProviderInterface} to generate the
 * composite, stores the result and finalises the job. Mirrors
 * {@see \Bayti\Api\Domain\Notification\BroadcastSender} — a single autowired
 * process() that does the slow work and mutates+flushes the entity.
 *
 * Privacy: the raw customer photo lives in PRIVATE, off-web-root storage
 * ({@see TryOnPhotoStorage}) and is deleted as soon as processing finishes
 * (success OR failure). The generated output is stored PUBLIC-by-unguessable
 * path (via {@see ImageStorageService}) so clients can render it in an <img>
 * (retention handled by the TTL sweep). NOTE: a hard worker crash (OOM/SIGKILL)
 * can still leave a raw photo behind because the finally never runs — the
 * ai:process-tryon-jobs worker sweeps those via
 * {@see TryOnJobRepository::findTerminalWithSourceFile()}.
 */
final class TryOnJobProcessor
{
    private const ACCEPTED_GARMENT_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private LoggerInterface $logger;

    public function __construct(
        private readonly VirtualTryOnProviderInterface $provider,
        private readonly TryOnPhotoStorage $photos,
        private readonly ImageStorageService $imageStorage,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function process(TryOnJob $job): void
    {
        try {
            $this->run($job);
            /* Commit the terminal state FIRST so a successful generation is
               durably recorded (result path set) even if the raw-photo cleanup
               below fails — otherwise a flush failure would orphan the stored
               output and mislabel the job 'failed'. */
            $this->em->flush();
        } finally {
            /* Always remove the raw customer photo once processing is done —
               best-effort and isolated so its failure never discards the
               already-committed terminal state. */
            $sourcePath = $job->getSourceImagePath();
            if ($sourcePath !== '') {
                try {
                    $this->photos->deleteInput($sourcePath);
                    $job->clearSourceImage();
                    $this->em->flush();
                } catch (\Throwable $e) {
                    $this->logger->warning('tryon source cleanup failed', [
                        'job' => $job->getJobReference(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function run(TryOnJob $job): void
    {
        if (!$this->provider->isEnabled()) {
            $job->failWith('Virtual try-on is currently unavailable.');
            return;
        }

        $product = $this->em->find(Product::class, $job->getProductId());
        if (
            !$product instanceof Product
            || !$product->isTryOnEnabled()
            || !$product->isOrderable()
            || !$product->isInStock()
        ) {
            $job->failWith('This product is not available for try-on.');
            return;
        }

        $personBytes = $this->photos->readInput($job->getSourceImagePath());
        if ($personBytes === null || $personBytes === '') {
            $job->failWith('The uploaded photo could not be read.');
            return;
        }

        $garmentPath = ImageStorageService::storagePathFromUrl($product->getPrimaryImageUrl());
        $garmentBytes = $garmentPath !== null ? $this->imageStorage->read($garmentPath) : null;
        if ($garmentBytes === null || $garmentBytes === '') {
            $job->failWith('The product image is unavailable for try-on.');
            return;
        }

        $garmentMime = $this->mimeFromPath($garmentPath);
        if (!in_array($garmentMime, self::ACCEPTED_GARMENT_MIMES, true)) {
            /* The image model only accepts JPEG/PNG/WebP; a GIF (or other)
               product image would 400 upstream and fail opaquely. */
            $job->failWith('This product image format is not supported for try-on.');
            return;
        }

        $result = $this->provider->generate(
            $personBytes,
            $this->mimeFromPath($job->getSourceImagePath()),
            $garmentBytes,
            $garmentMime,
            $product->getName(),
        );

        if ($result->isEmpty()) {
            $job->failWith('Try-on generation failed. Please try again.');
            return;
        }

        $outPath = sprintf('try-on/%d/out/%s.png', $job->getUserId(), bin2hex(random_bytes(16)));
        $stored = $this->imageStorage->storeRaw($result->imageBytes, $outPath);
        $job->succeed($stored->publicUrl(), $outPath);
    }

    private function mimeFromPath(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
