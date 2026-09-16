<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Personalization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Raw-DBAL access to customer_style_profiles. Writes come from the
 * `ai:build-style-profiles` command; reads power the For-You rails. Reads are
 * defensive — any DB problem (table not migrated yet, connection issue) degrades
 * to "no profile", so the rails endpoint simply falls back to the popular/cold
 * path rather than erroring.
 *
 * Non-final so the rails test can mock the profile read.
 */
class CustomerStyleProfileStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** Insert/replace one customer's computed style profile. */
    public function upsert(int $userId, CustomerStyleProfile $profile): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:sP');

        $this->connection->executeStatement(
            'INSERT INTO customer_style_profiles
                (user_id, colours, styles, categories, vendors, occasions, sizes,
                 budget_min, budget_max, seed_product_ids, signal_counts,
                 computed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET
                colours = EXCLUDED.colours, styles = EXCLUDED.styles,
                categories = EXCLUDED.categories, vendors = EXCLUDED.vendors,
                occasions = EXCLUDED.occasions, sizes = EXCLUDED.sizes,
                budget_min = EXCLUDED.budget_min, budget_max = EXCLUDED.budget_max,
                seed_product_ids = EXCLUDED.seed_product_ids, signal_counts = EXCLUDED.signal_counts,
                computed_at = EXCLUDED.computed_at, updated_at = EXCLUDED.updated_at',
            [
                $userId,
                json_encode($profile->colours),
                json_encode($profile->styles),
                json_encode($profile->categories),
                json_encode($profile->vendors),
                json_encode($profile->occasions),
                json_encode($profile->sizes),
                $profile->budgetMin,
                $profile->budgetMax,
                json_encode($profile->seedProductIds),
                json_encode($profile->signalCounts === [] ? new \stdClass() : $profile->signalCounts),
                $now,
                $now,
                $now,
            ],
            [
                ParameterType::INTEGER,
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
            ],
        );
    }

    /** The stored profile for a user, or null if none is computed yet. */
    public function fetch(int $userId): ?CustomerStyleProfile
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT colours, styles, categories, vendors, occasions, sizes,
                        budget_min, budget_max, seed_product_ids, signal_counts
                 FROM customer_style_profiles WHERE user_id = ?',
                [$userId],
                [ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return null;
        }

        if ($row === false) {
            return null;
        }

        return CustomerStyleProfile::fromArray($row);
    }
}
