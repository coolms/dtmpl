<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Lexer;

/**
 * Closed list of DTMPL tag-introducing keywords plus a "did you mean"
 * resemblance check used by the lexer's strict tag-detection pass.
 *
 * Two responsibilities:
 *
 *   1. Single source of truth for "is this identifier a tag-starter?".
 *      The lexer's `KEYWORDS` map (string → TokenType) needs the
 *      identifier-to-type binding; this registry only needs the
 *      identifier set, so it stays a flat `list<string>` and is kept
 *      alphabetically for human reviewers.
 *
 *   2. Three-tier closeness check so a typo like `{vra:foo}` raises
 *      `Unknown DTMPL keyword ... Did you mean var?` instead of being
 *      silently treated as literal text. The tiers run cheapest-first
 *      and short-circuit on the first hit:
 *         T1 -- anagram        (same length, same multiset of chars)
 *         T2 -- 1 substitution (same length, levenshtein === 1)
 *         T3 -- 1 ins/del      (length diff 1, levenshtein === 1)
 *      Anything farther than that is treated as ordinary text -- a `{`
 *      followed by an unrelated word becomes literal `{`.
 *
 * Pure PHP. No DI, no Symfony -- `levenshtein()` and `count_chars()` are
 * built-in C-native and constant-cost on the keyword sizes we have.
 */
final class KeywordRegistry
{
    /**
     * Tag-introducing keywords. Mirrors the Lexer's internal map; the
     * Lexer keeps the string → TokenType binding because the parser
     * needs it, this registry keeps just the names.
     *
     * @var list<string>
     */
    public const array KEYWORDS = [
        'const',
        'def', 'define',
        'endfill', 'endif', 'endinclude', 'endloop', 'endno', 'endslot', 'endunless',
        'else',
        'fill',
        'if', 'ifno', 'include',
        'item',
        'loop',
        'slot',
        't',
        'unless',
        'var',
        'widget',
    ];

    public static function isKeyword(string $candidate): bool
    {
        return in_array($candidate, self::KEYWORDS, true);
    }

    /**
     * Closest registered keyword if `$candidate` looks like a typo of
     * one. Returns the keyword string on hit, `null` otherwise.
     *
     * Cheap to call per `{` because the keyword list is short and each
     * tier short-circuits; no early-exit by length-bucketing is needed.
     */
    public static function findResemblance(string $candidate): ?string
    {
        return self::closest($candidate, self::KEYWORDS);
    }

    /**
     * The three-tier closeness check, over an arbitrary vocabulary.
     *
     * Extracted so the "did you mean" courtesy is not a privilege of tag
     * keywords: the parser reuses it for loop modifiers, and any future
     * closed vocabulary gets the same behaviour for free rather than a
     * second, subtly different implementation.
     *
     * @param list<string> $vocabulary
     */
    public static function closest(string $candidate, array $vocabulary): ?string
    {
        if ('' === $candidate) {
            return null;
        }

        $candidateLen = strlen($candidate);
        $candidateChars = count_chars($candidate, 1);

        foreach ($vocabulary as $keyword) {
            $keywordLen = strlen($keyword);
            $lenDiff = abs($candidateLen - $keywordLen);

            if (0 === $lenDiff) {
                if ($candidateChars === count_chars($keyword, 1)) {
                    return $keyword;
                }
                if (1 === levenshtein($candidate, $keyword)) {
                    return $keyword;
                }
            } elseif (1 === $lenDiff) {
                if (1 === levenshtein($candidate, $keyword)) {
                    return $keyword;
                }
            }
        }

        return null;
    }
}
