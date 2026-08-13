<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Define node: {def:name=source} -- assign without output.
 *
 * When assignSource is non-null, the value is computed from the source
 * and assigned into context. Returns empty string (no output).
 */
final readonly class DefineNode extends Node
{
    /**
     * @param string[]            $path
     * @param FilterNode[]        $filters
     * @param VariableSource|null $assignSource non-null for path/widget/literal assignment
     */
    public function __construct(
        public array $path,
        public array $filters = [],
        public mixed $value = null,
        public ?VariableSource $assignSource = null,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
