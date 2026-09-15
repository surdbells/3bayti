<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Enrichment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Raw-DBAL access to product_ai_attributes (the AI enrichment + embedding store).
 * Writes come from the ai:build-product-attributes command; reads power the
 * semantic retrieval pass. Read paths are defensive: any DB problem (table not
 * migrated yet, connection issue) degrades to "no enrichment", so the concierge
 * simply falls back to keyword/filter retrieval rather than erroring.
 */
final class ProductAiAttributesStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Insert/replace one product's enrichment.
     *
     * @param list<string> $occasions
     * @param list<string> $colours
     * @param list<string> $styles
     * @param list<float> $embedding
     */
    public function upsert(
        int $productId,
        array $occasions,
        array $colours,
        array $styles,
        string $searchText,
        array $embedding,
        ?string $embedModel,
        string $sourceHash,
    ): void {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');
        $this->connection->executeStatement(
            'INSERT INTO product_ai_attributes
                (product_id, occasions, colours, styles, search_text, embedding, embed_model, source_hash, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (product_id) DO UPDATE SET
                occasions = EXCLUDED.occasions, colours = EXCLUDED.colours, styles = EXCLUDED.styles,
                search_text = EXCLUDED.search_text, embedding = EXCLUDED.embedding,
                embed_model = EXCLUDED.embed_model, source_hash = EXCLUDED.source_hash,
                updated_at = EXCLUDED.updated_at',
            [
                $productId,
                json_encode($occasions),
                json_encode($colours),
                json_encode($styles),
                $searchText,
                $embedding !== [] ? json_encode($embedding) : null,
                $embedModel,
                $sourceHash,
                $now,
                $now,
            ],
            [
                ParameterType::INTEGER,
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING,
            ],
        );
    }

    /**
     * Current source_hash per product id (for incremental builds).
     *
     * @param list<int> $productIds
     * @return array<int, string> id => source_hash
     */
    public function sourceHashesFor(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT product_id, source_hash FROM product_ai_attributes WHERE product_id IN (?)',
                [$productIds],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['product_id']] = (string) $r['source_hash'];
        }
        return $out;
    }

    /** Cheap "is there any embedding at all" gate for the semantic pass. */
    public function hasAnyEmbedding(): bool
    {
        try {
            return (bool) $this->connection->fetchOne(
                'SELECT 1 FROM product_ai_attributes WHERE embedding IS NOT NULL LIMIT 1',
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Embeddings for a candidate id set (for the PHP-cosine fallback).
     *
     * @param list<int> $productIds
     * @return array<int, list<float>> id => embedding (only rows that have one)
     */
    public function fetchEmbeddings(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT product_id, embedding FROM product_ai_attributes
                 WHERE product_id IN (?) AND embedding IS NOT NULL',
                [$productIds],
                [\Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $vec = json_decode((string) $r['embedding'], true);
            if (is_array($vec) && $vec !== []) {
                $out[(int) $r['product_id']] = array_map(static fn ($v): float => (float) $v, array_values($vec));
            }
        }
        return $out;
    }

    /**
     * Whether the pgvector extension + a `vector` column are available, so the
     * retrieval service can switch from PHP-cosine to database kNN. Cached per
     * request. See docs/ai-pgvector.md for enabling it.
     */
    public function hasPgvector(): bool
    {
        try {
            return (bool) $this->connection->fetchOne(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_name = 'product_ai_attributes' AND column_name = 'embedding_vec' LIMIT 1",
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * pgvector kNN: the ids of enriched, in-stock, sellable products nearest the
     * query vector (true semantic recall over the whole catalogue). Only meaningful
     * when hasPgvector(); fully guarded — any issue returns [] so the caller falls
     * back to the PHP-cosine path. Enable + verify per docs/ai-pgvector.md.
     *
     * @param list<float> $queryVec
     * @return list<int> product ids, nearest first
     */
    public function pgvectorRank(array $queryVec, int $limit, ?int $categoryId, ?float $minPrice, ?float $maxPrice): array
    {
        if ($queryVec === []) {
            return [];
        }
        try {
            $literal = '[' . implode(',', array_map(static fn ($f): string => (string) (float) $f, $queryVec)) . ']';

            $sql = "SELECT a.product_id
                    FROM product_ai_attributes a
                    JOIN products p ON p.id = a.product_id
                    JOIN vendors v ON v.id = p.vendor_id
                    WHERE a.embedding_vec IS NOT NULL
                      AND p.is_active = TRUE AND v.is_active = TRUE AND v.status = 'approved'
                      AND (p.allow_oversell = TRUE OR p.stock_status <> 'out_of_stock')";
            $params = ['qv' => $literal, 'lim' => $limit];
            $types = ['qv' => ParameterType::STRING, 'lim' => ParameterType::INTEGER];

            if ($categoryId !== null) {
                $sql .= ' AND p.category_id = :cat';
                $params['cat'] = $categoryId;
                $types['cat'] = ParameterType::INTEGER;
            }
            if ($minPrice !== null && $minPrice > 0) {
                $sql .= ' AND p.price >= :minp';
                $params['minp'] = $minPrice;
                $types['minp'] = ParameterType::STRING;
            }
            if ($maxPrice !== null && $maxPrice > 0) {
                $sql .= ' AND p.price <= :maxp';
                $params['maxp'] = $maxPrice;
                $types['maxp'] = ParameterType::STRING;
            }
            $sql .= ' ORDER BY a.embedding_vec <=> CAST(:qv AS vector) LIMIT :lim';

            $rows = $this->connection->fetchFirstColumn($sql, $params, $types);
            return array_map('intval', $rows);
        } catch (\Throwable) {
            return [];
        }
    }
}
