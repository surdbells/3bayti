import type { Locale } from '../i18n/locale.types';

/**
 * Active role flags for a user.
 *
 * Mirrors the strings emitted by UserSerializer::extractActiveRoles
 * in apps/api. A user can hold multiple roles; the consumer app
 * dispatches behaviour on whichever subset is relevant.
 */
export type UserRole = 'customer' | 'vendor' | 'admin' | 'finance' | 'support' | 'sub_admin';

/**
 * Public-profile shape of an authenticated user.
 *
 * Field-for-field mirror of apps/api UserSerializer::publicProfile().
 * Source of truth for the contract: see apps/api/src/Http/Serializers/UserSerializer.php.
 *
 * Why we don't reuse @3bayti/api-client types
 * --------------------------------------------
 * The api-client package's response type is `unknown` (it's a generic
 * client that doesn't know domain shapes). The auth domain is the
 * web app's, not the api-client's, so the types live here.
 */
export interface AuthUser {
  id: number;
  email: string;
  phone: string;
  country_code: string;
  first_name: string | null;
  last_name: string | null;
  gender: string | null;
  /** ISO 8601 date (YYYY-MM-DD), not a full timestamp. */
  dob: string | null;
  locale: Locale | null;
  timezone: string | null;
  is_phone_verified: boolean;
  is_email_verified: boolean;
  roles: UserRole[];
  is_store_approved: boolean;
  is_store_active: boolean;
  /** ISO 8601 datetime (ATOM format), or null if never logged in. */
  last_login_at: string | null;
  /** Public URL of the user's avatar, or null if none uploaded. */
  avatar_url: string | null;
}

/**
 * The token-pair envelope the API returns from login / confirm /
 * refresh / reset-confirm. Mirrors LoginController response shape.
 */
export interface TokenPair {
  access_token: string;
  access_token_expires_at: string; // ISO 8601 ATOM datetime
  refresh_token: string;
  refresh_token_expires_at: string; // ISO 8601 ATOM datetime
}

/**
 * Full login response, token pair + user.
 */
export interface LoginResponse extends TokenPair {
  user: AuthUser;
}

/**
 * Public-side login response, what the BFF returns to the browser.
 *
 * Diverges from LoginResponse: the BFF strips refresh_token from the
 * JSON body and parks it as an HttpOnly cookie so it never reaches
 * the browser's JS context. The browser only sees the access token
 * (kept in memory) and the user.
 *
 * refresh_token_expires_at is kept so the browser can warn the user
 * about session expiry if they go inactive long enough.
 */
export interface BffLoginResponse {
  access_token: string;
  access_token_expires_at: string;
  refresh_token_expires_at: string;
  user: AuthUser;
}

/**
 * Public-side refresh response, same shape as BffLoginResponse,
 * which is what the BFF returns when called via /auth-proxy/refresh.
 */
export type BffRefreshResponse = BffLoginResponse;

/**
 * Register endpoint response (unverified state, no tokens yet).
 *
 * Mirrors RegisterController response shape.
 */
export interface RegisterResponse {
  verification_id: string;
  user: {
    email: string;
    phone: string;
    country_code: string;
    is_phone_verified: false;
  };
}

/**
 * Reset password endpoint response, just a verification_id.
 *
 * Anti-enumeration: the API returns the same shape whether the email
 * is registered or not (fake `fake-...` prefix vid for unknown
 * emails). The frontend never needs to distinguish the two cases.
 */
export interface ResetResponse {
  verification_id: string;
}

/**
 * SendOTP (resend) response.
 */
export interface SendOtpResponse {
  verification_id: string;
}

/**
 * Logout response, always 204. Modelled as `void` so consumers don't
 * have to write `await ... as void`.
 */
export type LogoutResponse = void;

/* ===========================================================================
   Passwordless parity (#8), mirrors the mobile v3 passwordless flows.
   =========================================================================== */

/**
 * OTP-login send inputs. The channel discriminates phone vs email; the
 * BFF passes this straight through to /v3/auth/otp-login/send.
 *
 * Anti-enumeration: the API always returns a verification_id even for an
 * unknown identifier, so the UI never reveals whether an account exists -
 * a bad/unknown identifier only fails at the verify step.
 */
export type OtpLoginSendInput =
  | { channel: 'phone'; country_code: string; phone: string }
  | { channel: 'email'; email: string };

/** OTP-login send response, just a verification_id. */
export interface OtpLoginSendResponse {
  verification_id: string;
}

/** OTP-login verify inputs (verification_id + 6-digit code). */
export interface OtpLoginVerifyInput {
  verification_id: string;
  /** Exactly 6 digits. */
  code: string;
}

/**
 * Register step 1 (phone), initiate inputs. Returns a verification_id
 * for the phone OTP.
 */
export interface RegisterInitiateInput {
  /** E.164 with leading '+', e.g. '+971501234567'. */
  phone: string;
  /** ISO 3166-1 alpha-2; derived from the phone's dial code. */
  country_code: string;
}

/** Register step 1 response, phone-OTP verification_id. */
export interface RegisterInitiateResponse {
  verification_id: string;
}

