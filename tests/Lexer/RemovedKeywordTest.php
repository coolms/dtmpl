<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Lexer;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\RemovedKeywordException;
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Exception\TemplateException;
use CoolMS\Dtmpl\Lexer\KeywordRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Spellings listed in {@see KeywordRegistry::REMOVED} are refused, with
 * the replacement named in the message.
 *
 * Two rules the whole mechanism rests on:
 *
 *   - A removed keyword is never reclaimed as literal text. Without the
 *     map, `{raw}` renders as the characters `{raw}` -- silent, and with
 *     nothing for the author to search for.
 *   - The refusal fires exactly where the old spelling used to work, and
 *     no wider. `{raw}` was a block marker only in its exact form, so
 *     `{raw x}` and `{rawish}` were literal text then and stay literal
 *     text now.
 */
final class RemovedKeywordTest extends TestCase
{
    private DtmplEngine $engine;

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function removedSpellings(): iterable
    {
        yield 'open marker' => ['{raw}x{endraw}', 'raw', 'verbatim'];
        yield 'open marker alone' => ['{raw}', 'raw', 'verbatim'];
        yield 'close marker with no open block' => ['A{endraw}B', 'endraw', 'endverbatim'];
        yield 'close marker mid-template' => ['<p>{endraw}</p>', 'endraw', 'endverbatim'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stillLiteralText(): iterable
    {
        // Each of these failed the old marker's exact-form check, so none
        // of them was ever a block. Widening the refusal to cover them
        // would reject template text that is valid today.
        yield 'trailing space' => ['{raw }text'];
        yield 'with an argument' => ['{raw something}'];
        yield 'longer word' => ['{rawish}'];
        yield 'longer close word' => ['{endrawish}'];
        yield 'no closing brace' => ['{raw'];
    }

    #[Test]
    #[DataProvider('removedSpellings')]
    public function aRemovedSpellingIsRefused(string $template, string $old, string $replacement): void
    {
        $this->expectException(RemovedKeywordException::class);
        $this->expectExceptionMessage(sprintf('`{%s}` was renamed to `{%s}` in DTMPL 2.0.', $old, $replacement));

        $this->engine->compile($template);
    }

    #[Test]
    #[DataProvider('removedSpellings')]
    public function theMessageNamesBothTheBlockAndTheFilter(string $template, string $old, string $replacement): void
    {
        // The point of the whole rename: an author who reaches for
        // `{raw}` is as likely to have wanted the encoding opt-out as the
        // verbatim block. The error is where they are most receptive to
        // being told the two are different things.
        try {
            $this->engine->compile($template);
            self::fail('expected a RemovedKeywordException');
        } catch (RemovedKeywordException $e) {
            self::assertStringContainsString(sprintf('`{%s}` was renamed to `{%s}`', $old, $replacement), $e->getMessage());
            self::assertStringContainsString('{verbatim}', $e->getMessage());
            self::assertStringContainsString('{endverbatim}', $e->getMessage());
            self::assertStringContainsString('The `raw` *filter* is unrelated and unchanged.', $e->getMessage());
        }
    }

    #[Test]
    #[DataProvider('stillLiteralText')]
    public function aNonMarkerSpellingStaysLiteralText(string $template): void
    {
        self::assertSame($template, $this->engine->render($template));
    }

    #[Test]
    public function theErrorIsCatchableAsASyntaxErrorAndAsATemplateError(): void
    {
        // Consistent with UnknownFilterException: a host catches every
        // engine failure as one category, and every syntax failure as a
        // narrower one, without knowing this class exists.
        self::assertInstanceOf(SyntaxException::class, $this->capture('{raw}'));
        self::assertInstanceOf(TemplateException::class, $this->capture('{raw}'));
    }

    #[Test]
    public function theErrorCarriesTheLineAndColumnOfTheOffendingMarker(): void
    {
        $error = $this->capture("line one\n  {raw}\n");

        self::assertSame(2, $error->row);
        self::assertSame(3, $error->column);
    }

    #[Test]
    public function aRemovedSpellingInsideAVerbatimBlockIsJustText(): void
    {
        // The block's interior is never tokenized, so a template
        // documenting the migration can quote the old spelling.
        self::assertSame('{raw}', $this->engine->render('{verbatim}{raw}{endverbatim}'));
    }

    #[Test]
    public function theRawFilterIsUntouchedByTheRename(): void
    {
        // The two names no longer collide: one is gone from the tag
        // vocabulary, the other still resolves and still applies.
        self::assertSame('<b>hi</b>', $this->engine->render('{var:x raw}', ['x' => '<b>hi</b>']));
        self::assertSame('&lt;b&gt;hi&lt;/b&gt;', $this->engine->render('{var:x}', ['x' => '<b>hi</b>']));
        self::assertTrue($this->engine->getFilters()->has('raw'));
    }

    #[Test]
    public function bothSpellingsAreRegisteredAsRemoved(): void
    {
        self::assertNotNull(KeywordRegistry::findRemoved('raw'));
        self::assertNotNull(KeywordRegistry::findRemoved('endraw'));
        self::assertNull(KeywordRegistry::findRemoved('verbatim'));
        self::assertNull(KeywordRegistry::findRemoved('var'));
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }

    private function capture(string $template): RemovedKeywordException
    {
        try {
            $this->engine->compile($template);
        } catch (RemovedKeywordException $e) {
            return $e;
        }

        self::fail('expected a RemovedKeywordException');
    }
}
