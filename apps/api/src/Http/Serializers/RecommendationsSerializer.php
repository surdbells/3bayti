<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;

/**
 * Serialize recommendation envelopes for the X.12-G HTTP endpoints.
 *
 * Wraps the {product, score, source} rows produced by
 * RecommendationsService through ProductSerializer's listShape
 * so the client gets full catalog row data alongside the
 * recommendation metadata.
 */
final class RecommendationsSerializer
{
    public function __construct(
        private readonly ProductSerializer $productSerializer,
    ) {
    }

    /**
     * @param list<array{product: Product, score: string, source: string}> $recs
     * @return array<string, mixed>
     */
    public function shape(array $recs, int $limit): array
    {
        $data = array_map(
            fn (array $rec): array => [
                'product' => $this->productSerializer->listShape($rec['product']),
                'score' => $rec['score'],
                'source' => $rec['source'],
            ],
            $recs,
        );

        return [
            'data' => $data,
            'meta' => [
                'total' => count($recs),
                'limit' => $limit,
            ],
        ];
    }

    /**
     * Admin "explain" shape, fuller breakdown grouping recs by
     * source so an admin can see at a glance: 'this product gets
     * 5 copurchase + 3 category recs'.
     *
     * @param list<array{product: Product, score: string, source: string, rank: int}> $recs
     * @return array<string, mixed>
     */
    public function explainShape(int $productId, array $recs): array
    {
        $bySource = [
            'copurchase' => [],
            'category' => [],
            'fallback_popular' => [],
        ];
        foreach ($recs as $rec) {
            $bySource[$rec['source']][] = [
                'product' => $this->productSerializer->listShape($rec['product']),
                'score' => $rec['score'],
                'rank' => $rec['rank'],
            ];
        }

        return [
            'data' => [
                'product_id' => $productId,
                'total_recommendations' => count($recs),
                'by_source' => [
                    'copurchase' => [
                        'count' => count($bySource['copurchase']),
                        'rows' => $bySource['copurchase'],
                    ],
                    'category' => [
                        'count' => count($bySource['category']),
                        'rows' => $bySource['category'],
                    ],
                    'fallback_popular' => [
                        'count' => count($bySource['fallback_popular']),
                        'rows' => $bySource['fallback_popular'],
                    ],
                ],
            ],
        ];
    }
}
