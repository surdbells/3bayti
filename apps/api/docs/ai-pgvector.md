# Enabling pgvector for Ain semantic search

Ain's semantic retrieval works **out of the box** on the JSONB embeddings in
`product_ai_attributes.embedding` (cosine similarity computed in PHP). This is
fine for a modest catalogue but scans the candidate pool per request.

Enabling the Postgres **pgvector** extension gives true, indexed nearest-neighbour
search over the whole catalogue. `ProductAiAttributesStore::hasPgvector()`
auto-detects the `embedding_vec` column and `ProductRetrievalService` switches to
DB kNN automatically — no code change or redeploy needed.

`CREATE EXTENSION` needs a DB **superuser** and cannot run inside a Doctrine
migration (same constraint as `pg_trgm`), so this is a one-time out-of-band step.

## Steps (run as a Postgres superuser on the prod DB)

```sql
-- 1) Enable the extension (once per database).
CREATE EXTENSION IF NOT EXISTS vector;

-- 2) Add the vector column. Dimension must match the embedding model:
--    text-embedding-3-small = 1536, text-embedding-3-large = 3072.
ALTER TABLE product_ai_attributes ADD COLUMN embedding_vec vector(1536);

-- 3) Backfill from the JSONB embeddings already built by ai:build-product-attributes.
UPDATE product_ai_attributes
   SET embedding_vec = (
     '[' || (SELECT string_agg(value::text, ',') FROM jsonb_array_elements_text(embedding)) || ']'
   )::vector
 WHERE embedding IS NOT NULL AND embedding_vec IS NULL;

-- 4) Approximate-NN index (cosine). Tune `lists` ~ sqrt(rows).
CREATE INDEX idx_product_ai_attributes_vec
  ON product_ai_attributes USING ivfflat (embedding_vec vector_cosine_ops) WITH (lists = 100);

ANALYZE product_ai_attributes;
```

## Keeping it in sync

`ai:build-product-attributes` writes the JSONB `embedding`. To also populate the
vector column on each build, add the same expression as step 3 to a nightly job,
or re-run step 3 after each enrichment run:

```sql
UPDATE product_ai_attributes
   SET embedding_vec = ('[' || (SELECT string_agg(value::text, ',') FROM jsonb_array_elements_text(embedding)) || ']')::vector
 WHERE embedding IS NOT NULL
   AND (embedding_vec IS NULL OR updated_at > now() - interval '1 day');
```

## Verify

After enabling, hit `POST /v3/ai/concierge/style` and confirm results still return
(the store's `pgvectorRank()` is fully guarded — any SQL/type issue silently falls
back to the PHP-cosine path, so the concierge never breaks). Spot-check the SQL:

```sql
SELECT product_id
  FROM product_ai_attributes
 WHERE embedding_vec IS NOT NULL
 ORDER BY embedding_vec <=> (SELECT embedding_vec FROM product_ai_attributes WHERE embedding_vec IS NOT NULL LIMIT 1)
 LIMIT 5;
```

## Rolling back

```sql
DROP INDEX IF EXISTS idx_product_ai_attributes_vec;
ALTER TABLE product_ai_attributes DROP COLUMN IF EXISTS embedding_vec;
-- (optional) DROP EXTENSION vector;
```

Retrieval reverts to the JSONB PHP-cosine path automatically.
