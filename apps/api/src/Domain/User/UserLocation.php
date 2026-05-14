<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Bayti\Api\Domain\Common\Timestamps;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's current location capture.
 *
 * Purpose
 * -------
 * Mobile apps prompt the user on first launch for their location to
 * personalise shipping cost estimates, content (local vendors near
 * you), and language defaults. The captured location is one row per
 * user — we keep only the most recent. Historical location tracking
 * is intentionally out of scope for M3.1.x.
 *
 * Location vs User.countryCode
 * ----------------------------
 * The existing User.countryCode field stores the user's NATIONALITY
 * / SMS country (used for E.164 phone-number normalisation, OTP
 * delivery, etc.). It does NOT change when a user travels.
 *
 * UserLocation.countryCode stores the user's CURRENT physical
 * location. A UAE resident traveling to Saudi Arabia would have:
 *
 *   User.countryCode          = 'AE'  (nationality, stays put)
 *   UserLocation.countryCode  = 'SA'  (current location, updates)
 *
 * Keeping these in different tables makes the distinction explicit
 * and avoids accidentally overwriting one with the other.
 *
 * Permission semantics
 * --------------------
 * If the user denies the OS location permission, the client should
 * still POST to /v3/me/location with `permission_granted=false` so
 * we record the decision. Without that, the app would re-prompt on
 * every launch, which is bad UX.
 *
 * When `permission_granted=false`, the lat/lng/city/country fields
 * are typically null but may be partially filled (e.g. user denied
 * GPS but typed a city manually elsewhere).
 *
 * Reverse geocoding
 * -----------------
 * Out of scope for M3.1.x storage. The controller accepts whatever
 * combination of lat/lng + city + country_code the client sends.
 * If client sends only lat/lng, city stays null. If only city,
 * coordinates stay null. Both is fine. None is fine.
 *
 * M4 may add a reverse-geocoding service call to populate missing
 * city/country from lat/lng. At that point this entity gains a
 * `geocoded_at` field.
 *
 * Why a separate table (not columns on `users`)
 * ----------------------------------------------
 * Three reasons:
 *   1. Location data is sparse — many users won't grant permission.
 *      Sparse columns on `users` waste storage.
 *   2. The OS location capture cadence (first launch + on-demand)
 *      differs from the User entity's lifecycle (every API call).
 *      Splitting reduces write contention.
 *   3. Future-proofing: if location history becomes relevant later
 *      (M4+ analytics), this table can be re-keyed to allow many
 *      rows per user with minimal disruption to consumers.
 */
