<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\User\User;

/**
 * Convert User entities into public response shapes.
 *
 * Why this lives here (Http\Serializers) and not on the User entity
 * ----------------------------------------------------------------
 * The public-facing JSON shape is an HTTP concern, not a domain
 * concern. Different endpoints emit different views of a user:
 *
 *   - publicProfile():   for /v3/auth/me + /v3/auth/login responses
 *                        (full self-view; includes role flags and
 *                        last_login_at, since the user owns this data)
 *
 *   - storefront():      for showing a vendor on the public catalog
 *                        (just store_legal_name, ratings, public ids
 *                        — DOES NOT include email, phone, role flags)
 *
 *   - adminListing():    for /v3/admin/users (M4)
 *                        (full record + audit fields)
 *
 * Putting these on User would couple the entity to HTTP shapes; a
 * dedicated serializer keeps responsibilities clean and makes adding
 * new views (mobile vs web?) safe.
 *
 * For M1.4.2 we only need publicProfile(). storefront() and
 * adminListing() land in M2 and M4 respectively.
 */
final class UserSerializer
{
    /**
     * Public self-view — what the user sees about themselves on
     * /v3/auth/login and /v3/auth/me.
     *
     * @return array<string, mixed>
     */
    public function publicProfile(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'phone' => $user->getPhone(),
            'country_code' => $user->getCountryCode(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),

            // Profile fields (M1.7.0+)
            'gender' => $user->getGender(),
            // DOB is a calendar date — format as ISO 8601 date (YYYY-MM-DD).
            // Not the full ATOM datetime which includes time + timezone.
            'dob' => $user->getDob()?->format('Y-m-d'),
            'avatar_url' => $user->getAvatarUrl(),
            'locale' => $user->getLocale(),
            'timezone' => $user->getTimezone(),

            'is_phone_verified' => $user->isPhoneVerified(),
            'is_email_verified' => $user->isEmailVerified(),
            'roles' => $this->extractActiveRoles($user),
            // RBAC: the granular permission keys the user effectively holds
            // (union of assigned roles; empty for plain customers/vendors) and
            // the assigned roles themselves — used by the portal to gate the UI.
            'permissions' => $user->effectivePermissionKeys(),
            'assigned_roles' => $this->assignedRoles($user),
            'is_store_approved' => $user->isStoreApproved(),
            'is_store_active' => $user->isStoreActive(),
            'last_login_at' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * Build a list of the user's active role flag names.
     *
     * Same logic as JwtService::extractActiveRoles, but kept separate
     * so the JWT and the response payload aren't accidentally coupled.
     * If the JWT ever needs an internal-only role (e.g. 'system') we
     * don't want it leaking into responses just because it appears in
     * one shared helper.
     *
     * @return string[]
     */
    private function extractActiveRoles(User $user): array
    {
        return array_values(array_filter([
            $user->isCustomer() ? 'customer' : null,
            $user->isVendor() ? 'vendor' : null,
            $user->isAdmin() ? 'admin' : null,
            $user->isFinance() ? 'finance' : null,
            $user->isSupport() ? 'support' : null,
            $user->isSubAdmin() ? 'sub_admin' : null,
        ]));
    }

    /**
     * The user's assigned RBAC roles as compact references.
     *
     * @return list<array{id:int|null, slug:string, name:string}>
     */
    private function assignedRoles(User $user): array
    {
        $out = [];
        foreach ($user->getAssignedRoles() as $role) {
            $out[] = ['id' => $role->getId(), 'slug' => $role->getSlug(), 'name' => $role->getName()];
        }
        return $out;
    }

    /**
     * Admin staff-list shape: identity + status + assigned roles + the granular
     * permissions those roles confer. Used by the staff management screen.
     *
     * @return array<string, mixed>
     */
    public function staff(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'is_admin' => $user->isAdmin(),
            'is_active' => $user->isActive(),
            'assigned_roles' => $this->assignedRoles($user),
            'permissions' => $user->effectivePermissionKeys(),
            'last_login_at' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
