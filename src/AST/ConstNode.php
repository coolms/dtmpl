<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Constant node: {const:name}.
 *
 * Outputs an immutable platform-injected value from the constants registry.
 * Unlike {var:name}, constants cannot be redefined by template code.
 *
 * Examples:
 *   {const:siteName}      resolves to "CoolMS"
 *   {const:currentYear}   resolves to "2026"
 *   {const:counter}       resolves to "1"  (in naming pattern context)
 */
final readonly class ConstNode extends Node
{
    public function __construct(
        public string $name,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
