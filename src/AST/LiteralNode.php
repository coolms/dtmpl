<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Literal node (string, number, boolean).
 */
final readonly class LiteralNode extends Node
{
    public function __construct(
        public mixed $value,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
