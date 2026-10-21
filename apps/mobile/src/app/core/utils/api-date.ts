/**
 * Engine-independent parsing + display of API timestamps.
 *
 * Why this exists
 * ===============
 * `new Date(string)` is only SPECIFIED for the strict ECMAScript date-time
 * format (`YYYY-MM-DD`, `YYYY-MM-DDTHH:mm[:ss[.sss]][Z|±HH:mm]`). Every other
 * shape is implementation-defined, and the engines disagree:
 *
 *   input                          Chrome / Android WebView   Safari / WKWebView (iOS)
 *   '2026-10-21 12:34:56'          parsed (local time)        Invalid Date
 *   '2026-10-21T12:34:56+0000'     parsed                     Invalid Date
 *   '2026-10-21T12:34:56.123456Z'  parsed                     Invalid Date on older iOS
 *
 * So a timestamp that renders fine on Android silently becomes `Invalid Date`
 * on iOS: `getTime()` is NaN (sorts break, "x ago" goes empty) and Angular's
 * DatePipe THROWS on it, which aborts change detection for the view.
 *
 * The helpers below never hand a raw API string to the engine's parser: they
 * read the parts with a regex and build the Date from numbers, so the result
 * is identical on every platform (and follows the ECMAScript rules Chrome
 * already applies: a bare date is UTC, a date-time without an offset is local).
 * Anything unparseable comes back as `null` / `0` / `''`, never Invalid Date
 * and never an exception.
 */

/** YYYY-MM-DD, optional [T or space]HH:mm[:ss[.fraction]], optional Z / ±HH[[:]mm]. */
const API_DATE_RE =
  /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:[.,](\d+))?)?)?\s*(Z|[+-]\d{2}(?::?\d{2})?)?$/i;

/** Plain epoch number sent as a string, e.g. "1760000000". */
const EPOCH_STRING_RE = /^-?\d+(?:\.\d+)?$/;

const MINUTE_MS = 60_000;
const HOUR_MS = 3_600_000;
const DAY_MS = 86_400_000;

/**
 * Parse an API timestamp into a valid Date, or `null`.
 *
 * Accepts: Date instances, epoch numbers (seconds or milliseconds), ISO-8601 /
 * ATOM strings, MySQL `YYYY-MM-DD HH:mm:ss`, bare `YYYY-MM-DD`, offsets with or
 * without a colon (`+04:00`, `+0400`, `+04`) and fractional seconds of any
 * length. Never returns an Invalid Date.
 */
export function parseApiDate(value: unknown): Date | null {
  // Tag check instead of `instanceof Date`, robust across realms and when the
  // global Date has been swapped (tests).
  if (Object.prototype.toString.call(value) === '[object Date]') {
    const time = (value as Date).getTime();
    return Number.isNaN(time) ? null : new Date(time);
  }
  if (typeof value === 'number') {
    return fromEpoch(value);
  }
  if (typeof value !== 'string') {
    return null;
  }

  const s = value.trim();
  if (s === '') {
    return null;
  }
  if (EPOCH_STRING_RE.test(s)) {
    return fromEpoch(Number(s));
  }

  const m = API_DATE_RE.exec(s);
  if (m) {
    return fromParts(m);
  }

  // A shape we don't know: let the engine try, but never leak Invalid Date.
  const fallback = new Date(s);
  return Number.isNaN(fallback.getTime()) ? null : fallback;
}

/** Epoch milliseconds for sorting; 0 when the value can't be parsed. */
export function apiDateMs(value: unknown): number {
  return parseApiDate(value)?.getTime() ?? 0;
}

/**
 * Short human date for review cards: "now" / "5 minutes ago" / "yesterday"
 * inside a week, then a calendar date ("Oct 21, 2026"), localised for the
 * app language ('ar' or English). Returns '' when the value can't be parsed.
 */
