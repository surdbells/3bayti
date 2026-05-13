# Deferred: HTML entity cleanup in vendor descriptions

**Status:** Audited but NOT applied. Deferred to M3 cleanup.
**Audit date:** Day 7 of M2 rollout (May 13, 2026)

## Why this is deferred

Of 100 vendors in v3, 67 have HTML entities (`&amp;`, `&#1605;`, `&#160;`, etc.)
in their `description` column. These descriptions came from the legacy WordPress
database, which stores entity-encoded HTML.

**The descriptions are not rendered to users anywhere in the current demo:**

| Surface | Renders vendor descriptions? |
|---|---|
| apps/web home page | No |
| apps/web /category/* pages | No |
| apps/web /product/* pages | No (only vendor name + slug) |
| apps/web /designer/* | Route not built |
| apps/mobile | Still on legacy v1 (Day 7 deferred) |
| apps/portal | Still on legacy v1 |

So fixing them today produces zero visible benefit to the demo. But fixing
them poorly (e.g. with a careless SQL UPDATE) could corrupt 67 production
rows. Risk-adjusted: defer.

## Audit findings

Quick spot-check shows three distinct shapes of bad data:

1. **Pure-Arabic descriptions with numeric character references**
   Example: vendor `03-boutique`
   ```
   &#1605;&#1588;&#1594;&#1604; &#1582;&#1610;&#1575;&#1591;&#1577;
   ```
   These decode to readable Arabic. Need numeric HTML entity decoding.

2. **HTML-wrapped descriptions**
   Example: vendor `98-boutique`
   ```
   <h5><span class="quote">Emirati inspired jalabya and abaya...</span></h5>
   ```
   Contains raw HTML tags. Decision needed: render as HTML (with sanitization) or strip to plain text.

3. **Simple cases**
   Example: vendor `8ighty-nine`
   ```
   When Modern and Traditional Meet&#160;
   ```
   `&#160;` is just a non-breaking space. Trivially decodable.

## Recommended approach when the cleanup is scheduled

Do NOT use a single PHP `html_entity_decode($desc, ENT_QUOTES, 'UTF-8')` on
every row blindly. The HTML-wrapped descriptions (shape 2) need different
handling:

```php
// Pseudo-code for the cleanup script
foreach ($rows as $row) {
    $cleaned = html_entity_decode($row['description'], ENT_QUOTES, 'UTF-8');
    // Strip outer HTML tags but preserve inline formatting?
    // Or strip everything and store plain text?
    // Or render as Markdown? Open question.
}
```

**Decisions needed before the cleanup runs:**
1. Should `description` allow HTML at all, or be plain text?
2. If HTML allowed, which tags are safe? (Sanitizer config.)
3. Backfill empty descriptions for shape-1 Arabic ones, or render as-is?

Once those decisions land, the cleanup is ~30-45 minutes:
- Backup the column: `CREATE TABLE vendors_description_backup AS SELECT id, description FROM vendors;`
- Write a PHP one-off that reads, decodes, writes back
- Add `description_format` column ('plain', 'html', 'markdown') for future safety

## Trigger conditions

Schedule this cleanup when ANY of these become true:
- apps/web adds /designer/:slug routes (rendering vendor description in HTML)
- apps/mobile flips to v3 vendor read endpoints
- apps/portal adds vendor management UI
- A search-engine-visible vendor profile page ships

Until then, the entities sit harmlessly in the DB.

## Affected vendors (sample, not exhaustive)

To avoid bloating this doc, see the full list via:

```bash
curl -s 'https://api-v3.3bayti.ae/v3/vendors?limit=104' | python3 -c "
import json, re, sys
d = json.load(sys.stdin)
ep = re.compile(r'&[a-z]+;|&#\d+;', re.I)
for v in d['data']:
    if ep.search(v.get('description', '') or ''):
        print(v['slug'])
"
```

As of audit date, this returned 67 slugs.
