<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Variable node: {var:user.name} or {var:name=source filters}.
 *
 * When assignSource is non-null, the value is computed from the source,
 * assigned into the context under $path, then returned as output string.
 */
final readonly class VariableNode extends Node
{
    /**
     * @param string[]            $path
     * @param FilterNode[]        $filters
     * @param VariableSource|null $assignSource non-null means assignment + echo
     */
    public function __construct(
        public array $path,
        public array $filters = [],
        public mixed $default = null,
        public ?VariableSource $assignSource = null,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
