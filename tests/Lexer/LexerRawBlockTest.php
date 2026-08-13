<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Lexer;

use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Lexer\Token;
use CoolMS\Dtmpl\Lexer\TokenType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `{raw}...{endraw}` verbatim-block coverage. The interior is emitted as
 * one literal Text token and never tokenized, so `<script>`/`<style>`
 * blocks, JSON, minified code, and DTMPL syntax samples survive intact --
 * the escape hatch the single-brace `{{`/`}}` escape couldn't give a
 * whole region. The FIRST `{endraw}` terminates; an unterminated block
 * is a hard error.
 */
final class LexerRawBlockTest extends TestCase
{
    private Lexer $lexer;

    #[Test]
    public function scriptWithBracesSurvivesVerbatim(): void
    {
        $js = "<script>(function(){var x={a:1};if(x.a){console.log('hi')}})();</script>";
        $input = "{raw}$js{endraw}";

        // The interior is reproduced byte-for-byte; the markers vanish.
        self::assertSame($js, $this->renderText($this->lexer->tokenize($input)));
    }

    #[Test]
    public function dtmplKeywordAdjacenciesInsideRawAreLiteral(): void
    {
        // Every one of these would mis-lex or throw OUTSIDE a raw block:
        // `{if:` is a tag, `${t}` hits the single-letter translate keyword,
        // `}else{` glues a brace to a keyword.
        $tricky = 'if(a){var b=1}else{b=2} `${t}` {if:x}{var:y}';
        $input = "{raw}$tricky{endraw}";

        self::assertSame($tricky, $this->renderText($this->lexer->tokenize($input)));
        // And nothing inside became a real tag.
        self::assertSame(0, $this->countTagTokens($this->lexer->tokenize($input)));
    }

    #[Test]
    public function firstEndrawTerminates(): void
    {
        $input = '{raw}A{endraw}B{raw}C{endraw}';

        // 'A' and 'C' are raw interiors; 'B' is ordinary literal text
        // between two blocks. All three are Text; no tags anywhere.
        self::assertSame('ABC', $this->renderText($this->lexer->tokenize($input)));
        self::assertSame(0, $this->countTagTokens($this->lexer->tokenize($input)));
    }

    #[Test]
    public function tagsOutsideRawStillTokenizeWhileInteriorStaysLiteral(): void
    {
        $tokens = $this->lexer->tokenize('{var:before}{raw}{var:inside}{endraw}{var:after}');

        // Two real {var:...} tags (before + after); the middle is literal.
        self::assertSame(2, $this->countKeyword($tokens, TokenType::Var));
        self::assertStringContainsString('{var:inside}', $this->renderText($tokens));
    }

    #[Test]
    public function nearMissEndrawDoesNotTerminateEarly(): void
    {
        // `{endrawer}` is not the terminator; the real `{endraw}` is.
        $input = '{raw}keep {endrawer} going{endraw}';

        self::assertSame('keep {endrawer} going', $this->renderText($this->lexer->tokenize($input)));
    }

    #[Test]
    public function unclosedRawBlockThrows(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('Unclosed `{raw}` block');
        $this->lexer->tokenize('{raw}<script>oops</script>');
    }

    #[Test]
    public function nonExactRawMarkerIsLiteralNotABlock(): void
    {
        // `{raw }` (trailing space) and `{rawish}` are NOT the verbatim
        // marker -- they fall through to ordinary literal text, and the
        // `{endraw}` that follows is itself just literal text (no open
        // block to close), so nothing is consumed as a block.
        $input = '{raw }text';

        self::assertSame($input, $this->renderText($this->lexer->tokenize($input)));
    }

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    /** @param Token[] $tokens */
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

    /**
     * Count keyword-introducing tokens (the tag-opening keywords) -- a
     * proxy for "did any real DTMPL tag get produced?".
     *
     * @param Token[] $tokens
     */
    private function countTagTokens(array $tokens): int
    {
        $tagTypes = [
            TokenType::Var, TokenType::Loop, TokenType::If, TokenType::Widget,
            TokenType::Const, TokenType::Translate, TokenType::Define, TokenType::Include,
        ];
        $n = 0;
        foreach ($tokens as $token) {
            if (in_array($token->type, $tagTypes, true)) {
                ++$n;
            }
        }

        return $n;
    }

    /** @param Token[] $tokens */
    private function countKeyword(array $tokens, TokenType $type): int
    {
        $n = 0;
        foreach ($tokens as $token) {
            if ($token->type === $type) {
                ++$n;
            }
        }

        return $n;
    }
}
