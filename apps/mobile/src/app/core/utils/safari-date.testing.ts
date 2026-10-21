/**
 * Test helper (imported by specs only, never by app code): makes the global
 * `Date` parse strings the way Safari / WKWebView does on iOS.
 *
 * Karma runs in Chrome, whose lenient parser accepts '2026-10-21 12:34:56',
 * so an iOS-only Invalid Date can't be reproduced natively. While installed,
 * `new Date(str)` and `Date.parse(str)` accept ONLY the strict ECMAScript
 * date-time format and yield NaN for everything else, exactly the iOS
 * failure mode. Numeric constructors, Date.UTC and Date.now are untouched.
 *
 * Usage:
 *   let restore: () => void;
 *   beforeEach(() => (restore = installSafariDateParsing()));
 *   afterEach(() => restore());
 */

/** The only string shape ECMAScript requires every engine to parse. */
const STRICT_ES_DATE =
  /^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,3})?)?(?:Z|[+-]\d{2}:\d{2})?)?$/;

export function installSafariDateParsing(): () => void {
  const NativeDate = Date;

  class SafariLikeDate extends NativeDate {
    constructor(...args: unknown[]) {
      if (args.length === 1 && typeof args[0] === 'string' && !STRICT_ES_DATE.test(args[0])) {
        super(NaN);
      } else {
        super(...(args as [number, number, number?, number?, number?, number?, number?]));
      }
    }

    static override parse(s: string): number {
      return STRICT_ES_DATE.test(s) ? NativeDate.parse(s) : NaN;
    }
  }

  (globalThis as { Date: DateConstructor }).Date = SafariLikeDate as unknown as DateConstructor;
  return () => {
    (globalThis as { Date: DateConstructor }).Date = NativeDate;
  };
}
