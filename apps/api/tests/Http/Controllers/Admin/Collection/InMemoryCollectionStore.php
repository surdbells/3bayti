<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Admin\Collection;

use Bayti\Api\Domain\Authz\Permission;
use Bayti\Api\Domain\Authz\Role;
use Bayti\Api\Domain\Catalog\CollectionProduct;
use Bayti\Api\Domain\Catalog\CollectionProductRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Doctrine\DBAL\Driver\AbstractException as DriverAbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stateful in-memory stand-in for the collection curation tables, for the
 * HTTP tests (CI has no PostgreSQL, so the EntityManager is mocked; see
 * HttpTestCase). Collections, products and collection_products rows live in
 * arrays (the "database"); the repository mocks read them with the same
 * semantics as the real queries (ordering: display_order ASC NULLS LAST, then
 * id DESC), and the EM mock behaves like Doctrine's where it matters:
 *
 *   - persist()/remove() are QUEUED and only applied on flush(), inserts
 *     BEFORE deletes (Doctrine's commit order), with UNIQUE(collection_id,
 *     product_id) enforced at insert time, so a flush that inserts a pair
 *     still present (even one queued for deletion) throws
 *     UniqueConstraintViolationException exactly like Postgres would;
 *   - wrapInTransaction() flushes after the callback (like Doctrine) and, if
 *     anything throws, ROLLS BACK: rows, sort_orders and display_orders are
 *     restored and queued work is dropped;
 *   - the advisory-lock repository methods must run inside a transaction
 *     (the real ones throw a LogicException otherwise) and every lock/read is
 *     appended to $events, so tests can assert "lock, then read";
 *   - $onLock / $afterRead hooks simulate ANOTHER transaction committing while
 *     this one waits for a lock / right after this one read
 *     (commitConcurrently() keeps such a change across our rollback).
 *
 * The real queries' DQL/SQL is pinned separately by
 * tests/Domain/Catalog/CollectionCurationRepositoryDqlTest.
 *
 * Used only by classes extending HttpTestCase.
 */
trait InMemoryCollectionStore
{
    /** @var array<int, ProductCollection> */
    private array $collections = [];
    /** @var array<int, Product> */
    private array $products = [];
    /** @var list<CollectionProduct> */
    private array $rows = [];
    private int $nextRowId = 1000;
    private int $flushes = 0;
    private int $flushesOutsideTransaction = 0;
    private int $transactions = 0;
    private int $rollbacks = 0;
    private bool $inTransaction = false;
    /** @var array<string, int> repository method => call count */
    private array $repoCalls = [];
    /** @var list<CollectionProduct> rows INSERTed (applied on flush) */
    private array $persisted = [];
    /** @var list<CollectionProduct> rows DELETEd (applied on flush) */
    private array $removed = [];
    /** @var list<CollectionProduct> */
    private array $pendingInserts = [];
    /** @var list<CollectionProduct> */
    private array $pendingDeletes = [];
    /** @var list<string> lock/read log, e.g. "lock:collection:5", "read:findForProduct" */
    private array $events = [];
    /** @var (callable(string): void)|null called with the lock key once granted */
    private $onLock = null;
    /** @var (callable(string): void)|null called after an authoritative membership read */
    private $afterRead = null;
    /** @var array{rows: list<CollectionProduct>, sort: array<int, int>, display: array<int, ?int>}|null */
    private ?array $snapshot = null;
    private ?Vendor $storeVendor = null;

    private function resetStore(): void
    {
        $this->collections = [];
        $this->products = [];
        $this->rows = [];
        $this->nextRowId = 1000;
        $this->flushes = 0;
        $this->flushesOutsideTransaction = 0;
        $this->transactions = 0;
        $this->rollbacks = 0;
        $this->inTransaction = false;
        $this->repoCalls = [];
        $this->persisted = [];
        $this->removed = [];
        $this->pendingInserts = [];
        $this->pendingDeletes = [];
        $this->events = [];
        $this->onLock = null;
        $this->afterRead = null;
        $this->snapshot = null;
        $this->storeVendor = null;
    }

    // ── seeding ─────────────────────────────────────────────────────

