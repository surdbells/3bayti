<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\FacetAggregator;
use Bayti\Api\Domain\Catalog\ProductFilterParser;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\FacetsSerializer;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/products/facets
 *
 * Returns facet counts for the catalog list page (M3.2.X.10-B/C).
 *
 * Same query-string contract as GET /v3/products PLUS two refinement
 * keys (sizes / colors) the products endpoint doesn't currently
 * filter on. Slug resolution + filter parsing delegated to the shared
 * ProductFilterParser introduced in X.10-C.
 *
 * Soft-fail: unknown slugs return 200 with empty data + an
 * applied_filters echo (so the UI can show "filtering by:
 * does-not-exist — 0 results"). NOT a 404.
 *
 * Public endpoint, no auth required.
 */
final class ListFacetsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly ProductFilterParser $filterParser,
        private readonly FacetAggregator $aggregator,
        private readonly FacetsSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $appliedBlock = $this->filterParser->buildAppliedFiltersBlock($query);

        $parsed = $this->filterParser->parse($query);
        if ($parsed['filterNotFound']) {
            return $this->ok($this->serializer->shape(
                $this->emptyAggregatorResult(),
                $appliedBlock,
            ));
        }

        $result = $this->aggregator->compute($parsed['filters']);

        return $this->ok($this->serializer->shape($result, $appliedBlock));
    }

    /**
     * Empty 5-facet aggregator result for the unknown-slug soft-fail.
     *
     * @return array<string, mixed>
     */
    private function emptyAggregatorResult(): array
    {
        return [
            'size'           => ['values' => [], 'total_distinct' => 0],
            'color'          => ['values' => [], 'total_distinct' => 0],
            'price'          => ['values' => []],
            'vendor'         => ['values' => [], 'total_distinct' => 0],
            'category'       => ['values' => [], 'total_distinct' => 0],
            'total_products' => 0,
        ];
    }
}
