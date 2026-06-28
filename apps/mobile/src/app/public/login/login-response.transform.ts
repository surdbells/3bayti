/**
 * Transform a v3 login response into the legacy `single_user` shape
 * stored in Capacitor Preferences under the 'user' key.
 *
 * Why this file exists
 * ====================
 * After M3.1.2 the mobile app routes POST /users/login through
 * MobileNetworkAdapter, which translates v3's response envelope
 * back into the legacy `{response_code, status, message, data}`
 * shape so call sites don't care which backend served them.
 *
 * BUT — the *contents* of `data` are different between backends:
 *
 *   Legacy POST /users/login -> data = { id, token, first_name, ..., is_vendor, is_store_active, ... }
 *   v3 POST /v3/auth/login   -> data = { access_token, refresh_token, ..., user: { id, email, ..., roles: [...] } }
 *
 * Mobile reads `data` and JSON-stringifies it directly into the
 * 'user' Preferences key. 35 call sites then JSON.parse that back
 * into `single_user` and read fields like `single_user.id`,
 * `single_user.token`, `single_user.is_vendor`. If we stored the
 * v3 shape unchanged, every one of those reads would break.
 *
 * Single point of fix: transform the v3 shape into the legacy
 * shape AT the login handler, before storage. Every downstream
 * reader stays unchanged. This matches the "preserve legacy shape"
 * philosophy of the adapter (see mobile-network-adapter.ts class
 * docblock).
 *
 * Field mapping
 * =============
 * The mapping below was derived by:
 *   1. Reading the v3 LoginController + UserSerializer::publicProfile
 *      to enumerate what v3 actually returns.
 *   2. Greping every reader of `single_user.*` across mobile/src to
 *      catalog the legacy contract (what fields are actually read).
 *   3. Reconciling: include every field with a real consumer, derive
 *      where the v3 shape diverges, omit fields with zero consumers.
 *
 *   v3 source                        -> legacy field        consumers
 *   -----------------                   ----------------    ---------
 *   data.access_token                -> token               read by ~all authenticated calls (in body)
 *   data.user.id                     -> id                  read by ~all authenticated calls (in body)
 *   data.user.first_name             -> first_name          read by header, profile, account
 *   data.user.last_name              -> last_name           read by header, profile, account
 *   data.user.email                  -> email               read by profile, account
 *   data.user.phone                  -> phone               read by profile, account
 *   data.user.roles.includes('vendor') -> is_vendor         read by store-dashboard, vendor-chat-list
 *   data.user.is_store_active        -> is_store_active     read by vendor-chat-list
 *   data.user.is_store_approved      -> is_store_approved   declared in single_user shape; no read consumers but cheap to include
 *   data.refresh_token               -> refresh_token       NEW field; no legacy consumers; included for M3.1.4 OTP/reset
 *
 *   Omitted (zero readers across mobile/src):
 *     is_2fa, is_active, is_admin, is_customer, user_type, avatar
 *   Not in v3 login response by design (separate endpoints per M3.1.1):
 *     location, villa_number, delivery_address,
 *     billing_name, billing_email, billing_phone,
 *     billing_street, billing_city, billing_area
 *
 *   Known gap: 3 pages (process, checkout, settings) read
 *   `single_user.billing_*` / `single_user.location` /
 *   `single_user.villa_number`. After this flip those reads return
 *   undefined. Two of three pages still talk to the legacy backend
 *   for now (M3.1.5+ migrates them); the legacy endpoints fetch
 *   their own state and don't depend on the cached blob. The
 *   checkout page is the highest-risk consumer — flagged in the
 *   M3.1.3 completion runbook for device-test verification.
 *
 * Legacy-passthrough behaviour
 * ============================
 * The MobileNetworkAdapter falls through to NetworkService when an
 * endpoint isn't in ENDPOINT_ROUTING or its target is 'old'. In
 * either case, the response.data already has the legacy shape
 * (`{id, token, ...}`). For defence-in-depth this transform
 * detects that case (input has `id` + `token` strings) and returns
 * the input unchanged, so flipping ENDPOINT_ROUTING['POST /auth/login']
 * back to target:'old' as an emergency rollback wouldn't require
 * touching this file too.
 *
 * Defensive: returns null for inputs that match neither shape.
 * Caller decides what to do (the login handler treats null as
 * "fall back to storing response.data as-is" — preserving today's
 * behaviour for unexpected shapes rather than masking with empty
 * fields).
 */

