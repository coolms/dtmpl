<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Lexer;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Lexer\TokenType;
use CoolMS\Dtmpl\Runtime\OutputMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `{comment}...{endcomment}` and `{comment:...}`.
 *
 * The property that matters is negative: the contents never reach the output,
 * in EITHER output mode. A comment is where an author writes down what they did
 * not want visible -- an HTML comment leaks in exactly the wrong direction, and
 * that is the hole this closes.
 */
final class LexerCommentTest extends TestCase
{
    private const string SECRET = 'do-not-ship-this-sentence';

    private Lexer $lexer;

    #[Test]
    public function aBlockCommentEmitsNoTokens(): void
    {
        $tokens = $this->lexer->tokenize('{comment}' . self::SECRET . '{endcomment}');

        // Nothing but EOF: not an empty Text token, no token at all.
        self::assertCount(1, $tokens);
        self::assertTrue($tokens[0]->is(TokenType::Eof));
    }

    #[Test]
    public function anInlineCommentEmitsNoTokens(): void
    {
        $tokens = $this->lexer->tokenize('{comment:' . self::SECRET . '}');

        self::assertCount(1, $tokens);
        self::assertTrue($tokens[0]->is(TokenType::Eof));
    }

    #[Test]
    public function theContentsReachNeitherOutputMode(): void
    {
        // ⚠️ The test this file exists for. `Text` mode turns ENCODING off, so
        // a comment stripped by an encoder rather than by the lexer would
        // survive here -- which is why both modes are asserted rather than
        // trusting that one implies the other.
        $template = 'A{comment}' . self::SECRET . '{endcomment}B{comment:' . self::SECRET . '}C';

        foreach ([OutputMode::Html, OutputMode::Text] as $mode) {
            $out = new DtmplEngine(outputMode: $mode)->render($template, []);

            self::assertSame('ABC', $out, $mode->name . ' must emit nothing for a comment');
            self::assertStringNotContainsString(self::SECRET, $out, $mode->name . ' leaked the comment body');
        }
    }

    #[Test]
    public function aCommentedOutRegionIsNeverParsed(): void
    {
        // The common use: commenting out a chunk while debugging. That chunk is
        // usually broken -- half-written tags, unbalanced braces -- and none of
        // it may reach the parser.
        $broken = '{if:x}{var:  }{loop:}{unknownkeyword:!}{{ }}';

        self::assertSame('ok', new DtmplEngine()->render('{comment}' . $broken . '{endcomment}ok', []));
    }

    #[Test]
    public function theFirstEndcommentTerminates(): void
    {
        // Comments do not nest, for the reason verbatim blocks do not: the
        // scanner would have to parse the body it is deliberately not parsing.
        self::assertSame('after', new DtmplEngine()->render('{comment}a{endcomment}after', []));
    }

    #[Test]
    public function nearMissesAreOrdinaryText(): void
    {
        // The exact-form rule the verbatim marker already uses: only `{comment}`
        // opens a block, so prose and code containing the word survive.
        foreach (['{commentary}', '{comment foo}', '{comments}'] as $input) {
            self::assertSame($input, new DtmplEngine()->render($input, []), $input . ' must stay literal');
        }
    }

    #[Test]
    public function aCommentMarkerInsideVerbatimSurvivesAsText(): void
    {
        // Verbatim is checked first, so documentation showing the comment
        // syntax renders the sample rather than swallowing it -- which is what
        // the DTMPL docs page needs in order to document this feature at all.
        $sample = '{comment}not a real comment{endcomment}';

        self::assertSame($sample, new DtmplEngine()->render('{verbatim}' . $sample . '{endverbatim}', []));
    }

    #[Test]
    public function anUnclosedBlockIsAHardError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/Unclosed `\{comment\}`/');
        $this->lexer->tokenize('{comment}forever');
    }

    #[Test]
    public function anUnclosedInlineCommentIsAHardError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/Unclosed `\{comment:`/');
        $this->lexer->tokenize('{comment:forever');
    }

    #[Test]
    public function anInlineCommentStopsAtTheFirstBrace(): void
    {
        // Documented limit rather than a surprise: the body is never parsed, so
        // there is no brace tracking to find a later terminator. What follows
        // the first `}` is ordinary text.
        self::assertSame('x}y', new DtmplEngine()->render('{comment:a}x}y', []));
    }

    #[Test]
    public function surroundingTextIsUntouched(): void
    {
        self::assertSame(
            'before after',
            new DtmplEngine()->render('before {comment}gone{endcomment}after', []),
        );
    }

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }
}
