<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Text node (literal content).
 */
final readonly class TextNode extends Node
{
    public function __construct(
        public string $content,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
