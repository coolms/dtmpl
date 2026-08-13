<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Filter node: php.strtoupper, currency:`USD`.
 */
final readonly class FilterNode extends Node
{
    /**
     * @param array<int, mixed> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
