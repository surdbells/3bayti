-- =============================================================================
-- Post-migration cleanup: Fix HTML-entity-bug-affected vendor slug
-- =============================================================================
--
-- Background
-- ----------
-- During the Day 4 migration, the vendor with legacy_vendor_id=4722 had
-- its name stored as the HTML-encoded literal "Ether &amp; Moon" rather
-- than decoded "Ether & Moon". The vendor name was patched after Day 4
-- by a direct SQL UPDATE, but the SLUG had already been generated from
-- the encoded name. Result:
--
--   vendor.name = "Ether & Moon"     (correct, fixed)
--   vendor.slug = "ether-amp-moon"   (incorrect — &amp; bled into the slug)
--
-- Why this matters
-- ----------------
-- 1. The slug is in /v3/sitemap-data output, so search engines crawling
--    https://staging.3bayti.ae/sitemap.xml see "ether-amp-moon" as the
--    canonical URL slug for this vendor. Once cached by Google et al,
--    changing the slug later costs SEO reputation.
-- 2. Any internal app linking to this vendor (currently zero, but future
--    Phase 2 designer pages) would have to use the wrong slug.
-- 3. It's obviously wrong on inspection. Trust signal.
--
-- The fix
-- -------
-- Replace 'ether-amp-moon' with 'ether-and-moon'. The "and" expansion is
-- the human-readable form most readers expect when seeing "&" — same
-- substitution Wordpress, Shopify, and other CMSes do by default. We use
-- a single UPDATE statement keyed by the buggy slug, so it's idempotent
-- (re-running on already-fixed data is a no-op).
--
-- Why not regenerate from name
-- -----------------------------
-- Could compute the slug fresh from "Ether & Moon" → "ether-moon" but
-- that's a different result ("Tarsier & Hex" became "tarsier-hex" not
-- "tarsier-and-hex"). For ONE row, manual choice of "ether-and-moon"
-- preserves "and" which the description text uses. M3 would standardize.
--
-- Impact on related tables
-- ------------------------
-- Products table has vendor_id (integer FK), not vendor_slug, so no
-- update needed there. The /v3/products/:slug endpoint serialises the
-- vendor reference as {slug, name} via a JOIN — that join uses the
-- vendor's CURRENT slug at query time, so this UPDATE is sufficient.
--
-- Verification queries
-- --------------------
-- BEFORE:
--   SELECT id, slug, name FROM vendors WHERE slug = 'ether-amp-moon';
-- Expected: 1 row (vendor #92, "Ether & Moon")
--
-- AFTER:
--   SELECT id, slug, name FROM vendors WHERE id = 92;
-- Expected: slug = 'ether-and-moon', name = 'Ether & Moon'
--
-- =============================================================================

BEGIN;

-- The actual fix
UPDATE vendors
   SET slug = 'ether-and-moon',
       updated_at = date_trunc('second', NOW())
 WHERE slug = 'ether-amp-moon';

-- Verify exactly one row was affected (uncomment to assert):
-- SELECT 'rows updated: ' || COUNT(*) FROM vendors WHERE slug = 'ether-and-moon';

COMMIT;

-- =============================================================================
-- Post-run smoke check (run separately after COMMIT):
--
-- 1. Confirm the slug change:
--      SELECT id, slug, name FROM vendors WHERE id = 92;
--
-- 2. Confirm sitemap reflects it:
--      curl -s https://api-v3.3bayti.ae/v3/sitemap-data \
--        | python3 -c "import json,sys; d=json.load(sys.stdin); \
--          print([v for v in d['vendors'] if 'ether' in v['slug']])"
--      Expected: [{'slug': 'ether-and-moon', ...}]
--
-- 3. Confirm products linked to this vendor still resolve:
--      curl -s https://api-v3.3bayti.ae/v3/vendors/ether-and-moon/products
--      Expected: 200 OK with 3 products (Dia, Cyllene, Eirene)
--
-- 4. Confirm OLD slug now 404s:
--      curl -s -o /dev/null -w "%{http_code}\n" \
--        https://api-v3.3bayti.ae/v3/vendors/ether-amp-moon
--      Expected: 404
-- =============================================================================
