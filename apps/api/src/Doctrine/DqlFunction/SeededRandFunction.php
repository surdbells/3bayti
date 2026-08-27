<?php

declare(strict_types=1);

namespace Bayti\Api\Doctrine\DqlFunction;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function: SEEDED_RAND(seed, id) → TEXT.
 *
 * Compiles to PostgreSQL's
 *   md5(<seed> || '-' || <id>)
 *
 * A deterministic, per-seed shuffle key. Used in ORDER BY to present a
 * product listing (the EXPLORE feed) in a "random-looking" but STABLE
 * order for a given seed:
 *
 *   ->orderBy('SEEDED_RAND(:seed, p.id)', 'ASC')
 *   ->setParameter('seed', (string) $seed)
 *
 * Why this composes with LIMIT/OFFSET
 * ===================================
 * The md5 key is a pure function of (seed, id). For a fixed seed the key
 * of every row is fixed, so the total order over the whole result set is
 * fixed too. Paging with LIMIT/OFFSET therefore walks a single stable
 * order, page 2 never re-shuffles, so infinite scroll never duplicates
 * or skips a product. Change the seed and the whole order changes.
 *
 * md5() returns a 32-char hex TEXT; ordering lexicographically over that
 * hash is effectively uniform, which is what makes the order look random.
 * Registered as a string function (DQL has no dedicated hash category),
 * exactly like TSMATCH / JSONB_EXISTS_ANY.
 *
 * The `<id>` operand is wrapped in `CAST(... AS text)` because Postgres'
 * `||` needs a text operand and the id is an integer column; the seed is
 * bound as a string parameter so it is already text.
 *
 * PostgreSQL-specific (md5). Locked: PostgreSQL is the production DB.
 *
 * Registered via config/doctrine.php → addCustomStringFunction.
 */
final class SeededRandFunction extends FunctionNode
{
    private Node $seedExpression;
    private Node $idExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->seedExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->idExpression = $parser->ArithmeticExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            "md5(%s || '-' || CAST(%s AS text))",
            $this->seedExpression->dispatch($sqlWalker),
            $this->idExpression->dispatch($sqlWalker),
        );
    }
}
