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
 * card via gift_cards.purchase_order_reference = orders.order_reference.
 *
 * By DEFAULT they are excluded from OrderRepository::paginatedForAdmin so they
 * never pollute the logistics board (fulfilment has nothing to ship for a gift
 * card). The merged admin Orders & Sales list opts in via
 * includeGiftCards = true so they surface as sales (the serializer synthesizes
 * a "Gift Card" line). Both paths are asserted below.
 *
 * CI has no PostgreSQL, so — same DQL-capture approach as
 * StyleRepositoryFindByOwnerTest / ProductRepositoryActiveVendorGatingTest — we
 * drive the repository through a mocked EntityManager that returns REAL
 * Doctrine QueryBuilders, capture every DQL string the method generates, and
 * assert the gift-card exclusion predicate is present (or absent) on BOTH the
 * count query and the id-page query (the two builders that decide which orders
 * appear).
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

    #[Test]
    public function paginatedForAdminIncludesGiftCardOrdersWhenOptedIn(): void
    {
        $dqls = $this->capturePaginatedForAdminDql(includeGiftCards: true);

        self::assertGreaterThanOrEqual(
            2,
            count($dqls),
            'paginatedForAdmin should emit at least a count + id-page query.',
        );

        $pattern = '/NOT\s+EXISTS\s*\('
            . '.*GiftCard\s+gc'
            . '.*gc\.purchaseOrderReference\s*=\s*o\.orderReference'
            . '.*\)/is';

        // With the opt-in flag the gatekeeper queries must NOT carry the
        // exclusion — gift-card sales belong on the merged Orders & Sales list.
        self::assertDoesNotMatchRegularExpression(
            $pattern,
            $dqls[0],
            'The count query must include gift-card orders when opted in.',
        );
        self::assertDoesNotMatchRegularExpression(
            $pattern,
            $dqls[1],
            'The id-page query must include gift-card orders when opted in.',
        );
    }

    /**
     * Drive paginatedForAdmin against a mocked EntityManager that hands out
     * real QueryBuilders, and collect every DQL string it compiles.
     *
     * @return list<string>
     */
    private function capturePaginatedForAdminDql(bool $includeGiftCards = false): array
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
        // No filters: prove the exclusion depends only on the includeGiftCards
        // flag, not on a status/user/vendor filter. The stub count returns
        // non-zero so the method proceeds into the id-page query — we assert
        // >= 2 captured queries above.
        $repo->paginatedForAdmin(20, 0, includeGiftCards: $includeGiftCards);

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
