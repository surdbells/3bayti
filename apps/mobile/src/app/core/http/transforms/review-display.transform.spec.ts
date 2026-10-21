import { CATALOG_RESPONSE_TRANSFORMS, transformReviewDisplayResponse } from './catalog-response.transforms';
import { installSafariDateParsing } from '../../utils/safari-date.testing';

/**
 * transformReviewDisplayResponse feeds every mobile review screen (store
 * reviews, the storefront's vendor-reviews page, My Reviews). It must emit the
 * names those templates bind and dates that survive iOS's stricter parser.
 */
describe('transformReviewDisplayResponse', () => {
  // ReviewSerializer::publicShape row (v3 sends star as a string).
  const row = {
    id: 7,
    product_id: null,
    product_name: null,
    reviewer: 'sara',
    star: '4',
    title: null,
    comment: 'Beautiful stitching, fast delivery.',
    vendor_reply: null,
    is_verified: true,
    helpful_count: 3,
    created_at: '2026-10-21T12:34:56+00:00',
  };

  const one = (input: unknown): Record<string, unknown> =>
    (transformReviewDisplayResponse([input]) as Record<string, unknown>[])[0];

  it('is registered for both review list route keys', () => {
    expect(CATALOG_RESPONSE_TRANSFORMS['GET /vendors/:vendorId/reviews']).toBe(transformReviewDisplayResponse);
    expect(CATALOG_RESPONSE_TRANSFORMS['GET /me/reviews']).toBe(transformReviewDisplayResponse);
  });

  it('maps the v3 fields onto the names the review templates bind', () => {
    const r = one(row);
    expect(r['star']).toBe(4);
    expect(r['rating']).toBe(4);
    expect(r['author']).toBe('sara');
    expect(r['customer']).toBe('sara');
    expect(r['authorInitial']).toBe('S');
    expect(r['text']).toBe('Beautiful stitching, fast delivery.');
    expect(r['comment']).toBe('Beautiful stitching, fast delivery.');
    expect(r['title']).toBe('');
    expect(r['helpfulCount']).toBe(3);
    expect(r['verified']).toBeTrue();
    expect(r['createdAtMs']).toBe(Date.UTC(2026, 9, 21, 12, 34, 56));
    expect(typeof r['relativeDate']).toBe('string');
    expect((r['relativeDate'] as string).length).toBeGreaterThan(0);
  });

  it('clamps and defaults bad numbers', () => {
    const r = one({ ...row, star: 'x', helpful_count: null, reviewer: null });
    expect(r['rating']).toBe(0);
    expect(r['helpfulCount']).toBe(0);
    expect(r['author']).toBe('');
    expect(r['authorInitial']).toBe('');
    expect(one({ ...row, star: 9 })['rating']).toBe(5);
  });

  it('returns [] for a non-array payload so a template @for never iterates an object', () => {
    expect(transformReviewDisplayResponse({ items: [row] })).toEqual([]);
    expect(transformReviewDisplayResponse('nope')).toEqual([]);
    expect(transformReviewDisplayResponse([])).toEqual([]);
  });

  describe('on iOS (Safari date parsing)', () => {
    let restore: () => void;
    beforeEach(() => (restore = installSafariDateParsing()));
    afterEach(() => restore());

    it('keeps a usable date + sort key for a MySQL-style timestamp', () => {
      const r = one({ ...row, created_at: '2026-10-21 12:34:56' });
      expect(r['createdAtMs']).toBe(new Date(2026, 9, 21, 12, 34, 56).getTime());
      expect((r['relativeDate'] as string).length).toBeGreaterThan(0);
    });

    it('keeps a usable date for a colon-less offset', () => {
      const r = one({ ...row, created_at: '2026-10-21T12:34:56+0000' });
      expect(r['createdAtMs']).toBe(Date.UTC(2026, 9, 21, 12, 34, 56));
    });

    it('degrades to an empty date (not a crash) for junk', () => {
      const r = one({ ...row, created_at: 'yesterday-ish' });
      expect(r['createdAtMs']).toBe(0);
      expect(r['relativeDate']).toBe('');
    });
  });
});