/**
 * Register step 2 (verify phone) inputs. On success the API returns a
 * short-lived registration_token used to authorise the submit step.
 */
export interface RegisterVerifyPhoneInput {
  verification_id: string;
  /** Exactly 6 digits. */
  code: string;
}

/** Register step 2 response, registration_token. */
export interface RegisterVerifyPhoneResponse {
  registration_token: string;
}

/**
 * Register step 3 (details), submit inputs. The verified phone is
 * carried implicitly by the registration_token; we send the rest.
 */
export interface RegisterSubmitInput {
  registration_token: string;
  email: string;
  password: string;
  first_name?: string | null;
  last_name?: string | null;
}

/** Register step 3 response, email-OTP verification_id. */
export interface RegisterSubmitResponse {
  verification_id: string;
}

/**
 * Register step 4 (confirm email) inputs. On success the API returns a
 * full token-pair + user (same envelope as /confirm), so the BFF sets
 * the refresh cookie and the browser auto-logs-in.
 */
export interface RegisterConfirmEmailInput {
  verification_id: string;
  /** Exactly 6 digits. */
  code: string;
}

/** validate-phone inputs, best-effort availability probe. */
export interface ValidatePhoneInput {
  /** E.164 with leading '+', e.g. '+971501234567'. */
  phone: string;
}

/** validate-phone response, { phone, available }. */
export interface ValidatePhoneResponse {
  phone: string;
  /** false = already registered. */
  available: boolean;
}

/**
 * Reset request inputs, two-channel (email | phone), mirroring the
 * mobile reset flow. The BFF passes this straight to /v3/auth/reset.
 */
export type ResetRequestInput =
  | { channel: 'email'; email: string }
  | { channel: 'phone'; phone: string };

/**
 * Login form inputs.
 */
export interface LoginInput {
  email: string;
  password: string;
}

/**
 * Social sign-in input, the Firebase ID token plus the name fields the
 * popup surfaced (used only when the API is creating a brand-new account;
 * ignored for an existing linked identity). Sent to the BFF /auth-proxy/social,
 * which forwards it to /v3/auth/social.
 */
export interface SocialLoginInput {
  id_token: string;
  first_name?: string | null;
  last_name?: string | null;
}

/**
 * Register form inputs.
 *
 * Mirrors apps/api RegisterInput DTO. The Y.1-F register page submits
 * exactly these fields.
 */
export interface RegisterInput {
  email: string;
  /** E.164 with leading '+', e.g. '+971501234567'. */
  phone: string;
  password: string;
  /** ISO 3166-1 alpha-2; defaults to 'AE'. */
  country_code: string;
  first_name?: string | null;
  last_name?: string | null;
}

/**
 * OTP confirmation inputs.
 */
export interface ConfirmInput {
  verification_id: string;
  /** Exactly 6 digits. */
  code: string;
}

/**
 * Password reset confirmation inputs.
 */
export interface ResetConfirmInput {
  verification_id: string;
  /** Exactly 6 digits. */
  code: string;
  new_password: string;
}

/**
 * Stable error-code constants used by the auth UI.
 *
 * Mirrors apps/api Bayti\Api\Http\Errors\ErrorCodes for the subset
 * the auth pages consume. Other error codes pass through unmapped
 * and are presented via the global toast.
 */
export const AUTH_ERROR_CODES = {
  INVALID_CREDENTIALS: 'AUTH_INVALID_CREDENTIALS',
  ACCOUNT_INACTIVE: 'AUTH_ACCOUNT_INACTIVE',
  CONFLICT_EMAIL_TAKEN: 'CONFLICT_EMAIL_TAKEN',
  CONFLICT_PHONE_TAKEN: 'CONFLICT_PHONE_TAKEN',
  /* Account-link (phone-claim): the number is shared by >1 account, so the
     merge is refused and the user is sent to support. */
  PHONE_LINK_AMBIGUOUS: 'PHONE_LINK_AMBIGUOUS',
  OTP_RATE_LIMITED: 'OTP_RATE_LIMITED',
  OTP_PROVIDER_ERROR: 'OTP_PROVIDER_ERROR',
  OTP_INVALID_CODE: 'OTP_INVALID_CODE',
  OTP_VERIFICATION_FAILED: 'OTP_VERIFICATION_FAILED',
  PHONE_NOT_VERIFIED: 'AUTH_PHONE_NOT_VERIFIED',
  VALIDATION_FAILED: 'VALIDATION_FAILED',
  REFRESH_TOKEN_INVALID: 'AUTH_REFRESH_TOKEN_INVALID',
  REFRESH_TOKEN_EXPIRED: 'AUTH_REFRESH_TOKEN_EXPIRED',
  /* Passwordless parity (#8): the short-lived registration_token from
     register/verify-phone can expire before register/submit, the API
     emits AUTH_INVALID_TOKEN and the UI restarts the flow at step 1. */
  INVALID_TOKEN: 'AUTH_INVALID_TOKEN',
} as const;

export type AuthErrorCode = (typeof AUTH_ERROR_CODES)[keyof typeof AUTH_ERROR_CODES];
