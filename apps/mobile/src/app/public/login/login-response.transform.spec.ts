import { transformV3LoginResponse, type LegacyUserShape } from './login-response.transform';

/**
 * Unit tests for transformV3LoginResponse.
 *
 * Per M3.1.2 closeout: mobile CI runs type-check + build only,
 * not Jasmine. These tests are compile-checked but not executed
 * in CI. They DO run locally with `pnpm --filter mobile test`
 * once the test runner is set up (M4 hardening).
 *
 * Despite not running, the file is valuable as:
 *   - executable documentation of the transform's contract
 *   - regression catch when the transform is next modified
 *   - precise tests for the local `ng test` path
 *
 * Coverage targets the three execution paths in the transform:
 *   1. v3 shape (access_token + user)
 *   2. legacy passthrough (id + token)
 *   3. unrecognized shape -> null
 * Plus the edge cases that broke during M3.1.2 review:
 *   - roles array empty -> is_vendor false
 *   - vendor + customer roles -> is_vendor true
 *   - missing optional fields (no first_name) -> empty strings, not undefined
 */
describe('transformV3LoginResponse', () => {
  describe('v3 shape', () => {
    it('maps a standard v3 customer login to legacy shape', () => {
      const v3 = {
        access_token: 'eyJ...access',
        access_token_expires_at: '2026-05-15T12:00:00+00:00',
        refresh_token: 'eyJ...refresh',
        refresh_token_expires_at: '2026-05-22T12:00:00+00:00',
        user: {
          id: 42,
          email: 'jane@example.com',
          phone: '+971501234567',
          country_code: 'AE',
          first_name: 'Jane',
          last_name: 'Doe',
          gender: null,
          dob: null,
          locale: 'en',
          timezone: 'Asia/Dubai',
          is_phone_verified: true,
          is_email_verified: false,
          roles: ['customer'],
          is_store_approved: false,
          is_store_active: false,
          last_login_at: '2026-05-14T10:00:00+00:00',
        },
      };

      const result = transformV3LoginResponse(v3);

      expect(result).not.toBeNull();
      expect(result!.id).toBe(42);
      expect(result!.token).toBe('eyJ...access');
      expect(result!.refresh_token).toBe('eyJ...refresh');
      expect(result!.first_name).toBe('Jane');
      expect(result!.last_name).toBe('Doe');
      expect(result!.email).toBe('jane@example.com');
      expect(result!.phone).toBe('+971501234567');
      expect(result!.is_vendor).toBe(false);
      expect(result!.is_store_active).toBe(false);
      expect(result!.is_store_approved).toBe(false);
    });

    it('derives is_vendor=true when roles contains vendor', () => {
      const v3 = {
        access_token: 't',
        refresh_token: 'r',
        user: {
          id: 7,
          email: 'v@x.com',
          phone: '+971500000000',
          first_name: 'V',
          last_name: 'V',
          roles: ['customer', 'vendor'],
          is_store_active: true,
          is_store_approved: true,
        },
      };

      const result = transformV3LoginResponse(v3);

      expect(result!.is_vendor).toBe(true);
      expect(result!.is_store_active).toBe(true);
      expect(result!.is_store_approved).toBe(true);
    });

    it('returns empty strings (not undefined) for missing optional name fields', () => {
      // Per RegisterInput DTO, first_name/last_name are nullable in v3.
      // The legacy single_user defaults them to "" (empty string), so
      // the transform must coerce null -> "" to avoid breaking
      // string-typed code like header.innerText = single_user.first_name.
      const v3 = {
        access_token: 't',
        refresh_token: 'r',
        user: {
          id: 1,
          email: 'a@b.com',
          phone: '+971500000000',
          first_name: null,
          last_name: null,
          roles: ['customer'],
          is_store_active: false,
          is_store_approved: false,
        },
      };

      const result = transformV3LoginResponse(v3);

      expect(result!.first_name).toBe('');
      expect(result!.last_name).toBe('');
    });

    it('handles refresh_token absence gracefully', () => {
      // Defensive: v3 always sends refresh_token today, but if an
      // upstream change ever omits it the transform must not throw.
      const v3 = {
        access_token: 't',
        user: {
          id: 1,
          email: 'a@b.com',
          phone: '+971500000000',
          first_name: 'A',
          last_name: 'B',
          roles: [],
          is_store_active: false,
          is_store_approved: false,
        },
      };

      const result = transformV3LoginResponse(v3);

      expect(result!.refresh_token).toBeUndefined();
      expect(result!.token).toBe('t');
    });

    it('treats empty roles array as is_vendor=false', () => {
      const v3 = {
        access_token: 't',
        refresh_token: 'r',
        user: {
          id: 1,
          email: 'a@b.com',
          phone: '+971500000000',
          first_name: 'A',
          last_name: 'B',
          roles: [],
          is_store_active: false,
          is_store_approved: false,
        },
      };

      const result = transformV3LoginResponse(v3);
      expect(result!.is_vendor).toBe(false);
    });
  });

  describe('legacy passthrough', () => {
    it('returns input unchanged when shape already has id + token (string)', () => {
      // This is what NetworkService returns when MobileNetworkAdapter
      // falls through to legacy. The transform must preserve every
      // field — including legacy-only ones like billing_*, avatar,
      // location — so emergency rollback (flip target back to 'old')
      // doesn't require touching this file too.
      const legacy: Record<string, unknown> = {
        id: 99,
        token: 'legacy-token',
        first_name: 'Legacy',
        last_name: 'User',
        email: 'l@example.com',
        phone: '+971501112233',
        is_vendor: false,
        is_store_active: false,
        is_store_approved: false,
        billing_name: 'Legacy User',
        billing_street: '123 Sheikh Zayed Rd',
        location: 'Dubai',
        avatar: 'https://cdn.example.com/avatar.jpg',
      };

      const result = transformV3LoginResponse(legacy);

      // Returned as-is, preserving extras
      expect(result).toBe(legacy as unknown as LegacyUserShape);
      expect(result!['billing_name']).toBe('Legacy User');
      expect(result!['avatar']).toBe('https://cdn.example.com/avatar.jpg');
    });
  });

  describe('unrecognized shapes', () => {
    it('returns null for null input', () => {
      expect(transformV3LoginResponse(null)).toBeNull();
    });

    it('returns null for undefined input', () => {
      expect(transformV3LoginResponse(undefined)).toBeNull();
    });

    it('returns null for an array', () => {
      expect(transformV3LoginResponse([1, 2, 3])).toBeNull();
    });

    it('returns null for a primitive', () => {
      expect(transformV3LoginResponse('a string')).toBeNull();
      expect(transformV3LoginResponse(42)).toBeNull();
    });

    it('returns null when object has neither v3 nor legacy markers', () => {
      // E.g. an unexpected error shape that slipped past the adapter.
      // Returning null tells the caller "store response.data as-is",
      // which preserves today's behaviour rather than masking the
      // anomaly with a fake legacy shape.
      const weird = { foo: 'bar', baz: 42 };
      expect(transformV3LoginResponse(weird)).toBeNull();
    });

    it('returns null when access_token is present but user is missing', () => {
      // Defensive: don't return a half-built object.
      const partial = { access_token: 't', refresh_token: 'r' };
      expect(transformV3LoginResponse(partial)).toBeNull();
    });

    it('returns null when id is present but token is missing', () => {
      // Legacy passthrough requires BOTH id and token (string).
      const partial = { id: 1, first_name: 'A' };
      expect(transformV3LoginResponse(partial)).toBeNull();
    });
  });
});
