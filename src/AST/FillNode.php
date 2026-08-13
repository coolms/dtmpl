<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Fill node: {fill:name}...{endfill}.
 *
 * Defines content to be injected into a named slot of an included partial.
 * FillNodes appear only as children of IncludeNode and are never executed directly.
 */
final readonly class FillNode extends Node
{
    /**
     * @param Node[] $body
     */
    public function __construct(
        public string $name,
        public array $body = [],
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
