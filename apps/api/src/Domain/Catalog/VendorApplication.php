<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\Common\Timestamps;
use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A prospective seller's application to join the marketplace.
 *
 * Flow (replaces self-serve vendor creation)
 * ------------------------------------------
 * The PUBLIC storefront "Become a seller" form POSTs an application
 * (no auth). An admin reviews it in the portal and either approves
 * (which provisions a User + Vendor and emails the applicant to set
 * their password) or rejects it (with an optional reason).
 *
 * This is the ONLY door into the marketplace: the legacy self-serve
 * POST /v3/vendor/onboarding/submit + the portal /auth/register vendor
 * sign-up are disabled. Vendors no longer create their own stores;
 * admins vet every applicant first.
 *
 * Lifecycle
 * ---------
 *   - pending:  submitted, awaiting admin review (initial state)
 *   - approved: admin approved; vendor_id points at the provisioned
 *               Vendor, reviewed_by_user_id at the acting admin
 *   - rejected: admin rejected; reject_reason carries the note
 *
 * The transition is one-way from pending. Approve/reject are idempotent
 * at the controller layer (re-calling on a terminal application is a
 * no-op success rather than an error).
 */
#[ORM\Entity(repositoryClass: VendorApplicationRepository::class)]
#[ORM\Table(name: 'vendor_applications')]
#[ORM\Index(columns: ['email'], name: 'idx_vendor_applications_email')]
#[ORM\Index(columns: ['status'], name: 'idx_vendor_applications_status')]
#[ORM\HasLifecycleCallbacks]
class VendorApplication
{
    use Timestamps;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const ALL_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    #[ORM\Column(name: 'first_name', type: 'string', length: 100)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', type: 'string', length: 100)]
    private string $lastName;

    /** Lowercased + trimmed at construction (and in the DTO). */
    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'string', length: 25)]
    private string $phone;

    #[ORM\Column(name: 'country_code', type: 'string', length: 2)]
    private string $countryCode = 'AE';

    #[ORM\Column(name: 'business_name', type: 'string', length: 255)]
    private string $businessName;

    #[ORM\Column(name: 'license_number', type: 'string', length: 100, nullable: true)]
    private ?string $licenseNumber = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(name: 'reject_reason', type: 'text', nullable: true)]
    private ?string $rejectReason = null;

    /**
     * The Vendor provisioned on approval. Nullable: null while pending
     * or rejected, set once approved. ON DELETE SET NULL so deleting a
     * vendor doesn't cascade-delete the historical application record.
     */
    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Vendor $vendor = null;

    /** The admin who reviewed (approved/rejected) this application. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(name: 'reviewed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    public function __construct(
        string $firstName,
        string $lastName,
        string $email,
        string $phone,
        string $businessName,
        string $countryCode = 'AE',
    ) {
        $this->firstName = trim($firstName);
        $this->lastName = trim($lastName);
        $this->email = strtolower(trim($email));
        $this->phone = trim($phone);
        $this->businessName = trim($businessName);
        $this->countryCode = strtoupper($countryCode);
        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getId(): ?int { return $this->id; }
    public function getFirstName(): string { return $this->firstName; }
    public function getLastName(): string { return $this->lastName; }
    public function getEmail(): string { return $this->email; }
    public function getPhone(): string { return $this->phone; }
    public function getCountryCode(): string { return $this->countryCode; }
    public function getBusinessName(): string { return $this->businessName; }
    public function getLicenseNumber(): ?string { return $this->licenseNumber; }
    public function getCategory(): ?string { return $this->category; }
    public function getMessage(): ?string { return $this->message; }
    public function getStatus(): string { return $this->status; }
    public function getRejectReason(): ?string { return $this->rejectReason; }
    public function getVendor(): ?Vendor { return $this->vendor; }
    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function getReviewedAt(): ?DateTimeImmutable { return $this->reviewedAt; }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isApproved(): bool { return $this->status === self::STATUS_APPROVED; }
    public function isRejected(): bool { return $this->status === self::STATUS_REJECTED; }

    // -----------------------------------------------------------------
    // Mutators
    // -----------------------------------------------------------------

    public function setLicenseNumber(?string $licenseNumber): void
    {
        $licenseNumber = $licenseNumber !== null ? trim($licenseNumber) : null;
        $this->licenseNumber = ($licenseNumber === '' || $licenseNumber === null) ? null : $licenseNumber;
    }

    public function setCategory(?string $category): void
    {
        $category = $category !== null ? trim($category) : null;
        $this->category = ($category === '' || $category === null) ? null : $category;
    }

    public function setMessage(?string $message): void
    {
        $message = $message !== null ? trim($message) : null;
        $this->message = ($message === '' || $message === null) ? null : $message;
    }

    /**
     * Mark this application approved + link the provisioned vendor and
     * the acting admin. Sets reviewed_at to now.
     */
    public function markApproved(Vendor $vendor, User $reviewedBy): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->vendor = $vendor;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = new DateTimeImmutable();
        $this->rejectReason = null;
    }

    /**
     * Mark this application rejected with an optional reason + the
     * acting admin. Sets reviewed_at to now.
     */
    public function markRejected(?string $reason, User $reviewedBy): void
    {
        $this->status = self::STATUS_REJECTED;
        $reason = $reason !== null ? trim($reason) : null;
        $this->rejectReason = ($reason === '' || $reason === null) ? null : $reason;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = new DateTimeImmutable();
    }
}
