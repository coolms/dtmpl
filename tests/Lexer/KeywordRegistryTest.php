<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Lexer;

use CoolMS\Dtmpl\Lexer\KeywordRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit cover for the registry that drives the lexer's strict tag-detection
 * pass -- the lexer asks two questions: "is this a keyword?" and "does it
 * resemble one?". Both are pure functions, no fixtures needed.
 */
final class KeywordRegistryTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function keywordCases(): iterable
    {
        foreach (KeywordRegistry::KEYWORDS as $kw) {
            yield $kw => [$kw];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonKeywordCases(): iterable
    {
        yield 'empty' => [''];
        yield 'capitalised' => ['Var'];   // case-sensitive
        yield 'unrelated word' => ['something'];
        yield 'partial match' => ['va'];    // resembles, but not registered
        yield 'partial match (long)' => ['vars'];
    }

    #[Test]
    #[DataProvider('keywordCases')]
    public function isKeywordRecognisesRegisteredIdentifiers(string $candidate): void
    {
        self::assertTrue(KeywordRegistry::isKeyword($candidate));
    }

    #[Test]
    #[DataProvider('nonKeywordCases')]
    public function isKeywordRejectsUnknownIdentifiers(string $candidate): void
    {
        self::assertFalse(KeywordRegistry::isKeyword($candidate));
    }

    // ── Resemblance: anagram (Tier 1) ──────────────────────────────────

    #[Test]
    public function anagramOfVarReturnsVar(): void
    {
        self::assertSame('var', KeywordRegistry::findResemblance('vra'));
    }

    #[Test]
    public function anagramOfLoopReturnsLoop(): void
    {
        self::assertSame('loop', KeywordRegistry::findResemblance('lopo'));
    }

    // ── Resemblance: single substitution (Tier 2) ──────────────────────

    #[Test]
    public function singleSubstitutionOfVarReturnsVar(): void
    {
        self::assertSame('var', KeywordRegistry::findResemblance('vir'));
    }

    #[Test]
    public function singleSubstitutionOfIfReturnsIf(): void
    {
        self::assertSame('if', KeywordRegistry::findResemblance('it'));
    }

    // ── Resemblance: single insertion / deletion (Tier 3) ──────────────

    #[Test]
    public function singleInsertionOfVarReturnsVar(): void
    {
        self::assertSame('var', KeywordRegistry::findResemblance('vars'));
    }

    #[Test]
    public function singleDeletionOfVarReturnsVar(): void
    {
        self::assertSame('var', KeywordRegistry::findResemblance('va'));
    }

    #[Test]
    public function singleDeletionOfLoopReturnsLoop(): void
    {
        self::assertSame('loop', KeywordRegistry::findResemblance('lop'));
    }

    // ── Resemblance: no hit ────────────────────────────────────────────

    #[Test]
    public function emptyCandidateReturnsNull(): void
    {
        self::assertNull(KeywordRegistry::findResemblance(''));
    }

    #[Test]
    public function unrelatedWordReturnsNull(): void
    {
        self::assertNull(KeywordRegistry::findResemblance('something'));
    }

    #[Test]
    public function exactKeywordReturnsItself(): void
    {
        // `count_chars` of var equals count_chars of var → tier-1 hit.
        // The function returns the matching keyword either way; useful
        // for callers that want "did you mean" even on an exact lookup
        // (though the lexer's isKeyword check intercepts that path).
        self::assertSame('var', KeywordRegistry::findResemblance('var'));
    }

    #[Test]
    public function differenceOfTwoCharsReturnsNull(): void
    {
        // 'loopyy' vs 'loop' -- len diff 2 → no tier matches.
        self::assertNull(KeywordRegistry::findResemblance('loopyy'));
    }
}
