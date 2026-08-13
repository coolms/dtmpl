<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Conditional node: {if:user.isPremium}...{endif}.
 */
final readonly class ConditionalNode extends Node
{
    /**
     * @param Node[] $thenBody
     * @param Node[] $elseBody
     *
     * When $onOwnLine is true, the node was structurally located on
     * its own line between newline-bearing siblings; the runtime
     * collapses the surrounding line when the node renders empty
     */
    public function __construct(
        public Node $condition,
        public array $thenBody,
        public array $elseBody = [],
        public bool $negate = false,
        int $line = 0,
        int $column = 0,
        public bool $onOwnLine = false,
    ) {
        parent::__construct($line, $column);
    }
}
