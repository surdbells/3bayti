import {
  transformLegacyRegisterRequest,
  type LegacyRegisterBody,
} from './register-request.transform';

/**
 * Unit tests for transformLegacyRegisterRequest.
 *
 * Per the M3.1.2 closeout: mobile CI runs type-check + build only,
 * not Jasmine. These tests are compile-checked but not executed
 * in CI. They DO run locally with `pnpm --filter @3bayti/mobile test`
 * once the test runner is set up (M4 hardening).
 *
 * Coverage targets:
 *   - Standard UAE flow (+971 -> AE)
 *   - Non-UAE dial codes in the mapping table
 *   - Empty / missing countryCode -> AE default
 *   - Unrecognised dial code -> AE fallback with warn
 *   - Already-ISO code passes through unchanged
 *   - Empty first/last name -> null (matches v3 nullable typing)
 *   - confirm_password / accepted_terms dropped from output
 */
describe('transformLegacyRegisterRequest', () => {
  const baseLegacy: LegacyRegisterBody = {
    first_name: 'Jane',
    last_name: 'Doe',
    email: 'jane@example.com',
    phone: '+971501234567',
    password: 'secret123',
    confirm_password: 'secret123',
    countryCode: '+971',
    accepted_terms: true,
  };

  it('maps a standard UAE registration to v3 shape', () => {
    const result = transformLegacyRegisterRequest(baseLegacy);

    expect(result.email).toBe('jane@example.com');
    expect(result.phone).toBe('+971501234567');
    expect(result.password).toBe('secret123');
    expect(result.country_code).toBe('AE');
    expect(result.first_name).toBe('Jane');
    expect(result.last_name).toBe('Doe');
  });

  it('drops confirm_password and accepted_terms', () => {
    const result = transformLegacyRegisterRequest(baseLegacy);

    // Type-level: result must not have these keys.
    // Runtime: defensive assertion in case object spread accidentally
    // included them.
    expect((result as unknown as Record<string, unknown>)['confirm_password']).toBeUndefined();
    expect((result as unknown as Record<string, unknown>)['accepted_terms']).toBeUndefined();
    expect((result as unknown as Record<string, unknown>)['countryCode']).toBeUndefined();
  });

  it('maps non-UAE Gulf dial codes to ISO', () => {
    const cases: Array<[string, string]> = [
      ['+966', 'SA'],
      ['+974', 'QA'],
      ['+973', 'BH'],
      ['+965', 'KW'],
      ['+968', 'OM'],
    ];

    for (const [dial, iso] of cases) {
      const result = transformLegacyRegisterRequest({
        ...baseLegacy,
        countryCode: dial,
      });
      expect(result.country_code).toBe(iso);
    }
  });

  it('defaults to AE for missing countryCode', () => {
    const result = transformLegacyRegisterRequest({
      ...baseLegacy,
      countryCode: undefined,
    });
    expect(result.country_code).toBe('AE');
  });

  it('defaults to AE for empty-string countryCode', () => {
    const result = transformLegacyRegisterRequest({
      ...baseLegacy,
      countryCode: '',
    });
    expect(result.country_code).toBe('AE');
  });

  it('passes through ISO alpha-2 codes unchanged', () => {
    // If a future UI sends an actual ISO code (not a dial code), keep it.
    const result = transformLegacyRegisterRequest({
      ...baseLegacy,
      countryCode: 'EG',
    });
    expect(result.country_code).toBe('EG');
  });

  it('falls back to AE with warn for unrecognised dial codes', () => {
    const warnSpy = spyOn(console, 'warn');
    const result = transformLegacyRegisterRequest({
      ...baseLegacy,
      countryCode: '+44', // not in mapping
    });
    expect(result.country_code).toBe('AE');
    expect(warnSpy).toHaveBeenCalled();
  });

  it('coerces empty first_name / last_name to null', () => {
    // v3 RegisterInput accepts ?string (nullable). Empty strings would
    // be sent as-is otherwise; coerce to null for cleaner DB rows.
    const result = transformLegacyRegisterRequest({
      ...baseLegacy,
      first_name: '',
      last_name: '',
    });
    expect(result.first_name).toBeNull();
    expect(result.last_name).toBeNull();
  });
});
