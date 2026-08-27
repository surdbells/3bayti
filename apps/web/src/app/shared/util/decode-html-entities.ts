/**
 * Decode the small set of HTML entities that leak into our product /
 * catalog copy (names, descriptions) from the legacy CMS.
 *
 * Why this exists: some product-name snapshots are stored HTML-entity
 * encoded (e.g. `Abaya &amp; Scarf`). Angular text interpolation sets
 * `textContent`, which does NOT re-parse entities, so `&amp;` renders
 * literally as `&amp;` instead of `&`. Decoding at display time fixes
 * the double-escape without touching stored data.
 *
 * SSR-safe: pure string work, no DOM (`document`/`textarea`), so it runs
 * identically during server prerender and in the browser.
 *
 * Scope: intentionally covers only the entities we actually see -
 * the named basics plus numeric (decimal + hex) character references.
 * It is NOT a general-purpose HTML sanitizer; callers must still avoid
 * binding the result to `innerHTML`.
 */
export function decodeHtmlEntities(input: string | null | undefined): string {
  if (!input) return '';
  return input
    /* Numeric refs first (&#38; / &#x26;) so a decoded `&` can't be
       mistaken for the start of a second entity. */
    .replace(/&#x([0-9a-f]+);/gi, (_, hex) => String.fromCodePoint(parseInt(hex, 16)))
    .replace(/&#(\d+);/g, (_, dec) => String.fromCodePoint(Number(dec)))
    .replace(/&nbsp;/g, ' ')
    .replace(/&quot;/g, '"')
    .replace(/&#39;|&apos;/g, "'")
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    /* &amp; LAST so we don't turn `&amp;lt;` into `<`. */
    .replace(/&amp;/g, '&');
}
