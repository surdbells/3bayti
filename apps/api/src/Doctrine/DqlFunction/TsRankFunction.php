<?php

declare(strict_types=1);

namespace Bayti\Api\Doctrine\DqlFunction;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function: TSRANK(tsvector_column, query_string) → FLOAT.
 *
 * Compiles to PostgreSQL's `ts_rank(<column>, websearch_to_tsquery(
 * 'english', <query>))`. Used in ORDER BY clauses for relevance-based
 * sorting:
 *
 *   ->orderBy('TSRANK(p.searchTsv, :q)', 'DESC')
 *
 * Companion to TsMatchFunction. The two functions use the same
 * websearch_to_tsquery semantics so the WHERE and ORDER BY agree on
 * what "matches the query".
 *
 * Registered alongside TsMatchFunction via
 * config/doctrine.php → addCustomNumericFunction.
 */
final class TsRankFunction extends FunctionNode
{
    private Node $tsvectorExpression;
    private Node $queryExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->tsvectorExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->queryExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            "ts_rank(%s, websearch_to_tsquery('english', %s))",
            $this->tsvectorExpression->dispatch($sqlWalker),
            $this->queryExpression->dispatch($sqlWalker),
        );
    }
}
