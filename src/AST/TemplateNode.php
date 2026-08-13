<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Template root node.
 */
final readonly class TemplateNode extends Node
{
    /**
     * @param Node[] $children
     */
    public function __construct(
        public array $children = [],
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
