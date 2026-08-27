/**
 * Transform a legacy register page payload into the v3 RegisterInput
 * shape accepted by POST /v3/auth/register.
 *
 * Why this file exists
 * ====================
 * The mobile register page collects a legacy-shaped object:
 *
 *   {
 *     first_name, last_name, email, phone, password,
 *     confirm_password,            // client-side only, drop
 *     countryCode: "+971",         // wrong key + wrong format for v3
 *     accepted_terms: true         // client-side only, drop
 *   }
 *
 * v3's RegisterInput DTO (apps/api/src/Http/Controllers/Auth/Dto/
 * RegisterInput.php) expects:
 *
 *   {
 *     email, phone, password,
 *     country_code: "AE",          // snake_case, ISO 3166-1 alpha-2
 *     first_name, last_name        // optional, may be null
 *   }
 *
 * The mismatches that require translation:
 *   - confirm_password / accepted_terms: dropped (client-side concerns)
 *   - countryCode -> country_code: snake_case rename
 *   - "+971" -> "AE": dial code -> ISO alpha-2
 *   - phone format: v3 requires E.164 ("+\d{7,19}"); legacy mobile may
 *     send digits-only or with country prefix. Normalisation handled
 *     downstream by v3's RegisterInput constructor (strips whitespace,
 *     hyphens, parens) but the leading + must be present.
 *
 * Why this isn't shared with the login transform
 * ===============================================
 * Login transforms a RESPONSE; this transforms a REQUEST. They share
 * no logic, no shape, no direction. Putting them in the same file
 * (e.g. core/http/auth-shape.ts) would couple unrelated code.
 *
 * Country code mapping
 * ====================
 * 3bayti is currently UAE-only but the codebase is built with multi-
 * country headroom. The mapping below covers the dial codes the
 * existing UI exposes, primarily "+971" (UAE). Any unknown dial
 * code falls back to "AE" because:
 *   1. v3 validates country_code as exactly 2 uppercase letters; "" or
 *      a malformed value would 422.
 *   2. AE is the operational default; misclassifying a foreign user
 *      as UAE-based is preferable to failing registration outright.
 *   3. v3's RegisterInput defaults to "AE" too when the field is
 *      empty (per its constructor default).
 *
 * If the UI ever adds a country picker that emits proper ISO codes
 * directly (e.g. "AE", "SA", "EG"), the mapping below should accept
 * them as-is via the IS_ISO_CODE guard at the top of the function.
 */

const DIAL_CODE_TO_ISO: Record<string, string> = {
  '+971': 'AE', // UAE
  '+966': 'SA', // Saudi Arabia
  '+974': 'QA', // Qatar
  '+973': 'BH', // Bahrain
  '+965': 'KW', // Kuwait
  '+968': 'OM', // Oman
  '+20': 'EG',  // Egypt
  '+962': 'JO', // Jordan
  '+961': 'LB', // Lebanon
};

const ISO_CODE_REGEX = /^[A-Z]{2}$/;

/** Legacy register form shape collected by register.page.ts. */
export interface LegacyRegisterBody {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  password: string;
  confirm_password?: string;
  countryCode?: string;
  accepted_terms?: boolean;
}

/** v3 RegisterInput shape; matches apps/api/.../Dto/RegisterInput.php. */
export interface V3RegisterBody {
  email: string;
  phone: string;
  password: string;
  country_code: string;
  first_name: string | null;
  last_name: string | null;
}

/**
 * Translate a legacy register body to a v3 register body.
 *
 * Behaviour:
 *   - Required fields (email, phone, password) forwarded as-is.
 *   - first_name / last_name: empty strings coerced to null to match
 *     v3's nullable typing; non-empty strings passed through.
 *   - countryCode: dial-code mapped to ISO (AE default); existing
 *     ISO codes passed through; anything unrecognised defaults to AE.
 *   - confirm_password, accepted_terms: dropped (client-side concerns).
 *
 * Does NOT validate. Validation is the caller's responsibility
 * (already implemented in register.page.ts's user_register handler).
 */
export function transformLegacyRegisterRequest(legacy: LegacyRegisterBody): V3RegisterBody {
  return {
    email: legacy.email,
    phone: legacy.phone,
    password: legacy.password,
    country_code: mapCountryCode(legacy.countryCode),
    first_name: legacy.first_name.length > 0 ? legacy.first_name : null,
    last_name: legacy.last_name.length > 0 ? legacy.last_name : null,
  };
}

function mapCountryCode(raw: string | undefined): string {
  if (raw === undefined || raw === null || raw.length === 0) {
    return 'AE';
  }
  // Already ISO alpha-2, pass through.
  if (ISO_CODE_REGEX.test(raw)) {
    return raw;
  }
  // Try dial-code lookup.
  const mapped = DIAL_CODE_TO_ISO[raw];
  if (mapped !== undefined) {
    return mapped;
  }
  // Fallback. Logged for visibility, if this fires in production
  // we'd see "+44" or similar unmapped codes in the console and can
  // add them to the table.
  console.warn(`[register-request.transform] unrecognised countryCode "${raw}"; defaulting to AE`);
  return 'AE';
}
