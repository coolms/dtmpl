<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Comparison node: `{if:age>18}`, `{if:status!=`draft`}`.
 *
 * Operator codes: eq, ne, gt, lt, ge, le. RHS is either a scalar
 * literal (string, int, float, bool) or a VariableNode for path-
 * based comparison.
 */
final readonly class ComparisonNode extends Node
{
    public function __construct(
        public VariableNode $left,
        public string $operator, // eq, ne, lt, le, gt, ge
        public mixed $right,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
