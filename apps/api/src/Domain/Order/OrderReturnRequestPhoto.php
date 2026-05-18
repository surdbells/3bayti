<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\Mapping as ORM;

/**
 * Photo evidence attached to an OrderReturnRequest (M3.2.X.18-A).
 *
 * Customer attaches photos when submitting a return request — these
 * are what the admin reviews when deciding approve vs deny. Per
 * Q-Photos = Flysystem-local-for-v1, the binary content lives on
 * disk under apps/api/var/uploads/return-photos/ (relative to the
 * configured Flysystem root); this entity holds the storage_path
 * pointer + the bookkeeping metadata.
 *
 * Limits (enforced at DTO + DB level):
 *   - Maximum 5 photos per request (DTO layer)
 *   - Maximum 5 MB per photo (DTO layer)
 *   - JPEG / PNG / WEBP only (DTO layer + DB chk_orrp_mime_type)
 *
 * Storage abstraction
 * ===================
 * storage_path is opaque to the API — it's whatever Flysystem path
 * the upload pipeline assigned (typically something like
 * `return-photos/2026/05/{return-request-id}/{ulid}.jpg`). The serve
 * endpoint (GET /v3/returns/{id}/photos/{photoId}) authorizes the
 * caller, then reads via FilesystemOperator::readStream().
 *
 * No URL is stored. Future migration to R2/S3 only changes the
 * Flysystem adapter binding in DI; entity stays unchanged.
 *
 * On-delete behaviour
 * ===================
 * Parent OrderReturnRequest goes away (rare admin op) → CASCADE
 * removes photo rows. The binary blobs on disk are NOT auto-deleted
 * by this — operator playbook §2.N notes a periodic cleanup step
 * for orphaned blobs. v1 acceptable as long as deletes are rare.
 */
#[ORM\Entity(repositoryClass: OrderReturnRequestPhotoRepository::class)]
#[ORM\Table(name: 'order_return_request_photos')]
class OrderReturnRequestPhoto
{
    public const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public const MAX_PHOTOS_PER_REQUEST = 5;
    public const MAX_PHOTO_SIZE_BYTES = 5_242_880;  // 5 MB

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    // @phpstan-ignore-next-line property.unusedType
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrderReturnRequest::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(name: 'return_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private OrderReturnRequest $returnRequest;

    #[ORM\Column(name: 'storage_path', type: 'string', length: 512)]
    private string $storagePath;

    #[ORM\Column(name: 'mime_type', type: 'string', length: 64)]
    private string $mimeType;

    #[ORM\Column(name: 'size_bytes', type: 'integer')]
    private int $sizeBytes;

    #[ORM\Column(name: 'original_filename', type: 'string', length: 255, nullable: true)]
    private ?string $originalFilename = null;

    #[ORM\Column(name: 'uploaded_at', type: 'datetime_immutable')]
    private DateTimeImmutable $uploadedAt;

    /**
     * Construct a photo record. The actual upload to Flysystem
     * happens before this entity is created — by the time the
     * constructor runs, the blob is on disk and storage_path is
     * known.
     *
     * @throws \InvalidArgumentException for invalid mime type or
     *         non-positive size.
     */
    public function __construct(
        string $storagePath,
        string $mimeType,
        int $sizeBytes,
        ?string $originalFilename = null,
    ) {
        if (trim($storagePath) === '') {
            throw new \InvalidArgumentException('storage_path must not be empty.');
        }
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unsupported mime type '{$mimeType}'. "
                . 'Must be one of: ' . implode(', ', self::ALLOWED_MIME_TYPES),
            );
        }
        if ($sizeBytes <= 0) {
            throw new \InvalidArgumentException(
                "size_bytes must be > 0; got {$sizeBytes}."
            );
        }
        if ($sizeBytes > self::MAX_PHOTO_SIZE_BYTES) {
            throw new \InvalidArgumentException(
                "size_bytes {$sizeBytes} exceeds max " . self::MAX_PHOTO_SIZE_BYTES . '.',
            );
        }

        $this->storagePath = $storagePath;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->originalFilename = $originalFilename !== null ? trim($originalFilename) : null;
        // Normalize empty trimmed filename to null
        if ($this->originalFilename === '') {
            $this->originalFilename = null;
        }
        $this->uploadedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Bidirectional collection setter — called from
     * OrderReturnRequest::addPhoto.
     */
    public function setReturnRequest(OrderReturnRequest $returnRequest): void
    {
        $this->returnRequest = $returnRequest;
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getReturnRequest(): OrderReturnRequest { return $this->returnRequest; }
    public function getStoragePath(): string { return $this->storagePath; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getSizeBytes(): int { return $this->sizeBytes; }
    public function getOriginalFilename(): ?string { return $this->originalFilename; }
    public function getUploadedAt(): DateTimeImmutable { return $this->uploadedAt; }
}
