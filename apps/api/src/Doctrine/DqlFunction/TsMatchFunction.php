<?php

declare(strict_types=1);

namespace Bayti\Api\Doctrine\DqlFunction;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function: TSMATCH(tsvector_column, query_string) → BOOLEAN.
 *
 * Compiles to PostgreSQL's `<column> @@ websearch_to_tsquery('english',
 * <query>)`. Used in WHERE clauses for fulltext search:
 *
 *   ->andWhere('TSMATCH(p.searchTsv, :q) = TRUE')
 *   ->setParameter('q', $userQuery)
 *
 * Why websearch_to_tsquery vs to_tsquery vs plainto_tsquery
 * =========================================================
 * `websearch_to_tsquery` is the right choice for user-supplied search
 * input — it gracefully tolerates input that other tsquery functions
 * choke on:
 *   - quoted phrases ("silk abaya") become phrase queries
 *   - "or" between words becomes an OR
 *   - leading minus excludes terms (silk -abaya)
 *   - no special-char escaping needed (won't throw on punctuation)
 *
 * `to_tsquery` requires manually-built operator strings; `plainto_tsquery`
 * doesn't support exclusion or phrases. Web-style search semantics
 * match user expectations and are the obvious default for a consumer
 * marketplace search box.
 *
 * Locale
 * ======
 * Hardcoded to 'english'. Multilingual catalogs need a per-row config
 * choice (Arabic uses 'arabic' or 'simple'). M3.1.5.5 deliberately
 * scopes to English; multilingual is M4+.
 *
 * Registered via config/doctrine.php → addCustomStringFunction.
 */
final class TsMatchFunction extends FunctionNode
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
            "(%s @@ websearch_to_tsquery('english', %s))",
            $this->tsvectorExpression->dispatch($sqlWalker),
            $this->queryExpression->dispatch($sqlWalker),
        );
    }
}
