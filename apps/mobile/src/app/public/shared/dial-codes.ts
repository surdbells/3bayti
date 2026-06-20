/**
 * Shared dial-code list + dial->ISO mapping for the phone-first auth flow.
 *
 * Used by the register page (Step 1 phone) and the reset page (Phone mode).
 * Mirrors the dial codes the existing register-request transform maps, plus
 * a flag glyph for the lightweight picker sheet (same pattern profile uses).
 *
 * 3bayti is UAE-first but built with multi-country headroom; the list below
 * covers the GCC + a few common Arab markets. Any dial code not in the
 * DIAL_TO_ISO table falls back to 'AE' (the operational default, matching
 * register-request.transform.ts).
 */

export interface DialCode {
  /** Dial prefix, e.g. "+971". */
  code: string;
  /** ISO 3166-1 alpha-2, e.g. "AE". */
  iso: string;
  /** Display name. */
  name: string;
  /** Flag emoji glyph. */
  flag: string;
}

export const DIAL_CODES: DialCode[] = [
  { code: '+971', iso: 'AE', name: 'United Arab Emirates', flag: '🇦🇪' },
  { code: '+966', iso: 'SA', name: 'Saudi Arabia', flag: '🇸🇦' },
  { code: '+974', iso: 'QA', name: 'Qatar', flag: '🇶🇦' },
  { code: '+973', iso: 'BH', name: 'Bahrain', flag: '🇧🇭' },
  { code: '+965', iso: 'KW', name: 'Kuwait', flag: '🇰🇼' },
  { code: '+968', iso: 'OM', name: 'Oman', flag: '🇴🇲' },
  { code: '+20',  iso: 'EG', name: 'Egypt', flag: '🇪🇬' },
  { code: '+962', iso: 'JO', name: 'Jordan', flag: '🇯🇴' },
  { code: '+961', iso: 'LB', name: 'Lebanon', flag: '🇱🇧' },
];

const DIAL_TO_ISO: Record<string, string> = DIAL_CODES.reduce(
  (acc, c) => { acc[c.code] = c.iso; return acc; },
  {} as Record<string, string>,
);

/** Map a dial prefix ("+971") to its ISO alpha-2 ("AE"); defaults to "AE". */
export function mapDialToIso(dial: string | undefined): string {
  if (dial && DIAL_TO_ISO[dial]) {
    return DIAL_TO_ISO[dial];
  }
  return 'AE';
}