    private function addCollection(int $id, string $name, ?int $displayOrder = null, bool $active = true): ProductCollection
    {
        $c = new ProductCollection($name, strtolower(str_replace(' ', '-', $name)));
        $this->forceId($c, ProductCollection::class, $id);
        $c->setDisplayOrder($displayOrder);
        $c->setActive($active);
        $this->collections[$id] = $c;
        return $c;
    }

    private function addProduct(int $id, string $name): Product
    {
        if ($this->storeVendor === null) {
            $this->storeVendor = new Vendor('almas', 'Almas', 'v@example.test');
            $this->forceId($this->storeVendor, Vendor::class, 1);
        }
        $p = new Product($this->storeVendor, 'p-' . $id, $name);
        $this->forceId($p, Product::class, $id);
        $this->products[$id] = $p;
        return $p;
    }

    private function addMember(int $collectionId, int $productId, int $sortOrder): CollectionProduct
    {
        $row = new CollectionProduct($this->collections[$collectionId], $this->products[$productId], $sortOrder);
        $this->forceId($row, CollectionProduct::class, $this->nextRowId++);
        $this->rows[] = $row;
        return $row;
    }

    /**
     * Another admin's transaction COMMITS this membership row now (used from
     * the $onLock / $afterRead hooks). Unlike our own writes it survives a
     * rollback of the transaction under test.
     */
    private function commitConcurrently(int $collectionId, int $productId, int $sortOrder): CollectionProduct
    {
        $row = $this->addMember($collectionId, $productId, $sortOrder);
        if ($this->snapshot !== null) {
            $this->snapshot['rows'][] = $row;
            $this->snapshot['sort'][spl_object_id($row)] = $sortOrder;
        }
        return $row;
    }

