<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\TryOn;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * One virtual-try-on generation request.
 *
 * Lifecycle: queued -> processing -> (succeeded | failed). A queued row is
 * created by POST /v3/ai/try-on and worked asynchronously by the
 * `ai:process-tryon-jobs` cron command (the generation call to the image model
 * is multi-second and must not block the HTTP request), then polled by the
 * customer via GET /v3/ai/try-on/{reference}.
 *
 * The public handle is an unguessable `job_reference` (never the serial id), so
 * the poll URL is not enumerable — consistent with the no-legacy-ids rule.
 *
 * Privacy: `source_image_path` is the PRIVATE Flysystem key of the customer's
 * uploaded photo; it is deleted as soon as generation finishes (success or
 * fail), so the raw photo lives only transiently. `result_image_url` is the
 * PUBLIC (unguessable-path) URL of the generated image, retained subject to the
 * retention policy (account-deletion hook + TTL sweep).
 *
 * Counters/columns mirror {@see \Bayti\Api\Domain\Notification\NotificationBroadcast}.
 */
#[ORM\Entity(repositoryClass: TryOnJobRepository::class)]
#[ORM\Table(name: 'tryon_jobs')]
#[ORM\Index(columns: ['status'], name: 'idx_tryon_jobs_status')]
#[ORM\Index(columns: ['status', 'created_at'], name: 'idx_tryon_jobs_status_created')]
#[ORM\Index(columns: ['user_id'], name: 'idx_tryon_jobs_user')]
class TryOnJob
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\Column(name: 'job_reference', type: 'string', length: 32, unique: true)]
    private string $jobReference;

    #[ORM\Column(name: 'user_id', type: 'bigint')]
    private int $userId;

    #[ORM\Column(name: 'product_id', type: 'bigint')]
    private int $productId;

    #[ORM\Column(name: 'source_image_path', type: 'string', length: 1000)]
    private string $sourceImagePath;

    #[ORM\Column(name: 'result_image_url', type: 'string', length: 1000, nullable: true)]
    private ?string $resultImageUrl = null;

    #[ORM\Column(name: 'result_image_path', type: 'string', length: 1000, nullable: true)]
    private ?string $resultImagePath = null;

    #[ORM\Column(name: 'status', type: 'string', length: 24)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(name: 'attempts', type: 'integer')]
    private int $attempts = 0;

    #[ORM\Column(name: 'error_sample', type: 'text', nullable: true)]
    private ?string $errorSample = null;

    #[ORM\Column(name: 'started_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'finished_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        int $userId,
        int $productId,
        string $sourceImagePath,
    ) {
        $now = new DateTimeImmutable();
        $this->jobReference = bin2hex(random_bytes(16));
        $this->userId = $userId;
        $this->productId = $productId;
        $this->sourceImagePath = $sourceImagePath;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    // ── Lifecycle mutators (used by the processor) ─────────────────────
    public function markProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->attempts++;
        $this->startedAt = new DateTimeImmutable();
        $this->touch();
    }

    public function succeed(string $resultImageUrl, string $resultImagePath): void
    {
        $this->status = self::STATUS_SUCCEEDED;
        $this->resultImageUrl = $resultImageUrl;
        $this->resultImagePath = $resultImagePath;
        $this->finishedAt = new DateTimeImmutable();
        $this->touch();
    }

    /** Terminal failure. Never leaves a job stuck in 'processing'. */
    public function failWith(?string $sample): void
    {
        $this->status = self::STATUS_FAILED;
        if ($this->errorSample === null && $sample !== null) {
            $this->errorSample = mb_substr($sample, 0, 1000);
        }
        $this->finishedAt = new DateTimeImmutable();
        $this->touch();
    }

    /** Clear the source photo reference once it has been deleted from storage. */
    public function clearSourceImage(): void
    {
        $this->sourceImagePath = '';
        $this->touch();
    }

    /**
     * Clear the generated-image references once the file has been deleted from
     * storage by the retention sweep — the row stays as a record, but the image
     * no longer resolves and is not swept again.
     */
    public function clearResultImage(): void
    {
        $this->resultImagePath = null;
        $this->resultImageUrl = null;
        $this->touch();
    }

    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED || $this->status === self::STATUS_FAILED;
    }

    private function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    // ── Getters ────────────────────────────────────────────────────────
    public function getId(): ?int { return $this->id; }
    public function getJobReference(): string { return $this->jobReference; }
    public function getUserId(): int { return $this->userId; }
    public function getProductId(): int { return $this->productId; }
    public function getSourceImagePath(): string { return $this->sourceImagePath; }
    public function getResultImageUrl(): ?string { return $this->resultImageUrl; }
    public function getResultImagePath(): ?string { return $this->resultImagePath; }
    public function getStatus(): string { return $this->status; }
    public function getAttempts(): int { return $this->attempts; }
    public function getErrorSample(): ?string { return $this->errorSample; }
    public function getStartedAt(): ?DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?DateTimeImmutable { return $this->finishedAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
