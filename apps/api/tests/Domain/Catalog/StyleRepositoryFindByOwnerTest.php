<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Style;
use Bayti\Api\Domain\Catalog\StyleRepository;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "My Styles" ownership gating: findByOwnerPaginated must surface only the
 * styles the authenticated user created (created_by_user) AND that are
 * active, NOT filter by style_type (community vs editorial).
 *
 * Same DQL-capture approach as ProductRepositoryActiveVendorGatingTest: CI
 * has no PostgreSQL, so we drive the repository through a mocked
 * EntityManager that returns REAL Doctrine QueryBuilders, capture the DQL,
 * and assert the WHERE clauses. This proves a style owned by a DIFFERENT
 * user (or an inactive one) is excluded, and that the query never filters
 * on s.styleType.
 */
#[CoversClass(StyleRepository::class)]
final class StyleRepositoryFindByOwnerTest extends TestCase
{
    #[Test]
    public function findByOwnerPaginatedFiltersByOwnerAndActive(): void
    {
        $dql = $this->captureFindByOwnerDql();

        // Owner gating, only the creator's styles.
        self::assertMatchesRegularExpression(
            '/s\.createdByUser\s*=\s*:user/i',
            $dql,
            'findByOwnerPaginated must require s.createdByUser = :user.',
        );

        // Active gating, soft-deleted/inactive styles excluded.
        self::assertMatchesRegularExpression(
            '/s\.isActive\s*=\s*true/i',
            $dql,
            'findByOwnerPaginated must require s.isActive = true.',
        );

        // Must NOT filter by style_type, My Styles spans community AND
        // editorial styles the user submitted.
        self::assertDoesNotMatchRegularExpression(
            '/s\.styleType/i',
            $dql,
            'findByOwnerPaginated must NOT filter by styleType.',
        );
    }

    #[Test]
    public function userParameterIsBoundToTheGivenUser(): void
    {
        $user = $this->makeUser();

        $em = $this->createMock(EntityManagerInterface::class);

        /** @var list<QueryBuilder> $builders */
        $builders = [];
        $em->method('createQueryBuilder')->willReturnCallback(
            function () use ($em, &$builders): QueryBuilder {
                $qb = new QueryBuilder($em);
                $builders[] = $qb;
                return $qb;
            },
        );
        $em->method('createQuery')->willReturnCallback(
            fn (string $dql): Query => $this->stubQuery(),
        );

        $repo = new StyleRepository($em, new ClassMetadata(Style::class));
        $repo->findByOwnerPaginated($user, 10, 0);

        $boundValue = null;
        foreach ($builders as $qb) {
            $param = $qb->getParameter('user');
            if ($param !== null) {
                $boundValue = $param->getValue();
                break;
            }
        }

        self::assertSame($user, $boundValue);
    }

    private function makeUser(): User
    {
        return new User('owner@example.test', null, 'hash');
    }

    /**
     * Drive findByOwnerPaginated and return the DQL string generated for
     * the main (item) query. The item query is the last createQuery call;
     * it carries the full where/orderBy shape.
     */
    private function captureFindByOwnerDql(): string
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

        $repo = new StyleRepository($em, new ClassMetadata(Style::class));
        $repo->findByOwnerPaginated($this->makeUser(), 10, 0);

        self::assertNotEmpty($captured, 'findByOwnerPaginated produced no DQL.');
        return (string) end($captured);
    }

    /**
     * A Query test double that no-ops the execution surface so
     * findByOwnerPaginated completes without a database. Same pattern as
     * ProductRepositoryActiveVendorGatingTest::stubQuery.
     */
    private function stubQuery(): Query
    {
        return new class extends Query {
            public function __construct()
            {
                // Skip the parent constructor: it requires an
                // EntityManager + UnitOfWork. We never execute against a
                // DB, so the uninitialised parent state is fine.
            }

            public function getResult(string|int $hydrationMode = self::HYDRATE_OBJECT): mixed
            {
                return [];
            }

            public function getSingleScalarResult(): mixed
            {
                return 0;
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
