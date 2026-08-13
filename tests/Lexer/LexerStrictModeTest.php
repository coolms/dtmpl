<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Lexer;

use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Lexer\Token;
use CoolMS\Dtmpl\Lexer\TokenType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Strict tag-detection + did-you-mean coverage for the F.lex-strict
 * Lexer fix. Earlier, every unescaped `{` put the lexer into tag mode
 * and rejected any non-keyword char inside; that broke uploads of
 * docx files containing code samples / JSON / set notation. The new
 * rule is that `{` only starts a tag when the immediately-following
 * letters spell a registered keyword. This file covers all six
 * dispatch branches:
 *
 *   1. `{` + registered-keyword → tag mode (existing happy path)
 *   2. `{` + word that resembles a keyword → SyntaxException + hint
 *   3. `{` + word far from any keyword → literal `{`
 *   4. `{` + non-letter (digit / symbol / whitespace / EOF) → literal
 *   5. `}` outside tag mode → literal, never error
 *   6. `{{` / `}}` → literal `{` / `}` (escape preserved)
 *
 * Plus the strict whitespace rule inside tag mode: the keyword/`:`/
 * first-arg sequence must be adjacent (`{var :name}` and `{var: x}`
 * both throw).
 */
final class LexerStrictModeTest extends TestCase
{
    private Lexer $lexer;

