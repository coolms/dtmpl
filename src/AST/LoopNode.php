<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Loop node: {loop:items}...{endloop}.
 */
final readonly class LoopNode extends Node
{
    /**
     * @param string[] $path
     * @param Node[]   $body
     *
     * When $onOwnLine is true, the node was structurally located on
     * its own line between newline-bearing siblings; the runtime
     * collapses the surrounding line when the node renders empty
     */
    public function __construct(
        public array $path,
        public array $body,
        public string $itemName = 'item',
        public bool $odd = false,
        public bool $even = false,
        public ?string $split = null,
        int $line = 0,
        int $column = 0,
        public bool $onOwnLine = false,
    ) {
        parent::__construct($line, $column);
    }
}
