<?php

declare(strict_types=1);

namespace Bayti\Api\Doctrine\DqlFunction;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL function: JSONB_EXISTS_ANY(jsonb_column, :values) → BOOLEAN.
 *
 * Compiles to PostgreSQL's
 *   jsonb_exists_any(<column>, array[<values>]::text[])
 *
 * Used in WHERE clauses to refine a listing by the "contains ANY of"
 * semantics over a jsonb array column (available_sizes / available_colors):
 *
 *   ->andWhere('JSONB_EXISTS_ANY(p.availableSizes, :sizes) = TRUE')
 *   ->setParameter('sizes', $sizes, ArrayParameterType::STRING)
 *
 * The `:values` parameter MUST be bound with
 * \Doctrine\DBAL\ArrayParameterType::STRING so Doctrine expands the single
 * placeholder into `?, ?, ?` inside the `array[...]` literal — yielding the
 * exact SQL FacetAggregator already emits for the same filter.
 *
 * Why the jsonb_exists_any() FUNCTION and not the `?|` operator
 * ============================================================
 * The `?` in `?|` is parsed by DBAL/PDO as a positional parameter
 * placeholder, so mixing `?|` with bound parameters throws at execute
 * time. The function form carries no `?`, so it binds cleanly. This is
 * the same rationale documented in FacetAggregator::buildAggregationSql.
 *
 * DQL has no boolean function category, so (like TSMATCH) this is
 * registered as a string function and compared to TRUE at the call site.
 *
 * Registered via config/doctrine.php → addCustomStringFunction.
 */
final class JsonbExistsAnyFunction extends FunctionNode
{
    private Node $columnExpression;
    private Node $valuesExpression;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->columnExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->valuesExpression = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'jsonb_exists_any(%s, array[%s]::text[])',
            $this->columnExpression->dispatch($sqlWalker),
            $this->valuesExpression->dispatch($sqlWalker),
        );
    }
}