    /** @return array<int, int> collection id => the product's sort_order there */
    private function membershipOf(int $productId): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ($r->getProduct()->getId() === $productId) {
                $out[(int) $r->getCollection()->getId()] = $r->getSortOrder();
            }
        }
        ksort($out);
        return $out;
    }

    /** @return list<int> product ids of a collection in curation order */
    private function membersOf(int $collectionId): array
    {
        return array_map(
            static fn (CollectionProduct $r): int => (int) $r->getProduct()->getId(),
            $this->rowsOfCollection($collectionId),
        );
    }

    /** @return list<CollectionProduct> sort_order ASC, id ASC */
    private function rowsOfCollection(int $collectionId): array
    {
        $rows = array_values(array_filter(
            $this->rows,
            static fn (CollectionProduct $r): bool => $r->getCollection()->getId() === $collectionId,
        ));
        usort($rows, static fn (CollectionProduct $a, CollectionProduct $b): int
            => [$a->getSortOrder(), $a->getId()] <=> [$b->getSortOrder(), $b->getId()]);
        return $rows;
    }

    // ── users ───────────────────────────────────────────────────────

    private function makeAdmin(): User
    {
        $u = $this->makeUser(id: 99);
        $u->setRoles(admin: true);
        return $u;
    }

    /** Back-office staff holding no RBAC permission (reaches the admin group, fails every gate). */
    private function makeStaffWithoutPermissions(): User
    {
        $u = $this->makeUser(id: 98);
        $u->setRoles(support: true);
        return $u;
    }

    /** Staff with exactly the given permission keys. */
    private function makeStaffWith(string ...$permissionKeys): User
    {
        $role = new Role('custom', 'Custom');
        foreach ($permissionKeys as $key) {
            $role->addPermission(new Permission($key, 'catalog', $key));
        }
        $u = $this->makeUser(id: 97);
        $u->addRole($role);
        return $u;
    }

    // ── wiring ──────────────────────────────────────────────────────

    private function bindStore(User $user): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $collectionRepo = $this->createMock(ProductCollectionRepository::class);
        $collectionRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?ProductCollection => $this->collections[(int) $id] ?? null,
        );
        $collectionRepo->method('findBy')->willReturnCallback(function (array $criteria): array {
            $this->track('collections.findBy');
            // DB order (id ASC), NOT the requested order: callers must re-order.
            $ids = array_map('intval', (array) ($criteria['id'] ?? []));
            sort($ids);
            return array_values(array_filter(array_map(fn (int $id) => $this->collections[$id] ?? null, $ids)));
        });
        $collectionRepo->method('findAllOrdered')->willReturnCallback(function (bool $refresh = false): array {
            $this->track('collections.findAllOrdered');
            $this->events[] = 'read:findAllOrdered' . ($refresh ? '(refresh)' : '');
            return $this->orderedCollections();
        });
        $collectionRepo->method('orderedIds')->willReturnCallback(function (): array {
            $this->track('collections.orderedIds');
            return array_map(static fn (ProductCollection $c): int => (int) $c->getId(), $this->orderedCollections());
        });
        $collectionRepo->method('lockDisplayOrder')->willReturnCallback(function (): void {
            $this->acquireLock('order');
        });
        $collectionRepo->method('findPaginated')->willReturnCallback(function (int $limit = 20, int $offset = 0): array {
            $all = $this->orderedCollections();
            return ['items' => array_slice($all, $offset, $limit), 'total' => count($all)];
        });

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('find')->willReturnCallback(
            fn (mixed $id): ?Product => $this->products[(int) $id] ?? null,
        );
        $productRepo->method('findBy')->willReturnCallback(function (array $criteria): array {
            $out = [];
            foreach ((array) ($criteria['id'] ?? []) as $id) {
                if (isset($this->products[(int) $id])) {
                    $out[] = $this->products[(int) $id];
                }
            }
            return $out;
        });

        $joinRepo = $this->createMock(CollectionProductRepository::class);
        $joinRepo->method('productIdsForCollection')->willReturnCallback(
            fn (int $cid): array => $this->membersOf($cid),
        );
        $joinRepo->method('lockCollections')->willReturnCallback(function (array $cids): void {
            $this->track('join.lockCollections');
            $cids = array_values(array_unique(array_map('intval', $cids)));
            sort($cids);
            foreach ($cids as $cid) {
                $this->acquireLock('collection:' . $cid);
            }
        });
        $joinRepo->method('lockProduct')->willReturnCallback(function (int $pid): void {
            $this->acquireLock('product:' . $pid);
        });
        $joinRepo->method('findForCollection')->willReturnCallback(function (int $cid): array {
            $this->track('join.findForCollection');
            $this->events[] = 'read:findForCollection';
            $rows = $this->rowsOfCollection($cid);
            if ($this->afterRead !== null) {
                ($this->afterRead)('findForCollection');
            }
            return $rows;
        });
        $joinRepo->method('collectionIdsForProduct')->willReturnCallback(function (int $pid): array {
            $this->track('join.collectionIdsForProduct');
            return array_keys($this->membershipOf($pid));
        });
        $joinRepo->method('membershipsForProducts')->willReturnCallback(function (array $pids): array {
            $this->track('join.membershipsForProducts');
            $rank = array_flip(array_map(
                static fn (ProductCollection $c): int => (int) $c->getId(),
                $this->orderedCollections(),
            ));
            $rows = array_values(array_filter(
                $this->rows,
                static fn (CollectionProduct $r): bool => in_array($r->getProduct()->getId(), $pids, true),
            ));
            usort($rows, static fn (CollectionProduct $a, CollectionProduct $b): int
                => $rank[(int) $a->getCollection()->getId()] <=> $rank[(int) $b->getCollection()->getId()]);
            return array_map(static fn (CollectionProduct $r): array => [
                'product_id' => (int) $r->getProduct()->getId(),
                'id'         => (int) $r->getCollection()->getId(),
                'name'       => $r->getCollection()->getName(),
                'slug'       => $r->getCollection()->getSlug(),
                'is_active'  => $r->getCollection()->isActive(),
                'sort_order' => $r->getSortOrder(),
            ], $rows);
        });
        $joinRepo->method('findForProduct')->willReturnCallback(function (int $pid): array {
            $this->track('join.findForProduct');
            $this->events[] = 'read:findForProduct';
            $rows = array_values(array_filter(
                $this->rows,
                static fn (CollectionProduct $r): bool => $r->getProduct()->getId() === $pid,
            ));
            if ($this->afterRead !== null) {
                ($this->afterRead)('findForProduct');
            }
            return $rows;
        });
        $joinRepo->method('countsByCollection')->willReturnCallback(function (array $cids): array {
            $this->track('join.countsByCollection');
            $out = [];
            foreach ($this->rows as $r) {
                $cid = (int) $r->getCollection()->getId();
                if (in_array($cid, $cids, true)) {
                    $out[$cid] = ($out[$cid] ?? 0) + 1;
                }
            }
            return $out;
        });
        $joinRepo->method('maxSortOrders')->willReturnCallback(function (array $cids): array {
            $this->track('join.maxSortOrders');
            $this->events[] = 'read:maxSortOrders';
            $out = [];
            foreach ($this->rows as $r) {
                $cid = (int) $r->getCollection()->getId();
                if (in_array($cid, $cids, true)) {
                    $out[$cid] = max($out[$cid] ?? PHP_INT_MIN, $r->getSortOrder());
                }
            }
            return $out;
        });

        // Own EM mock (not stubEm) so transactions can be counted, rolled
        // back, and every flush checked to have happened INSIDE one.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(function (callable $cb) use ($em): mixed {
            $this->transactions++;
            $this->inTransaction = true;
            $this->snapshot = $this->takeSnapshot();
            try {
                $result = $cb($em);
                $this->applyFlush(); // Doctrine flushes after the callback too
                $this->snapshot = null;
                return $result;
            } catch (\Throwable $e) {
                $this->rollback();
                throw $e;
            } finally {
                $this->inTransaction = false;
            }
        });
        (function ($em) use ($userRepo, $collectionRepo, $productRepo, $joinRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [ProductCollection::class, $collectionRepo],
                [Product::class, $productRepo],
                [CollectionProduct::class, $joinRepo],
            ]);
            $em->method('persist')->willReturnCallback(function (object $e): void {
                if ($e instanceof CollectionProduct && !in_array($e, $this->pendingInserts, true)) {
                    $this->pendingInserts[] = $e;
                }
            });
            $em->method('remove')->willReturnCallback(function (object $e): void {
                if (!$e instanceof CollectionProduct) {
                    return;
                }
                if (in_array($e, $this->pendingInserts, true)) {
                    // persist() then remove() before flush: never written.
                    $this->pendingInserts = array_values(array_filter(
                        $this->pendingInserts,
                        static fn (CollectionProduct $r): bool => $r !== $e,
                    ));
                    return;
                }
                if (!in_array($e, $this->pendingDeletes, true)) {
                    $this->pendingDeletes[] = $e;
                }
            });
            $em->method('flush')->willReturnCallback(function (): void {
                $this->flushes++;
                if (!$this->inTransaction) {
                    $this->flushesOutsideTransaction++;
                }
                $this->applyFlush();
            });
        })($em);
        $this->bind(EntityManagerInterface::class, $em);
    }

    /** @param array<string, mixed> $body */
    private function send(User $user, string $method, string $uri, array $body = []): ResponseInterface
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $this->handle($this->jsonRequest($method, $uri, $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    /**
     * Same order as the real queries: display_order ASC NULLS LAST, then id DESC.
     *
     * @return list<ProductCollection>
     */
    private function orderedCollections(): array
    {
        $all = array_values($this->collections);
        usort($all, static function (ProductCollection $a, ProductCollection $b): int {
            $an = $a->getDisplayOrder() === null ? 1 : 0;
            $bn = $b->getDisplayOrder() === null ? 1 : 0;
            return [$an, $a->getDisplayOrder() ?? 0, -(int) $a->getId()]
                <=> [$bn, $b->getDisplayOrder() ?? 0, -(int) $b->getId()];
        });
        return $all;
    }

    /** The write ran in exactly ONE transaction and every flush happened inside it. */
    private function assertSingleTransaction(): void
    {
        self::assertSame(1, $this->transactions, 'exactly one transaction');
        self::assertGreaterThanOrEqual(1, $this->flushes, 'changes flushed');
        self::assertSame(0, $this->flushesOutsideTransaction, 'no flush outside the transaction');
        self::assertSame(0, $this->rollbacks, 'not rolled back');
    }

    /**
     * Every listed lock was taken before the first event matching $readPrefix
     * (locks first, then the authoritative read).
     */
    private function assertLockedBeforeRead(array $locks, string $readPrefix): void
    {
        $firstRead = null;
        foreach ($this->events as $i => $event) {
            if (str_starts_with($event, $readPrefix)) {
                $firstRead = $i;
                break;
            }
        }
        self::assertNotNull($firstRead, $readPrefix . ' happened; events: ' . implode(', ', $this->events));
        foreach ($locks as $lock) {
            $at = array_search('lock:' . $lock, $this->events, true);
            self::assertNotFalse($at, $lock . ' lock taken; events: ' . implode(', ', $this->events));
            self::assertLessThan($firstRead, $at, $lock . ' lock taken BEFORE ' . $readPrefix);
        }
    }

    /** @return list<string> the lock keys taken, in order */
    private function locksTaken(): array
    {
        return array_values(array_map(
            static fn (string $e): string => substr($e, 5),
            array_filter($this->events, static fn (string $e): bool => str_starts_with($e, 'lock:')),
        ));
    }

    private function acquireLock(string $key): void
    {
        if (!$this->inTransaction) {
            throw new \LogicException('Advisory transaction locks must be taken inside a transaction.');
        }
        $this->events[] = 'lock:' . $key;
        if ($this->onLock !== null) {
            ($this->onLock)($key);
        }
    }

    /** Apply queued writes the way Doctrine's commit does: inserts, then deletes. */
    private function applyFlush(): void
    {
        foreach ($this->pendingInserts as $new) {
            foreach ($this->rows as $existing) {
                if ($existing->getCollection() === $new->getCollection()
                    && $existing->getProduct() === $new->getProduct()) {
                    $this->pendingInserts = [];
                    $this->pendingDeletes = [];
                    throw $this->uniqueViolation();
                }
            }
            $this->forceId($new, CollectionProduct::class, $this->nextRowId++);
            $this->rows[] = $new;
            $this->persisted[] = $new;
        }
        $this->pendingInserts = [];

        foreach ($this->pendingDeletes as $gone) {
            $this->rows = array_values(array_filter(
                $this->rows,
                static fn (CollectionProduct $r): bool => $r !== $gone,
            ));
            $this->removed[] = $gone;
        }
        $this->pendingDeletes = [];
    }

    /** @return array{rows: list<CollectionProduct>, sort: array<int, int>, display: array<int, ?int>} */
    private function takeSnapshot(): array
    {
        $sort = [];
        foreach ($this->rows as $r) {
            $sort[spl_object_id($r)] = $r->getSortOrder();
        }
        $display = [];
        foreach ($this->collections as $id => $c) {
            $display[$id] = $c->getDisplayOrder();
        }
        return ['rows' => $this->rows, 'sort' => $sort, 'display' => $display];
    }

    private function rollback(): void
    {
        $this->rollbacks++;
        $this->pendingInserts = [];
        $this->pendingDeletes = [];
        if ($this->snapshot === null) {
            return;
        }
        $this->rows = $this->snapshot['rows'];
        foreach ($this->rows as $r) {
            $r->setSortOrder($this->snapshot['sort'][spl_object_id($r)]);
        }
        foreach ($this->snapshot['display'] as $id => $order) {
            if (isset($this->collections[$id])) {
                $this->collections[$id]->setDisplayOrder($order);
            }
        }
        $this->snapshot = null;
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        $driver = new class (
            'SQLSTATE[23505]: duplicate key value violates unique constraint "uq_collection_products"',
            '23505',
        ) extends DriverAbstractException {
        };
        return new UniqueConstraintViolationException($driver, null);
    }

    private function track(string $call): void
    {
        $this->repoCalls[$call] = ($this->repoCalls[$call] ?? 0) + 1;
    }

    private function forceId(object $entity, string $class, int $id): void
    {
        $ref = new \ReflectionProperty($class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
