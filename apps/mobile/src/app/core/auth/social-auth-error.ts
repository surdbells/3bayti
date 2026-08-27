/**
 * Shared parsing + user-facing messaging for social (Google / Apple) sign-in
 * failures raised by @capacitor-firebase/authentication.
 *
 * Why this exists
 * ---------------
 * The login / register / settings screens all used to `catch (err)` and show a
 * single generic "Sign in failed" toast while the real reason went only to
 * console.error, invisible on a shipped device. That made the intermittent
 * "fails on some devices" reports impossible to diagnose remotely.
 *
 * The most common non-obvious cause on Android is a Google Play Services
 * DEVELOPER_ERROR (code 10): the certificate the app is signed with on that
 * device isn't registered as a SHA-1/SHA-256 fingerprint in the Firebase
 * project (typical when Play App Signing re-signs the app, or a side-loaded
 * build uses a different key). It presents as a hard failure only on the
 * affected devices, exactly the reported symptom.
 *
 * This helper classifies the error, maps the actionable ones to a clear
 * message, and, crucially, appends the raw code to the generic fallback so a
 * user can screenshot it and support can act on it.
 */

export interface SocialAuthErrorInfo {
  /** User dismissed the native sheet, callers should stay silent (no toast). */
  cancelled: boolean;
  /** Best-effort machine code: a Firebase `auth/...` slug or a native code/label. */
  code: string;
  /** Raw error message, kept for structured logging. */
  rawMessage: string;
  /** Coarse category used to pick a user-facing message. */
  category: SocialAuthErrorCategory;
}

export type SocialAuthErrorCategory =
  | 'cancelled'
  | 'network'
  | 'account-exists'
  | 'config'
  | 'disabled'
  | 'unknown';

/** i18n keys the categories map to (config left in the app's en/ar bundles). */
const CATEGORY_KEYS: Record<Exclude<SocialAuthErrorCategory, 'cancelled' | 'unknown'>, string> = {
  network: 'social_err_network',
  'account-exists': 'social_err_account_exists',
  config: 'social_err_config',
  disabled: 'social_err_disabled',
};

/** Substrings/codes that mean "the user backed out", never shown as an error. */
const CANCEL_TOKENS = [
  'cancel',
  'canceled',
  'cancelled',
  'popup-closed',
  'user_cancelled',
  'the user canceled',
  '1001', // Apple cancel
  '12501', // Google Play Services SIGN_IN_CANCELLED
];

/**
 * Normalise an unknown thrown value into a {@link SocialAuthErrorInfo}. Reads a
 * `.code` when present (Firebase JS uses `auth/...`; the native bridge may pass
 * a numeric Play Services code) and falls back to recovering a recognisable
 * token from the message (native errors often embed e.g. "10:" / "ApiException:
 * 10" / "DEVELOPER_ERROR").
 */
export function parseSocialAuthError(err: unknown): SocialAuthErrorInfo {
  const anyErr = err as { code?: unknown; message?: unknown } | null;
  const rawMessage =
    anyErr && typeof anyErr === 'object' && anyErr.message != null
      ? String(anyErr.message)
      : String(err ?? '');
  const rawCode =
    anyErr && typeof anyErr === 'object' && anyErr.code != null ? String(anyErr.code) : '';

  const haystack = `${rawCode} ${rawMessage}`.toLowerCase();

  const cancelled = CANCEL_TOKENS.some((t) => haystack.includes(t));

  // Prefer the explicit code; otherwise pull a known shape out of the message.
  let code = rawCode;
  if (!code) {
    const match =
      rawMessage.match(/\b(auth\/[a-z-]+)\b/i) ||
      rawMessage.match(/\b(DEVELOPER_ERROR|NETWORK_ERROR|INTERNAL_ERROR|SIGN_IN_FAILED|SIGN_IN_REQUIRED)\b/i) ||
      rawMessage.match(/ApiException:\s*(\d{1,5})/i) ||
      rawMessage.match(/\b(1[0-9]|125\d{2})\b/);
    code = match ? match[1] : '';
  }

  const category: SocialAuthErrorCategory = cancelled
    ? 'cancelled'
    : classify(code, haystack);

  return { cancelled, code, rawMessage, category };
}

function classify(code: string, haystack: string): SocialAuthErrorCategory {
  const c = code.toLowerCase();
  const both = `${c} ${haystack}`;

  if (both.includes('network') || wordCode(c, '7') || both.includes('timeout')) {
    return 'network';
  }
  if (both.includes('account-exists-with-different-credential') || both.includes('account-exists')) {
    return 'account-exists';
  }
  // DEVELOPER_ERROR (10) / operation-not-allowed / configuration / api-not-available:
  // the user can't fix these, they're a Firebase/console setup problem (most
  // often an unregistered signing SHA), so surface a config-specific message.
  if (
    both.includes('developer_error') ||
    both.includes('operation-not-allowed') ||
    both.includes('configuration') ||
    both.includes('api_not_connected') ||
    both.includes('api-not-available') ||
    wordCode(c, '10')
  ) {
    return 'config';
  }
  if (both.includes('user-disabled') || both.includes('user_disabled')) {
    return 'disabled';
  }
  return 'unknown';
}

/** Exact whole-token numeric match so "10" doesn't match inside "1001"/"12500". */
function wordCode(code: string, target: string): boolean {
  return code === target || new RegExp(`\\b${target}\\b`).test(code);
}

/**
 * Build the message to show the user. Actionable categories get a clear
 * explanation; everything else falls back to the app's generic string with the
 * raw code appended so it can be reported (e.g. "Sign in failed… (10)").
 *
 * @param t translate function (pass `(k) => i18n.t(k)`).
 */
export function socialAuthErrorMessage(
  info: SocialAuthErrorInfo,
  t: (key: string) => string,
): string {
  const base =
    info.category !== 'cancelled' && info.category !== 'unknown'
      ? t(CATEGORY_KEYS[info.category])
      : t('text_social_signin_failed');
  // Always append the raw code when we have one, on a shipped device the
  // console log isn't reachable, so the visible code is the only way a user
  // can report (and support can act on) the real cause.
  return info.code ? `${base} (${info.code})` : base;
}
