<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Translation AST node -- `{t:`key`}`, `{t:`key`:`domain`}`,
 * `{t:`key`:`domain` placeholder=value ...}`.
 *
 * Resolved at runtime through Symfony Translator. Params are wrapped
 * as `%name%` keys before passing to `trans()` so a DTMPL template
 * author writes `count=3` and a catalog entry containing `%count%`
 * matches.
 *
 * Domain defaults to `messages` when unset (Symfony convention).
 *
 * `$raw` carries the bare `raw` flag -- `{t:`key` raw}` -- which opts the
 * catalogue entry out of output encoding. A flag rather than a filter
 * because `{t:}` has no filter-chain position in the grammar: its tail is
 * a `key=value` param list.
 */
final readonly class TranslateNode extends Node
{
    /**
     * @param array<string, mixed> $params placeholder name -> value, sans `%` wrappers
     * @param bool                 $raw    emit the translated string as markup instead of encoding it
     */
    public function __construct(
        public string $key,
        public ?string $domain = null,
        public array $params = [],
        public bool $raw = false,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }
}