/** Subset of fields downstream readers actually use. */
export interface LegacyUserShape {
  id: number;
  token: string;
  refresh_token?: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  is_vendor: boolean;
  is_store_active: boolean;
  is_store_approved: boolean;
  // Whether the account has a verified phone number. Social sign-in
  // (Google/Apple) creates accounts with is_phone_verified=false; the
  // login/social handler reads this to gate fresh social accounts behind
  // the phone-capture step. Email/OTP/password logins surface true.
  is_phone_verified?: boolean;
  // Allow arbitrary extra fields so a legacy passthrough preserves
  // everything the legacy backend returned (e.g. avatar, billing_*).
  // The defined fields above are the contract; extras are best-effort.
  [extra: string]: unknown;
}

/**
 * Transform a login response's `data` into the legacy `single_user`
 * shape for Preferences storage.
 *
 * @param data - the `response.data` field after MobileNetworkAdapter
 *   wrapping. Could be v3-shaped (from v3 routing), legacy-shaped
 *   (from fall-through), or something unexpected.
 * @returns the legacy shape if recognizable; otherwise null.
 */
export function transformV3LoginResponse(data: unknown): LegacyUserShape | null {
  if (data === null || data === undefined || typeof data !== 'object' || Array.isArray(data)) {
    return null;
  }

  const d = data as Record<string, unknown>;

  // --- Path 1: v3 shape (has access_token + user) ----------------
  if (typeof d['access_token'] === 'string' && isPlainObject(d['user'])) {
    const u = d['user'] as Record<string, unknown>;
    const roles = Array.isArray(u['roles']) ? (u['roles'] as unknown[]) : [];
    const isVendor = roles.some((r) => typeof r === 'string' && r === 'vendor');

    return {
      id: typeof u['id'] === 'number' ? u['id'] : 0,
      token: d['access_token'] as string,
      refresh_token: typeof d['refresh_token'] === 'string' ? (d['refresh_token'] as string) : undefined,
      first_name: typeof u['first_name'] === 'string' ? (u['first_name'] as string) : '',
      last_name: typeof u['last_name'] === 'string' ? (u['last_name'] as string) : '',
      email: typeof u['email'] === 'string' ? (u['email'] as string) : '',
      phone: typeof u['phone'] === 'string' ? (u['phone'] as string) : '',
      is_vendor: isVendor,
      is_store_active: u['is_store_active'] === true,
      is_store_approved: u['is_store_approved'] === true,
      is_phone_verified: u['is_phone_verified'] === true,
      // Avatar: v3 emits `avatar_url` (UserSerializer::publicProfile); the
      // mobile single_user shape + /account + /settings templates bind
      // `avatar`. Persist it at login so the avatar shows from the cached
      // 'user' blob without a follow-up profile fetch. Empty string when the
      // user has no avatar so the <img> fallback (avatar-placeholder) applies.
      avatar: typeof u['avatar_url'] === 'string' ? (u['avatar_url'] as string) : '',
    };
  }

  // --- Path 2: legacy passthrough (has id + token already) -------
  // MobileNetworkAdapter fell through to NetworkService; the legacy
  // backend already returned the right shape. Preserve it verbatim
  // so emergency rollback of the routing flag works without code
  // changes here.
  if (typeof d['id'] === 'number' && typeof d['token'] === 'string') {
    return d as unknown as LegacyUserShape;
  }

  // --- Path 3: unrecognized shape --------------------------------
  // Don't fabricate a legacy shape from unknown input. Return null
  // and let the caller decide (currently: store response.data
  // verbatim, preserving today's behaviour for unexpected payloads).
  return null;
}

function isPlainObject(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}
