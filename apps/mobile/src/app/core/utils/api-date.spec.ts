import { apiDateMs, formatRelativeDate, parseApiDate } from './api-date';
import { installSafariDateParsing } from './safari-date.testing';

/**
 * parseApiDate must give the SAME instant on iOS (Safari / WKWebView) as on
 * Android (Chrome). The "iOS" blocks swap the global Date for one that parses
 * strings like Safari does (see safari-date.testing.ts), which is what turned
 * review timestamps into Invalid Date on iPhones.
 */
describe('api-date', () => {
  const ATOM = '2026-10-21T12:34:56+00:00';
  const ATOM_MS = Date.UTC(2026, 9, 21, 12, 34, 56);

  describe('parseApiDate', () => {
    it('parses the ATOM timestamps the v3 API emits', () => {
      expect(parseApiDate(ATOM)?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate('2026-10-21T16:34:56+04:00')?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate('2026-10-21T12:34:56Z')?.getTime()).toBe(ATOM_MS);
    });

    it('parses offsets without a colon and short offsets', () => {
      expect(parseApiDate('2026-10-21T12:34:56+0000')?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate('2026-10-21T16:34:56+0400')?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate('2026-10-21T16:34:56+04')?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate('2026-10-21T07:04:56-0530')?.getTime()).toBe(ATOM_MS);
    });

    it('reads a MySQL "YYYY-MM-DD HH:mm:ss" as local time (what Chrome does)', () => {
      const expected = new Date(2026, 9, 21, 12, 34, 56).getTime();
      expect(parseApiDate('2026-10-21 12:34:56')?.getTime()).toBe(expected);
    });

    it('reads a bare "YYYY-MM-DD" as UTC midnight (ECMAScript rule)', () => {
      expect(parseApiDate('2026-10-21')?.getTime()).toBe(Date.UTC(2026, 9, 21));
    });

    it('keeps milliseconds from fractions of any length', () => {
      expect(parseApiDate('2026-10-21T12:34:56.123456Z')?.getTime()).toBe(ATOM_MS + 123);
      expect(parseApiDate('2026-10-21T12:34:56.5Z')?.getTime()).toBe(ATOM_MS + 500);
    });

    it('accepts Date instances and epoch seconds / milliseconds', () => {
      expect(parseApiDate(new Date(ATOM_MS))?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate(ATOM_MS)?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate(ATOM_MS / 1000)?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate(String(ATOM_MS / 1000))?.getTime()).toBe(ATOM_MS);
    });

    it('returns null (never Invalid Date) for junk', () => {
      for (const junk of [null, undefined, '', '   ', 'not a date', '2026-02-30', '2026-13-01', '2026-10-21 25:00:00', {}, [], NaN, Infinity, new Date(NaN)]) {
        expect(parseApiDate(junk)).withContext(String(junk)).toBeNull();
      }
    });
  });

  describe('on iOS (Safari date parsing)', () => {
    let restore: () => void;
    beforeEach(() => (restore = installSafariDateParsing()));
    afterEach(() => restore());

    it('reproduces the iOS failure with the native parser', () => {
      // Sanity: the simulation really behaves like WKWebView.
      expect(Number.isNaN(new Date('2026-10-21 12:34:56').getTime())).toBeTrue();
      expect(Number.isNaN(new Date('2026-10-21T12:34:56+0000').getTime())).toBeTrue();
      expect(new Date(ATOM).getTime()).toBe(ATOM_MS);
    });

    it('still parses the iOS-hostile shapes', () => {
      expect(parseApiDate('2026-10-21 12:34:56')?.getTime()).toBe(new Date(2026, 9, 21, 12, 34, 56).getTime());
      expect(parseApiDate('2026-10-21T12:34:56+0000')?.getTime()).toBe(ATOM_MS);
      expect(parseApiDate('2026-10-21 12:34:56.000000')).not.toBeNull();
    });

    it('formats a relative date instead of an empty string', () => {
      const now = new Date(2026, 9, 21, 15, 34, 56).getTime();
      expect(formatRelativeDate('2026-10-21 12:34:56', 'en', now)).toBe('3 hours ago');
    });
  });

  describe('apiDateMs', () => {
    it('returns epoch ms, or 0 for unparseable input (safe sort key)', () => {
      expect(apiDateMs(ATOM)).toBe(ATOM_MS);
      expect(apiDateMs('garbage')).toBe(0);
      expect(apiDateMs(undefined)).toBe(0);
    });
  });

  describe('formatRelativeDate', () => {
    const now = ATOM_MS;

    it('uses relative wording inside a week', () => {
      expect(formatRelativeDate(now - 10_000, 'en', now)).toBe('now');
      expect(formatRelativeDate(now - 5 * 60_000, 'en', now)).toBe('5 minutes ago');
      expect(formatRelativeDate(now - 86_400_000, 'en', now)).toBe('yesterday');
      expect(formatRelativeDate(now - 3 * 86_400_000, 'en', now)).toBe('3 days ago');
    });

    it('treats a slightly-future timestamp (clock skew) as now', () => {
      expect(formatRelativeDate(now + 30_000, 'en', now)).toBe('now');
    });

    it('falls back to a calendar date after a week', () => {
      expect(formatRelativeDate('2026-09-01T10:00:00Z', 'en', now)).toBe('Sep 1, 2026');
    });

    it('localises for Arabic', () => {
      const ar = formatRelativeDate(now - 86_400_000, 'ar', now);
      expect(ar).toBe(new Intl.RelativeTimeFormat('ar', { numeric: 'auto' }).format(-1, 'day'));
      expect(ar).not.toBe('yesterday');
    });

    it('returns an empty string for unparseable input', () => {
      expect(formatRelativeDate('nope', 'en', now)).toBe('');
      expect(formatRelativeDate(null, 'ar', now)).toBe('');
    });
  });
});
