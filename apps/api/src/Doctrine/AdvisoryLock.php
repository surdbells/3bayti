<?php

declare(strict_types=1);

namespace Bayti\Api\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * PostgreSQL transaction-scoped advisory locks (pg_advisory_xact_lock).
 *
 * Used to serialise multi-row admin writes that a plain row lock cannot cover
 * (e.g. rows that do not exist yet, or a rewrite of a whole table's order).
 * The lock blocks until granted and is released automatically at COMMIT /
 * ROLLBACK, so it must be taken INSIDE a transaction; outside one it would be
 * released straight away, which is a silent bug, so that is a LogicException.
 *
 * Keys use the two-int4 form: (namespace, id). The id is folded into the
 * int4 range; a collision only makes two keys share one lock (extra
 * serialisation, never a correctness problem). Advisory locks never conflict
 * with row locks, so they cannot deadlock against ordinary UPDATEs.
 */
final class AdvisoryLock
{
    public const SQL = 'SELECT pg_advisory_xact_lock(CAST(? AS integer), CAST(? AS integer))';

    public static function forTransaction(Connection $conn, int $namespace, int $id): void
    {
        if (!$conn->isTransactionActive()) {
            throw new \LogicException('Advisory transaction locks must be taken inside a transaction.');
        }
        $conn->executeQuery(
            self::SQL,
            [$namespace & 0x7FFFFFFF, $id & 0x7FFFFFFF],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        )->free();
    }
}
