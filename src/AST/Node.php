<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Base AST Node.
 */
abstract readonly class Node
{
    public function __construct(
        public int $line = 0,
        public int $column = 0,
    ) {
    }
}