    /**
     * @return iterable<string, array{string}>
     */
    public static function literalPassthroughCases(): iterable
    {
        yield 'php class with brace-bodies' => [
            'class Foo { public function bar() { return 42; } }',
        ];
        yield 'phpdoc block inside interface body' => [
            'interface X { /** @return Y[] */ public function get(): array; }',
        ];
        yield 'json snippet' => [
            '{"key": "value", "n": 42}',
        ];
        yield 'js object literal' => [
            'const x = { foo: 1, bar: 2 };',
        ];
        yield 'set notation' => [
            'A = {1, 2, 3} ∪ {4, 5}',
        ];
        yield 'brace before slash (the original ADR offset-1633 case)' => [
            'EntitySchemaProviderInterface { /** @return EntityClassMetadata[] */',
        ];
        yield 'brace followed by space' => [
            'pre { not a tag } post',
        ];
        yield 'brace followed by digit' => [
            'value {42} more',
        ];
        yield 'brace followed by symbol' => [
            'leading {/foo} trailing',
        ];
        yield 'brace at end of input' => [
            'text ends with {',
        ];
        yield 'standalone closing brace' => [
            'middle } closing brace',
        ];
        yield 'unrelated word in braces' => [
            '{something:foo}',
        ];
        yield 'long unrelated word' => [
            '{foobar:x}',
        ];
        yield 'word two chars longer than nearest keyword' => [
            // 'loopy' length 5 vs 'loop' length 4 -- diff 1, levenshtein 1 → would resemble.
            // Use length-2 diff instead: 'loopyy' vs 'loop' -- not resemblance.
            '{loopyy:items}',
        ];
        yield 'brace then mixed content with closing braces' => [
            // Two-char identifiers chosen deliberately -- single letters
            // now resemble the F5.a.4 `t` keyword (length 1, substitution
            // distance 1) so they would trip the strict-mode hint. `aa`
            // and `bb` are length 2 with no keyword neighbour.
            'A {aa} B {bb} C',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function resemblanceCases(): iterable
    {
        // Tier 1 -- anagram (same length, same chars)
        yield 'anagram of var (vra)' => ['hello {vra:name}', 'var'];
        yield 'anagram of loop (lopo)' => ['{lopo:items}', 'loop'];
        yield 'anagram of if (fi)' => ['{fi:flag}thing{endif}', 'if'];
        yield 'anagram of else (esle)' => ['{if:f}a{esle}b{endif}', 'else'];

        // Tier 2 -- single substitution
        yield 'single substitution of var (vir)' => ['{vir:name}', 'var'];
        yield 'single substitution of loop (lopp)' => ['{lopp:items}', 'loop'];

        // Tier 3 -- single insertion
        yield 'insertion into var (vars)' => ['{vars:name}', 'var'];

        // Tier 3 -- single deletion
        yield 'deletion from var (va)' => ['{va:name}', 'var'];
        yield 'deletion from loop (lop)' => ['{lop:items}', 'loop'];

        // Capitalisation typo: counts as 1-substitution under Levenshtein.
        // Strictly speaking the prompt suggested `{Var:name}` could be
        // literal, but a "Did you mean var?" hint is more useful -- DTMPL
        // is case-sensitive so a capitalised keyword is almost always a
        // typo, not intentional prose.
        yield 'capitalised first letter (Var)' => ['{Var:name}', 'var'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keywordTagCases(): iterable
    {
        yield 'var' => ['{var:name}'];
        yield 'const' => ['{const:siteName}'];
        yield 'if' => ['{if:flag}body{endif}'];
        yield 'unless' => ['{unless:flag}body{endunless}'];
        yield 'else' => ['{if:flag}a{else}b{endif}'];
        yield 'loop' => ['{loop:items}{var:item}{endloop}'];
        yield 'def' => ['{def:x=1}{var:x}'];
        yield 'define' => ['{define:x=1}{var:x}'];
        yield 'widget' => ['{widget:foo}'];
        yield 'slot' => ['{slot:default}fallback{endslot}'];
        yield 'fill' => ['{fill:default}content{endfill}'];
        yield 'include' => ['{include:other}'];
    }

    // ── 1. Literal `{` survives in code samples / JSON / prose ─────────

    #[Test]
    #[DataProvider('literalPassthroughCases')]
    public function inputPassesThroughAsLiteralText(string $input): void
    {
        self::assertSame($input, $this->renderText($this->lexer->tokenize($input)));
    }

    // ── 2. Resemblance throws with did-you-mean ─────────────────────────

    #[Test]
    #[DataProvider('resemblanceCases')]
    public function resemblingKeywordThrowsWithSuggestion(string $input, string $expectedHint): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/Did you mean `' . preg_quote($expectedHint, '/') . '`/');
        $this->lexer->tokenize($input);
    }

    // ── 3. Registered keywords still parse as tags ──────────────────────

    #[Test]
    #[DataProvider('keywordTagCases')]
    public function registeredKeywordEntersTagMode(string $input): void
    {
        $tokens = $this->lexer->tokenize($input);
        // First non-text token after any leading text should be OpenBrace.
        $hasOpenBrace = false;
        foreach ($tokens as $t) {
            if (TokenType::OpenBrace === $t->type) {
                $hasOpenBrace = true;
                break;
            }
        }
        self::assertTrue($hasOpenBrace, 'Expected at least one OpenBrace token for registered keyword');
    }

    // ── 4. `}` outside tag mode is always literal ───────────────────────

    #[Test]
    public function standaloneClosingBraceIsLiteral(): void
    {
        $input = 'plain text } more text';
        $tokens = $this->lexer->tokenize($input);
        self::assertSame($input, $this->renderText($tokens));
    }

    #[Test]
    public function multipleClosingBracesAreLiteral(): void
    {
        $input = 'a } b }} c }';
        $tokens = $this->lexer->tokenize($input);
        // }} → literal }, single } → literal }, so 'a } b } c }'
        self::assertSame('a } b } c }', $this->renderText($tokens));
    }

    // ── 5. {{ and }} escapes still work ────────────────────────────────

    #[Test]
    public function doubleOpenBraceProducesLiteralOpen(): void
    {
        $tokens = $this->lexer->tokenize('Use {{var:name}} for variables');
        self::assertSame('Use {var:name} for variables', $this->renderText($tokens));
    }

    #[Test]
    public function doubleCloseBraceProducesLiteralClose(): void
    {
        $tokens = $this->lexer->tokenize('text}}');
        self::assertSame('text}', $this->renderText($tokens));
    }

    // ── 6. Strict whitespace inside tag mode ───────────────────────────

    #[Test]
    public function whitespaceBetweenKeywordAndColonThrows(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/Unexpected whitespace between keyword `var` and `:`/');
        $this->lexer->tokenize('{var :name}');
    }

    #[Test]
    public function whitespaceAfterColonThrows(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/Unexpected whitespace after `:`/');
        $this->lexer->tokenize('{var: name}');
    }

    #[Test]
    public function trailingWhitespaceBeforeClosingBraceIsAllowed(): void
    {
        // `{endif }` has no `:`/arg -- whitespace before `}` is harmless.
        $tokens = $this->lexer->tokenize('{endif }');
        self::assertSame(TokenType::EndIf, $tokens[1]->type);
    }

    #[Test]
    public function whitespaceInsideFilterChainIsAllowed(): void
    {
        // `{var:name uppercase trim}` -- whitespace separates filters
        // after the first arg; this must keep working.
        $tokens = $this->lexer->tokenize('{var:name uppercase trim}');
        $identifiers = array_values(array_filter(
            $tokens,
            static fn (Token $t): bool => TokenType::Identifier === $t->type,
        ));
        self::assertCount(3, $identifiers);
        self::assertSame('name', $identifiers[0]->value);
        self::assertSame('uppercase', $identifiers[1]->value);
        self::assertSame('trim', $identifiers[2]->value);
    }

    #[Test]
    public function argListWithEqualsParses(): void
    {
        $tokens = $this->lexer->tokenize('{loop:items split=`, `}');
        // Expect loop, :, items, split, =, `, `, } -- the lexer emits
        // these as tokens; just sanity-check the keyword and the arg
        // tokens flow through.
        self::assertSame(TokenType::Loop, $tokens[1]->type);
        $idents = array_values(array_filter(
            $tokens,
            static fn (Token $t): bool => TokenType::Identifier === $t->type,
        ));
        self::assertSame('items', $idents[0]->value);
        self::assertSame('split', $idents[1]->value);
    }

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Concatenate all Text-token values in order. Use only for
     * literal-passthrough assertions.
     *
     * @param Token[] $tokens
     */
    private function renderText(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $token) {
            if (TokenType::Text === $token->type) {
                $out .= $token->value;
            }
        }

        return $out;
    }
}
