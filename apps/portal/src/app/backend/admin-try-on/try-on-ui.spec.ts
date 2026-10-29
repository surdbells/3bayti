import {
  Translate,
  canEnable,
  defaultFilterTab,
  eligibilityBadge,
  eligibilityFromError,
  eligibilityHintKey,
  enabledDelta,
  offReasonKeys,
  reasonMessageKey,
  showProductStatus,
  summarizeBulk,
  toggleErrorMessage,
  toggleState,
} from './try-on-ui';
import { TryOnStatus } from '../../services/try-on-admin.service';

/** The en.json copy these helpers use, interpolated like I18nService.t. */
const EN: Record<string, string> = {
  'tryon.bulk.enabled': '{{count}} enabled',
  'tryon.bulk.disabled': '{{count}} disabled',
  'tryon.bulk.summary': 'Try-On updated: {{updated}}.',
  'tryon.bulk.summary_skipped': 'Try-On updated: {{updated}}, {{skipped}} skipped: {{reasons}}',
  'tryon.skip.unsupported_format': '{{count}} GIF or unsupported image',
  'tryon.skip.no_image': '{{count}} no image',
  'tryon.skip.external_image': '{{count}} image needs re-upload',
  'tryon.skip.not_found': '{{count}} not found',
  'tryon.list_separator': ', ',
  'tryon.reason.unsupported_format': 'GIF reason',
  'tryon.reason.no_image': 'No-image reason',
  'tryon.reason.ineligible': 'Ineligible reason',
  'tryon.toggle.error': 'Generic toggle error',
  'tryon.toggle.not_found': 'Gone',
  'tryon.network_error': 'Network',
};
const t: Translate = (key, params) =>
  (EN[key] ?? key).replace(/\{\{\s*(\w+)\s*\}\}/g, (_, m) => (params?.[m] != null ? String(params[m]) : ''));

const status = (patch: Partial<TryOnStatus> = {}): TryOnStatus => ({
  available: true, tryon_flag: true, ai_enabled: true, enabled_products: 0, eligible_formats: ['jpeg', 'png', 'webp'], ...patch,
});

const ineligible422 = (reason: string | null, message = 'Product image is a GIF; Try-On needs JPEG, PNG or WebP.') => ({
  status: 422,
  error: { error: { code: 'TRYON_IMAGE_INELIGIBLE', message, details: reason ? { reason } : {} } },
});

