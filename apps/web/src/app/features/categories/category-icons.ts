/**
 * Category icon resolution.
 *
 * Maps category slugs to bundled icon assets. Icons live in
 * public/icons/categories/ as 256×256 PNGs (decoded + downscaled
 * from the original SVG-wrapped PNGs uploaded by the project owner;
 * see Phase 1 W3 round-2 commit). Angular's build copies public/
 * straight to the served root, so the served URL is /icons/categories/.
 *
 * Why a static map and not the backend's `image` field:
 *   The /v2/categories response strips `image` to null because the
 *   backend's `icon` column stores Lucide icon names like '@tui.sparkles'
 *   that aren't real image URLs. Until the backend is reworked to point
 *   at real assets, the frontend resolves icons by slug locally.
 *
 * Slug format: '<name>-<id>' (e.g. 'abayas-1', 'modest-clothes-6').
 * The mapping uses the bare name portion (everything before the last
 * '-{number}') so the lookup is stable even if backend IDs change.
 *
 * Pyjamas note: per Phase 1 W3 round-2 direction, pyjamas has no
 * matching icon so we hide it from the home page entirely (returning
 * null here triggers the filter in HomeComponent). Pyjamas also
 * currently has 0 products in the catalog so this aligns with reality.
 */

const ICON_MAP: Record<string, string> = {
  abayas: '/icons/categories/abayas.png',
  accessories: '/icons/categories/accessories.png',
  bags: '/icons/categories/bag.png',
  dresses: '/icons/categories/dress.png',
  kaftans: '/icons/categories/kaftan.png',
  'modest-clothes': '/icons/categories/modest.png',
  mukhawars: '/icons/categories/mukhawar.png',
  // pyjamas: intentionally absent — categories without an icon are filtered
  // out of the home page categories row. Add the line below when an icon
  // exists:
  // pyjamas: '/icons/categories/pyjamas.png',
};

/**
 * Resolve an icon URL from a category slug.
 *
 * @param slug Full category slug, e.g. 'abayas-1' or 'modest-clothes-6'.
 * @returns The bundled icon URL, or null if no icon is mapped for this
 *          category. Callers should hide categories that resolve to null.
 */
export function categoryIconUrl(slug: string): string | null {
  if (!slug) return null;
  // Strip the trailing '-{numeric-id}' to get the bare name.
  const bareName = slug.replace(/-\d+$/, '');
  return ICON_MAP[bareName] ?? null;
}

/**
 * True if a category has a mapped icon.
 *
 * Used by HomeComponent to filter out categories like Pyjamas that
 * don't yet have icons. Filtering at the UI layer (rather than the
 * data layer) means /category and /category/:slug still surface every
 * category — only the home-page icon row hides icon-less ones.
 */
export function categoryHasIcon(slug: string): boolean {
  return categoryIconUrl(slug) !== null;
}
