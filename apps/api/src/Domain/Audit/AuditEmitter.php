<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Audit;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Middleware\RequestIdContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Records audit log entries for mutating actions.
 *
 * Intended use from controllers
 * -----------------------------
 *
 *   // create
 *   $audit->recordCreate($request, $user, $address);
 *
 *   // update — pass before+after snapshots
 *   $beforeSnapshot = $audit->snapshot($address);
 *   $address->update(...);
 *   $afterSnapshot = $audit->snapshot($address);
 *   $audit->recordUpdate($request, $user, $address, $beforeSnapshot, $afterSnapshot);
 *
 *   // delete — pass the snapshot from BEFORE the delete
 *   $beforeSnapshot = $audit->snapshot($address);
 *   $repo->remove($address);
 *   $audit->recordDelete($request, $user, $address, $beforeSnapshot);
 *
 *   // role-flag change (e.g. set-default-address)
 *   $audit->recordDefault($request, $user, $address, [
 *       'shipping' => true,
 *   ]);
 *
 * Snapshot helper
 * ---------------
 * The `snapshot()` method extracts a plain associative array of an
 * entity's persistable state. Used to capture "before" before a
 * mutation and "after" after. The diff in recordUpdate is computed
 * by comparing them — only fields that actually changed are kept.
 *
 * Sensitive field redaction
 * -------------------------
 * Per Q5=A: any field whose name ends with '_hash' or '_token' is
 * replaced with the literal '[REDACTED]'. We do this even though
 * (a) password_hash never reaches snapshot() because Address/User
 * snapshots don't expose it, and (b) verification tokens shouldn't
 * change in a profile update. Defence in depth for future entities.
 *
 * Failure handling
 * ----------------
 * If the audit insert fails (DB error, connection timeout), we LOG
 * the error and SWALLOW the exception. Reason: an audit failure
 * should not break the user's request. The original mutation has
 * already happened (or is about to via the controller's flush).
 * Refusing to return the user's address-update because the audit
 * row couldn't be written is worse than briefly missing audit data
 * — and Sentry will alert us so we can fix it.
 *
 * Trade-off accepted: silent audit failures are possible. Mitigations:
 *   - Failures land in the application log (var/logs/) AND Sentry
 *   - DB connection issues that affect audit also affect the actual
 *     mutation, which fails first
 *   - For genuinely high-stakes actions (M3+ payments, refunds), we'll
 *     add a "must-audit" flag that fails the request on audit error
 */
final class AuditEmitter
{
    /**
     * Field-name suffixes that trigger redaction. Case-insensitive
     * match on the SUFFIX of the field name.
     */
    private const REDACTED_SUFFIXES = ['_hash', '_token', '_secret'];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Record a 'created' event.
     *
     * @param array<string, mixed>|null $afterSnapshot
     *        If null, no fields are recorded — useful when the entity's
     *        identity (id) is enough and the values aren't interesting.
     */
    public function recordCreate(
        ?ServerRequestInterface $request,
        ?User $actor,
        object $subject,
        ?array $afterSnapshot = null,
    ): void {
        $this->record(
            request: $request,
            actor: $actor,
            subject: $subject,
            action: AuditLog::ACTION_CREATED,
            changes: $afterSnapshot !== null
                ? ['after' => $this->redact($afterSnapshot)]
                : [],
        );
    }

    /**
     * Record an 'updated' event with before/after snapshots.
     * The change set keeps only fields that actually differ.
     *
     * @param array<string, mixed> $beforeSnapshot
     * @param array<string, mixed> $afterSnapshot
     */
    public function recordUpdate(
        ?ServerRequestInterface $request,
        ?User $actor,
        object $subject,
        array $beforeSnapshot,
        array $afterSnapshot,
    ): void {
        $diff = $this->diff($beforeSnapshot, $afterSnapshot);
        if (empty($diff['before']) && empty($diff['after'])) {
            // Nothing actually changed — no audit row.
            return;
        }

        $this->record(
            request: $request,
            actor: $actor,
            subject: $subject,
            action: AuditLog::ACTION_UPDATED,
            changes: $diff,
        );
    }

    /**
     * Record a 'deleted' event with the entity's pre-delete state.
     *
     * @param array<string, mixed> $beforeSnapshot
     */
    public function recordDelete(
        ?ServerRequestInterface $request,
        ?User $actor,
        object $subject,
        array $beforeSnapshot,
    ): void {
        $this->record(
            request: $request,
            actor: $actor,
            subject: $subject,
            action: AuditLog::ACTION_DELETED,
            changes: ['before' => $this->redact($beforeSnapshot)],
        );
    }

    /**
     * Record a 'default' event — used for role/flag changes that
     * aren't quite a generic update. E.g. "set this address as the
     * default shipping" doesn't change the address itself, just the
     * flags relating it to other addresses.
     *
     * @param array<string, mixed> $changes
     */
    public function recordDefault(
        ?ServerRequestInterface $request,
        ?User $actor,
        object $subject,
        array $changes,
    ): void {
        $this->record(
            request: $request,
            actor: $actor,
            subject: $subject,
            action: AuditLog::ACTION_DEFAULT,
            changes: $changes,
        );
    }

    /**
     * Take a snapshot of an entity's persistable state.
     *
     * Strategy: ask Doctrine's UnitOfWork for the original entity
     * data. This gives us the state as last seen by the persistence
     * layer — the "before" state for an update.
     *
     * For the "after" state, we use the entity's current public
     * getters mirrored to a known shape per type.
     *
     * Why we hand-roll this rather than using full Doctrine reflection:
     *   - We want CONTROL over which fields get audited (e.g. don't
     *     audit timestamps that change on every update by definition)
     *   - We want NAMES that match the API contract (e.g.
     *     `recipient_name` as snake_case, matching the JSON), not
     *     internal property names
     *
     * Per-entity snapshot shapes are defined in the snapshot helper
     * methods below.
     *
     * @return array<string, mixed>
     */
    public function snapshot(object $subject): array
    {
        return match (true) {
            $subject instanceof \Bayti\Api\Domain\User\User
                => $this->snapshotUser($subject),
            $subject instanceof \Bayti\Api\Domain\User\Address
                => $this->snapshotAddress($subject),
            $subject instanceof \Bayti\Api\Domain\User\Measurement
                => $this->snapshotMeasurement($subject),
            default => throw new \InvalidArgumentException(
                'No snapshot strategy for ' . $subject::class,
            ),
        };
    }

    /**
     * Core record method — writes the AuditLog row.
     *
     * @param array<string, mixed> $changes
     */
    private function record(
        ?ServerRequestInterface $request,
        ?User $actor,
        object $subject,
        string $action,
        array $changes,
    ): void {
        try {
            $log = new AuditLog(
                userId: $actor?->getId(),
                subjectType: self::subjectType($subject),
                subjectId: self::subjectId($subject),
                action: $action,
                changes: $changes,
                ipAddress: $request !== null ? self::extractIp($request) : null,
                userAgent: $request !== null ? self::extractUserAgent($request) : null,
                requestId: RequestIdContext::get(),
            );

            /** @var AuditLogRepository $repo */
            $repo = $this->em->getRepository(AuditLog::class);
            $repo->save($log);
        } catch (Throwable $e) {
            // Audit failures must not break the user's request. The
            // catch block must NEVER throw — that would propagate up
            // as a 500, defeating the whole point of "audit is a
            // secondary concern."
            //
            // The bug here was: subjectId() throws on null-id entities.
            // The original code called subjectId() inside both the
            // try AND the catch (for logging). When subjectId() threw
            // in the try, control jumped to catch, which called it
            // AGAIN — the second throw escaped the catch as an
            // unhandled exception.
            //
            // Fix: resolve subject_id defensively for logging, never
            // re-throwing. Same for subject_type.
            $safeSubjectType = self::subjectTypeSafe($subject);
            $safeSubjectId = self::subjectIdSafe($subject);

            $this->logger->error('Audit log write failed', [
                'subject_type' => $safeSubjectType,
                'subject_id' => $safeSubjectId,
                'action' => $action,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
            // Send to Sentry too — audit failures are exactly the
            // kind of "we want to know" event Sentry exists for.
            try {
                \Sentry\captureException($e);
            } catch (Throwable) {
                // Sentry is also down — give up silently.
            }
        }
    }

    /**
     * Get the subject's class basename WITHOUT throwing.
     * Used in the catch path of record() where we must not throw.
     */
    private static function subjectTypeSafe(object $subject): string
    {
        try {
            return self::subjectType($subject);
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Get the subject's id WITHOUT throwing. Returns 0 as the
     * "couldn't determine" marker. Used in the catch path of
     * record() where we must not throw.
     */
    private static function subjectIdSafe(object $subject): int
    {
        try {
            return self::subjectId($subject);
        } catch (Throwable) {
            return 0;
        }
    }

    // ------------------------------------------------------------------
    // Diff + redaction helpers
    // ------------------------------------------------------------------

    /**
     * Compute the diff between two snapshots. Only fields that differ
     * are kept; both before and after receive the changed-only subset.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    private function diff(array $before, array $after): array
    {
        $beforeChanged = [];
        $afterChanged = [];

        // Union of all keys (handles fields added or removed too).
        $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));

        foreach ($allKeys as $key) {
            $beforeVal = $before[$key] ?? null;
            $afterVal = $after[$key] ?? null;

            // strict !== so int 0 vs string "0" registers as changed
            if ($beforeVal !== $afterVal) {
                $beforeChanged[$key] = $beforeVal;
                $afterChanged[$key] = $afterVal;
            }
        }

        return [
            'before' => $this->redact($beforeChanged),
            'after' => $this->redact($afterChanged),
        ];
    }

    /**
     * Replace sensitive field values with the literal '[REDACTED]'.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            $isSensitive = false;
            foreach (self::REDACTED_SUFFIXES as $suffix) {
                if (str_ends_with($lower, $suffix)) {
                    $isSensitive = true;
                    break;
                }
            }
            $out[$key] = $isSensitive ? '[REDACTED]' : $value;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Per-entity snapshot strategies
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function snapshotUser(\Bayti\Api\Domain\User\User $u): array
    {
        return [
            'first_name' => $u->getFirstName(),
            'last_name' => $u->getLastName(),
            'email' => $u->getEmail(),
            'phone' => $u->getPhone(),
            'gender' => $u->getGender(),
            'dob' => $u->getDob()?->format('Y-m-d'),
            'locale' => $u->getLocale(),
            'timezone' => $u->getTimezone(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotAddress(\Bayti\Api\Domain\User\Address $a): array
    {
        return [
            'recipient_name' => $a->getRecipientName(),
            'recipient_phone' => $a->getRecipientPhone(),
            'emirate' => $a->getEmirate(),
            'area' => $a->getArea(),
            'street_address' => $a->getStreetAddress(),
            'building_details' => $a->getBuildingDetails(),
            'postal_code' => $a->getPostalCode(),
            'label' => $a->getLabel(),
            'is_default_shipping' => $a->isDefaultShipping(),
            'is_default_billing' => $a->isDefaultBilling(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotMeasurement(\Bayti\Api\Domain\User\Measurement $m): array
    {
        return [
            'category_id' => $m->getCategoryId(),
            'values' => $m->getValues(),
            'notes' => $m->getNotes(),
        ];
    }

    // ------------------------------------------------------------------
    // Subject identity helpers
    // ------------------------------------------------------------------

    private static function subjectType(object $subject): string
    {
        // Use class basename (strip namespace) — keeps subject_type
        // human-readable in audit_log. e.g. 'User' not
        // 'Bayti\\Api\\Domain\\User\\User'.
        $parts = explode('\\', $subject::class);
        return end($parts);
    }

    private static function subjectId(object $subject): int
    {
        if (!method_exists($subject, 'getId')) {
            throw new \InvalidArgumentException(
                $subject::class . ' has no getId() — can\'t audit.',
            );
        }
        $id = $subject->getId();
        if (!is_int($id)) {
            throw new \InvalidArgumentException(
                'Audit subject ' . $subject::class . ' has no integer id (got '
                . get_debug_type($id) . '). Was the entity flushed before audit?',
            );
        }
        return $id;
    }

    // ------------------------------------------------------------------
    // Request introspection
    // ------------------------------------------------------------------

    /**
     * Extract the client IP address. Honors X-Forwarded-For if set
     * (we sit behind Apache → potentially a CDN later).
     *
     * Security note: X-Forwarded-For is client-controllable. An
     * attacker can spoof it to inject a fake IP into our audit log.
     * Mitigation:
     *   - Apache writes the REAL client IP into REMOTE_ADDR
     *   - We use REMOTE_ADDR as the source of truth, X-Forwarded-For
     *     only as an additional record (NOT what we save here)
     *
     * In this implementation we just take REMOTE_ADDR (server param).
     * A future version that integrates a CDN will need to whitelist
     * the CDN's IP and trust X-Forwarded-For only from there.
     */
    private static function extractIp(ServerRequestInterface $request): ?string
    {
        $serverParams = $request->getServerParams();
        $ip = $serverParams['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || $ip === '') {
            return null;
        }
        // Validate it's a real IP — defends against weirdly-set
        // server params (e.g. unix sockets that put a path here).
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        return $ip;
    }

    private static function extractUserAgent(ServerRequestInterface $request): ?string
    {
        $ua = $request->getHeaderLine('User-Agent');
        if ($ua === '') {
            return null;
        }
        // Truncate very long UAs (TEXT column has no hard limit but
        // we don't want pathological 64KB values).
        if (strlen($ua) > 1000) {
            $ua = substr($ua, 0, 1000);
        }
        return $ua;
    }
}
