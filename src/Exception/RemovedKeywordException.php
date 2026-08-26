<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Exception;

use CoolMS\Dtmpl\Lexer\KeywordRegistry;

/**
 * A template used a keyword that has been renamed or removed.
 *
 * Extends {@see SyntaxException}, so it carries line and column and is
 * catchable as a {@see TemplateException} like every other engine error.
 *
 * Raised from the lexer's `{`-dispatch for any spelling listed in
 * {@see KeywordRegistry::REMOVED}, at exactly the positions where that
 * spelling used to be accepted.
 */
class RemovedKeywordException extends SyntaxException
{
    /**
     * @param array{replacement: string, since: string, hint: string} $entry from {@see KeywordRegistry::REMOVED}
     */
    public static function forKeyword(string $keyword, array $entry, int $row, int $column): self
    {
        return new self(sprintf(
            '`{%s}` was renamed to `{%s}` in DTMPL %s. %s',
            $keyword,
            $entry['replacement'],
            $entry['since'],
            $entry['hint'],
        ), $row, $column);
    }
}
