<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Order;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Gift-card *purchase* orders are synthetic Orders with no line items
 * (InitiateCheckoutController::initiateGiftCardPurchase), linked to the funded
 * card via gift_cards.purchase_order_reference = orders.order_reference. They
 * are an internal payment vehicle and must never pollute the admin Orders list
 * or the logistics board — both of which are powered by
 * OrderRepository::paginatedForAdmin.
 *
 * CI has no PostgreSQL, so — same DQL-capture approach as
 * StyleRepositoryFindByOwnerTest / ProductRepositoryActiveVendorGatingTest — we
 * drive the repository through a mocked EntityManager that returns REAL
 * Doctrine QueryBuilders, capture every DQL string the method generates, and
 * assert the gift-card exclusion predicate is present on BOTH the count query
 * and the id-page query (the two builders that decide which orders appear).
 */
#[CoversClass(OrderRepository::class)]
final class OrderRepositoryGiftCardExclusionTest extends TestCase
{
    #[Test]
    public function paginatedForAdminExcludesGiftCardPurchaseOrders(): void
    {
        $dqls = $this->capturePaginatedForAdminDql();

        // The count + id-page queries are the first two DQL statements the
        // method emits (the final hydrate query only runs when ids are found,
        // which our stub avoids by returning an empty page). Both gatekeeper
        // queries must carry the NOT EXISTS exclusion.
        self::assertGreaterThanOrEqual(
            2,
            count($dqls),
            'paginatedForAdmin should emit at least a count + id-page query.',
        );

        // Predicate: NOT EXISTS ( SELECT 1 FROM ...GiftCard gc
        //            WHERE gc.purchaseOrderReference = o.orderReference )
        $pattern = '/NOT\s+EXISTS\s*\('
            . '.*GiftCard\s+gc'
            . '.*gc\.purchaseOrderReference\s*=\s*o\.orderReference'
            . '.*\)/is';

        $countDql = $dqls[0];
        $idPageDql = $dqls[1];

        self::assertMatchesRegularExpression(
            $pattern,
            $countDql,
            'The count query must exclude gift-card purchase orders.',
        );
        self::assertMatchesRegularExpression(
            $pattern,
            $idPageDql,
            'The id-page query must exclude gift-card purchase orders.',
        );
    }

    /**
     * Drive paginatedForAdmin against a mocked EntityManager that hands out
     * real QueryBuilders, and collect every DQL string it compiles.
     *
     * @return list<string>
     */
    private function capturePaginatedForAdminDql(): array
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturnCallback(
            static fn (): QueryBuilder => new QueryBuilder($em),
        );

        $captured = [];
        $em->method('createQuery')->willReturnCallback(
            function (string $dql) use (&$captured): Query {
                $captured[] = $dql;
                return $this->stubQuery();
            },
        );

        $repo = new OrderRepository($em, new ClassMetadata(Order::class));
        // No filters: prove the exclusion is unconditional, not gated behind a
        // status/user/vendor filter. The stub count returns 0, so the method
        // short-circuits after the count query in some paths — to be safe we
        // also assert >= 2 captured queries above.
        $repo->paginatedForAdmin(20, 0);

        self::assertNotEmpty($captured, 'paginatedForAdmin produced no DQL.');
        return $captured;
    }

    /**
     * A Query test double that no-ops the execution surface so
     * paginatedForAdmin completes without a database.
     *
     * getSingleScalarResult returns a non-zero count so the method proceeds
     * PAST the count query into the id-page query (we want to capture both).
     * getScalarResult returns an empty page so the final hydrate query is
     * skipped (it has no exclusion to assert and needs no execution).
     */
    private function stubQuery(): Query
    {
        return new class extends Query {
            public function __construct()
            {
                // Skip the parent constructor: it requires an EntityManager +
                // UnitOfWork. We never execute against a DB.
            }

            public function getResult(string|int $hydrationMode = self::HYDRATE_OBJECT): mixed
            {
                return [];
            }

            public function getScalarResult(): array
            {
                return [];
            }

            public function getSingleScalarResult(): mixed
            {
                // Non-zero so paginatedForAdmin does not short-circuit and goes
                // on to build the id-page query.
                return 1;
            }

            public function setParameters(\Doctrine\Common\Collections\ArrayCollection|array $parameters): static
            {
                return $this;
            }

            public function setFirstResult(int|null $firstResult): self
            {
                return $this;
            }

            public function setMaxResults(int|null $maxResults): self
            {
                return $this;
            }
        };
    }
}