#[ORM\Entity(repositoryClass: UserLocationRepository::class)]
#[ORM\Table(name: 'user_locations')]
#[ORM\Index(columns: ['user_id'], name: 'idx_user_locations_user')]
#[ORM\HasLifecycleCallbacks]
class UserLocation
{
    use Timestamps;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: 'bigint')]
    private ?int $id = null;

    /**
     * One row per user. The unique constraint on user_id (enforced
     * at the DB level via a UNIQUE index in the migration) makes
     * this effectively a 1-to-1 from User → UserLocation, but
     * implemented as ManyToOne for simplicity. If history becomes
     * needed in M4+, drop the unique index and add a timestamp-keyed
     * "current" pointer.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Decimal latitude in degrees. Range -90..+90.
     *
     * Stored as `decimal` (not `float`) with precision 9, scale 6:
     *   - max ±90.000000 fits comfortably in 9 digits
     *   - scale 6 = ~11cm precision, which is more than enough for
     *     marketplace UX (shipping zones are city/area-grained)
     *   - decimal avoids float rounding surprises that bit us in
     *     Day 4 (the microsecond bug). Better to be explicit.
     */
    #[ORM\Column(name: 'latitude', type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $latitude = null;

    /**
     * Decimal longitude in degrees. Range -180..+180.
     * Stored as decimal for same reasons as latitude.
     */
    #[ORM\Column(name: 'longitude', type: 'decimal', precision: 9, scale: 6, nullable: true)]
    private ?string $longitude = null;

    /**
     * City name. Optional. Either client-supplied directly (manual
     * entry) or filled by M4 reverse-geocoding from lat/lng.
     */
    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $city = null;

    /**
     * ISO 3166-1 alpha-2 country code (e.g. 'AE', 'SA').
     * Optional — see entity docblock for distinction from
     * User.countryCode.
     */
    #[ORM\Column(name: 'country_code', type: 'string', length: 2, nullable: true)]
    private ?string $countryCode = null;

    /**
     * Whether the user granted OS-level location permission to the
     * client app. When false, lat/lng are typically null.
     *
     * Defaults to false on entity creation: the client must
     * explicitly set it true after a successful OS permission grant.
     */
    #[ORM\Column(name: 'permission_granted', type: 'boolean', options: ['default' => false])]
    private bool $permissionGranted = false;

    /**
     * Last time the client successfully captured a location reading.
     * Distinct from `updated_at` (which fires on any row update,
     * including permission_granted toggles without lat/lng).
     *
     * If null, the client has never captured coordinates (only set
     * permission_granted=false or city manually).
     */
    #[ORM\Column(name: 'last_captured_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $lastCapturedAt = null;

    // -------------------------------------------------------------------
    // Constructor
    // -------------------------------------------------------------------

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->initTimestamps();
    }

    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->touchUpdatedAt();
    }

    // -------------------------------------------------------------------
    // Mutators — controller calls update() with a coherent payload;
    // individual setters are NOT public to discourage piecemeal mutation.
    // -------------------------------------------------------------------

    /**
     * Apply a location update.
     *
     * All parameters are optional / nullable:
     *   - Pass null to LEAVE a field unchanged.
     *   - Pass an empty string for nullable string fields to CLEAR
     *     them (via the optional clearing-via-empty-string convention
     *     we use elsewhere). Caller pre-trims.
     *
     * For latitude/longitude: pass float to set, null to leave.
     * For permission_granted: pass bool to set, null to leave.
     *
     * If lat AND lng are both provided, also bumps lastCapturedAt
     * to now() — that's the only path that updates the capture
     * timestamp.
     */
    public function update(
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $city = null,
        ?string $countryCode = null,
        ?bool $permissionGranted = null,
    ): void {
        // Coordinates are paired — accept both or neither, not one.
        // The controller should enforce this; here we defensively
        // accept "neither" but treat partial as "neither" + log
        // would-be partial via assertion.
        if ($latitude !== null && $longitude !== null) {
            $this->latitude = $this->formatCoord($latitude);
            $this->longitude = $this->formatCoord($longitude);
            $this->lastCapturedAt = new DateTimeImmutable();
        }

        if ($city !== null) {
            $this->city = $city === '' ? null : trim($city);
        }

        if ($countryCode !== null) {
            $this->countryCode = $countryCode === ''
                ? null
                : strtoupper(trim($countryCode));
        }

        if ($permissionGranted !== null) {
            $this->permissionGranted = $permissionGranted;
        }
    }

    /**
     * Format a float coordinate to the precision/scale of the
     * column. Avoids surprises where PHP's float-to-string
     * conversion emits scientific notation or excessive digits
     * that Doctrine then needs to round.
     */
    private function formatCoord(float $value): string
    {
        return number_format($value, 6, '.', '');
    }

    // -------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------

    public function getId(): ?int                          { return $this->id; }
    public function getUser(): User                        { return $this->user; }
    public function getLatitude(): ?string                 { return $this->latitude; }
    public function getLongitude(): ?string                { return $this->longitude; }
    public function getCity(): ?string                     { return $this->city; }
    public function getCountryCode(): ?string              { return $this->countryCode; }
    public function isPermissionGranted(): bool            { return $this->permissionGranted; }
    public function getLastCapturedAt(): ?DateTimeImmutable { return $this->lastCapturedAt; }
}