describe('Try-On UI logic', () => {
  describe('eligibility badge / hint', () => {
    it('labels each eligibility state', () => {
      expect(eligibilityBadge({ eligible: true, reason: null })).toEqual({ labelKey: 'tryon.elig.eligible', badgeClass: 'ax-badge ax-badge-success' });
      expect(eligibilityBadge({ eligible: false, reason: 'unsupported_format' }).labelKey).toBe('tryon.elig.unsupported_format');
      expect(eligibilityBadge({ eligible: false, reason: 'no_image' }).labelKey).toBe('tryon.elig.no_image');
      expect(eligibilityBadge({ eligible: false, reason: 'external_image' }).labelKey).toBe('tryon.elig.external_image');
      expect(eligibilityBadge({ eligible: false, reason: null }).labelKey).toBe('tryon.elig.ineligible');
      expect(eligibilityBadge(null).labelKey).toBe('tryon.elig.unknown');
    });

    it('explains the JPEG/PNG/WebP rule, or the specific reason', () => {
      expect(eligibilityHintKey({ eligible: true, reason: null })).toBe('tryon.rule');
      expect(eligibilityHintKey(null)).toBe('tryon.rule');
      expect(eligibilityHintKey({ eligible: false, reason: 'external_image' })).toBe('tryon.reason.external_image');
      expect(reasonMessageKey(null)).toBe('tryon.reason.ineligible');
    });
  });

  describe('switch state', () => {
    const gif = { eligible: false, reason: 'unsupported_format' as const };

    it('locks enabling an ineligible product, with the reason', () => {
      expect(toggleState(false, gif, true, false)).toEqual({ disabled: true, hintKey: 'tryon.reason.unsupported_format' });
      expect(canEnable(gif)).toBeFalse();
    });

    it('always allows disabling, even when the image is ineligible', () => {
      expect(toggleState(true, gif, true, false)).toEqual({ disabled: false, hintKey: null });
    });

    it('allows enabling eligible or unknown products (the server re-validates)', () => {
      expect(toggleState(false, { eligible: true, reason: null }, true, false).disabled).toBeFalse();
      expect(toggleState(false, null, true, false).disabled).toBeFalse();
    });

    it('is read-only without products.edit, and locked while saving', () => {
      expect(toggleState(true, null, false, false)).toEqual({ disabled: true, hintKey: 'tryon.toggle.read_only' });
      expect(toggleState(false, null, true, true)).toEqual({ disabled: true, hintKey: null });
    });
  });

  it('defaults the tab to Enabled only when something is enabled', () => {
    expect(defaultFilterTab(status({ enabled_products: 3 }))).toBe('true');
    expect(defaultFilterTab(status({ enabled_products: 0 }))).toBe('');
    expect(defaultFilterTab(null)).toBe('');
  });

  it('names the env flags that keep Try-On off', () => {
    expect(offReasonKeys(status())).toEqual([]);
    expect(offReasonKeys(null)).toEqual([]);
    expect(offReasonKeys(status({ available: false, tryon_flag: false }))).toEqual(['tryon.off.flag_tryon']);
    expect(offReasonKeys(status({ available: false, tryon_flag: false, ai_enabled: false }))).toEqual(['tryon.off.flag_tryon', 'tryon.off.flag_ai']);
    // both flags on but still unavailable → the API key is missing
    expect(offReasonKeys(status({ available: false }))).toEqual(['tryon.off.flag_key']);
  });

  it('computes the enabled-count delta', () => {
    expect(enabledDelta(false, true)).toBe(1);
    expect(enabledDelta(true, false)).toBe(-1);
    expect(enabledDelta(true, true)).toBe(0);
  });

  it('badges only non-live product statuses', () => {
    expect(showProductStatus('active')).toBeFalse();
    expect(showProductStatus('published')).toBeFalse();
    expect(showProductStatus('')).toBeFalse();
    expect(showProductStatus('draft')).toBeTrue();
  });

  describe('bulk summary', () => {
    it('reports a clean run as success', () => {
      expect(summarizeBulk({ updated: [1, 2, 3], skipped: [] }, true, t))
        .toEqual({ ok: true, message: 'Try-On updated: 3 enabled.' });
      expect(summarizeBulk({ updated: [1], skipped: [] }, false, t).message).toBe('Try-On updated: 1 disabled.');
    });

    it('groups skipped products by reason ("12 enabled, 3 skipped: …")', () => {
      const res = summarizeBulk({
        updated: Array.from({ length: 12 }, (_, i) => i + 1),
        skipped: [
          { id: 20, reason: 'no_image' },
          { id: 21, reason: 'unsupported_format' },
          { id: 22, reason: 'unsupported_format' },
        ],
      }, true, t);
      expect(res).toEqual({ ok: true, message: 'Try-On updated: 12 enabled, 3 skipped: 2 GIF or unsupported image, 1 no image' });
    });

    it('is an error when everything was skipped', () => {
      const res = summarizeBulk({ updated: [], skipped: [{ id: 5, reason: 'not_found' }, { id: 6, reason: 'external_image' }] }, true, t);
      expect(res.ok).toBeFalse();
      expect(res.message).toBe('Try-On updated: 0 enabled, 2 skipped: 1 image needs re-upload, 1 not found');
    });
  });

  describe('toggle errors', () => {
    it('shows the localized 422 reason (the API message is English-only), falling back to the API message', () => {
      expect(toggleErrorMessage(ineligible422('unsupported_format'), t)).toBe('GIF reason');
      expect(toggleErrorMessage(ineligible422('no_image', ''), t)).toBe('No-image reason');
      // unknown / missing reason: the API's message, else the generic localized one
      expect(toggleErrorMessage(ineligible422(null), t)).toBe('Product image is a GIF; Try-On needs JPEG, PNG or WebP.');
      expect(toggleErrorMessage(ineligible422('something_new', ''), t)).toBe('Ineligible reason');
    });

    it('handles 404, network failures and other API errors', () => {
      expect(toggleErrorMessage({ status: 404, error: { error: { code: 'NOT_FOUND', message: 'Product not found.' } } }, t)).toBe('Gone');
      expect(toggleErrorMessage({ status: 0 }, t)).toBe('Network');
      expect(toggleErrorMessage({ status: 500, error: { error: { code: 'X', message: 'Boom' } } }, t)).toBe('Boom');
      expect(toggleErrorMessage({ status: 500 }, t)).toBe('Generic toggle error');
    });

    it('derives the ineligible state a refused enable reports', () => {
      expect(eligibilityFromError(ineligible422('unsupported_format'))).toEqual({ eligible: false, reason: 'unsupported_format' });
      expect(eligibilityFromError(ineligible422(null))).toEqual({ eligible: false, reason: null });
      expect(eligibilityFromError({ status: 500 })).toBeNull();
    });
  });
});
