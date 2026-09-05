<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Template root node.
 */
final readonly class TemplateNode extends Node
{
    /**
     * ⚠️ `$assets` is NOT part of `$children` and is never executed. It is the
     * template's `{css}` / `{js}` declarations, lifted out of the flow at parse
     * time so the host can gather them from the whole include tree before
     * rendering starts -- see {@see AssetNode}. Two lists, not one, because
     * the second is metadata about the first and mixing them would put a node
     * the Executor has no case for into the path it walks.
     *
     * @param Node[]      $children
     * @param AssetNode[] $assets
     */
    public function __construct(
        public array $children = [],
        int $line = 0,
        int $column = 0,
        public array $assets = [],
    ) {
        parent::__construct($line, $column);
    }
}
