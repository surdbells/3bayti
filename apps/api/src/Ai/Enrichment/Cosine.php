<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Enrichment;

/**
 * Cosine similarity for the pgvector-free semantic fallback: rank a candidate
 * set's embeddings against the query embedding in PHP. When pgvector is enabled
 * the same ordering comes from the database's `<=>` operator instead.
 */
final class Cosine
{
    /**
     * @param list<float> $a
     * @param list<float> $b
     * @return float similarity in [-1, 1]; 0 when either is empty or degenerate
     */
    public static function similarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * Rank candidate ids by cosine similarity to the query vector, descending.
     *
     * @param list<float> $query
     * @param array<int, list<float>> $candidates id => embedding
     * @return list<int> ids, most-similar first (candidates with empty vectors dropped)
     */
    public static function rank(array $query, array $candidates, int $limit): array
    {
        if ($query === []) {
            return [];
        }
        $scored = [];
        foreach ($candidates as $id => $vec) {
            if ($vec === []) {
                continue;
            }
            $scored[$id] = self::similarity($query, $vec);
        }
        arsort($scored);
        return array_slice(array_map('intval', array_keys($scored)), 0, $limit);
    }
}
