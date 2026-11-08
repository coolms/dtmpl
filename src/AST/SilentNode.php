<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Where a construct that renders nothing at its own position was written: a comment, or a `{css}` / `{js}` asset
 * block, whose body is gathered elsewhere. It renders nothing; it marks the place, so that a line holding nothing but
 * it collapses like a `{def:}` line rather than leaving its newline and indentation behind.
 */
final readonly class SilentNode extends Node
{
    public const string COMMENT = 'comment';

    public const string ASSET = 'asset';

    public function __construct(
        public string $kind,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
