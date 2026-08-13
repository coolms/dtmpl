<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Slot node.
 *
 * Declares a named slot in a layout/partial template.
 *
 * Syntax variants:
 *   {slot:name}                       -- self-closing, empty default
 *   {slot:name default=`text`}        -- self-closing, inline string default
 *   {slot:name}...body...{endslot}    -- block default (endslot required)
 *   {slot:name}{endslot}              -- explicit empty close (treated as self-closing)
 *
 * Render priority:
 *   1. Matching {fill:name} content from caller
 *   2. $defaultBody nodes (block between slot tags)
 *   3. $default string (inline attribute)
 *   4. Empty string
 *
 * @property Node[] $defaultBody Default body parsed between {slot:name} and {endslot}.
 */
final readonly class SlotNode extends Node
{
    /**
     * @param Node[] $defaultBody Default body nodes from {slot:name}...{endslot}.
     *
     * When $onOwnLine is true, the node was structurally located on
     * its own line between newline-bearing siblings; the runtime
     * collapses the surrounding line when the slot renders empty.
     */
    public function __construct(
        public string $name,
        public ?string $default = null,
        public array $defaultBody = [],
        int $line = 0,
        int $column = 0,
        public bool $onOwnLine = false,
    ) {
        parent::__construct($line, $column);
    }
}
