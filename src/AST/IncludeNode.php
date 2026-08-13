<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Include node: {include:`path/to/partial`} or {include:`path`}...{endinclude}.
 *
 * When $fills is empty, this is a simple inline include (no slot filling).
 * When $fills is non-empty, the included partial receives slot fills.
 * $params are literal key=value pairs overlaid onto the child context at render time.
 */
final readonly class IncludeNode extends Node
{
    /**
     * @param FillNode[]          $fills  Fill blocks defined between {include} and {endinclude}
     * @param array<string,mixed> $params literal key=value params merged into child context
     *
     * When $onOwnLine is true, the node was structurally located on
     * its own line between newline-bearing siblings; the runtime
     * collapses the surrounding line when the include renders empty
     */
    public function __construct(
        public string $templatePath,
        public array $fills = [],
        public array $params = [],
        int $line = 0,
        int $column = 0,
        public bool $onOwnLine = false,
    ) {
        parent::__construct($line, $column);
    }
}
