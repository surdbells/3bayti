<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

/**
 * A collection-membership write was refused because the stored state is not
 * what the client last loaded (an optimistic-concurrency precondition such as
 * expected_product_ids / expected_collection_ids failed), or because a
 * concurrent writer inserted the same (collection, product) pair first.
 * Nothing was written. The HTTP layer maps it to 409 CONFLICT_STALE.
 */
final class StaleCollectionMembershipException extends \RuntimeException
{
    /**
     * @param list<int>|null $currentIds the stored ids the client should reload
     *                                   from (product ids in curation order for
     *                                   a collection; collection ids ascending
     *                                   for a product), or null when unknown
     */
    public function __construct(public readonly ?array $currentIds, ?\Throwable $previous = null)
    {
        parent::__construct('Collection membership changed since it was loaded.', 0, $previous);
    }
}