export function formatRelativeDate(value: unknown, lang = 'en', now: number = Date.now()): string {
  const date = parseApiDate(value);
  if (!date) {
    return '';
  }
  const locale = lang === 'ar' ? 'ar' : 'en';
  // A timestamp slightly in the future (device clock skew) reads as "now".
  const diff = Math.max(0, now - date.getTime());

  try {
    if (diff < 7 * DAY_MS) {
      const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
      if (diff < MINUTE_MS) return rtf.format(0, 'second');
      if (diff < HOUR_MS) return rtf.format(-Math.floor(diff / MINUTE_MS), 'minute');
      if (diff < DAY_MS) return rtf.format(-Math.floor(diff / HOUR_MS), 'hour');
      return rtf.format(-Math.floor(diff / DAY_MS), 'day');
    }
    return new Intl.DateTimeFormat(locale, { year: 'numeric', month: 'short', day: 'numeric' }).format(date);
  } catch {
    // Intl missing or partial (old WebView): plain fallback, never throw.
    if (diff < MINUTE_MS) return 'Just now';
    if (diff < HOUR_MS) return `${Math.floor(diff / MINUTE_MS)}m ago`;
    if (diff < DAY_MS) return `${Math.floor(diff / HOUR_MS)}h ago`;
    if (diff < 7 * DAY_MS) return `${Math.floor(diff / DAY_MS)}d ago`;
    return date.toISOString().slice(0, 10);
  }
}

/* ------------------------------------------------------------------ */

function fromEpoch(n: number): Date | null {
  if (!Number.isFinite(n)) {
    return null;
  }
  // PHP / Unix timestamps are seconds, JS epochs are milliseconds. Every
  // millisecond epoch after March 1973 is above 1e11; every seconds epoch
  // before the year 5138 is below it.
  const date = new Date(Math.abs(n) < 1e11 ? n * 1000 : n);
  return Number.isNaN(date.getTime()) ? null : date;
}

function fromParts(m: RegExpExecArray): Date | null {
  const year = Number(m[1]);
  const month = Number(m[2]);
  const day = Number(m[3]);
  const hasTime = m[4] !== undefined;
  const hour = hasTime ? Number(m[4]) : 0;
  const minute = hasTime ? Number(m[5]) : 0;
  const second = m[6] !== undefined ? Number(m[6]) : 0;
  // Any fraction length (PHP emits microseconds); keep milliseconds.
  const ms = m[7] !== undefined ? Number(m[7].slice(0, 3).padEnd(3, '0')) : 0;
  const zone = m[8];

  if (month < 1 || month > 12 || day < 1 || day > 31 || hour > 23 || minute > 59 || second > 59) {
    return null;
  }
  // Reject roll-overs such as 2026-02-30 (the Date constructors would
  // silently turn it into 2 March).
  const calendarCheck = new Date(Date.UTC(year, month - 1, day));
  if (calendarCheck.getUTCMonth() !== month - 1 || calendarCheck.getUTCDate() !== day) {
    return null;
  }

  let time: number;
  if (zone !== undefined || !hasTime) {
    // Explicit offset, or a bare date (which ECMAScript defines as UTC).
    time = Date.UTC(year, month - 1, day, hour, minute, second, ms) - offsetMinutes(zone) * MINUTE_MS;
  } else {
    // Date-time without an offset: ECMAScript (and Chrome) read it as local.
    time = new Date(year, month - 1, day, hour, minute, second, ms).getTime();
  }
  return Number.isNaN(time) ? null : new Date(time);
}

/** Minutes east of UTC for 'Z', '+04', '+0400', '+04:00', '-0530'. */
function offsetMinutes(zone: string | undefined): number {
  if (zone === undefined || zone.toUpperCase() === 'Z') {
    return 0;
  }
  const digits = zone.slice(1).replace(':', '');
  const hours = Number(digits.slice(0, 2));
  const minutes = digits.length > 2 ? Number(digits.slice(2, 4)) : 0;
  return (zone.startsWith('-') ? -1 : 1) * (hours * 60 + minutes);
}
