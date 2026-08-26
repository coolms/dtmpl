<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Runtime\OutputMode;
use CoolMS\Dtmpl\Runtime\RenderedHtml;
use CoolMS\Dtmpl\TemplateLoaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Output encoding, in both directions.
 *
 * There was no test here at all while the engine emitted every value
 * verbatim and the README promised the opposite -- which is how the two
 * stayed apart. Both directions are asserted deliberately: "it encodes"
 * alone would pass on an engine that encoded everything including the
 * markup, and "raw works" alone would pass on the engine that had no
 * encoding to opt out of.
 */
final class OutputEncodingTest extends TestCase
{
    private const string XSS = '<img src=x onerror=alert(1)>';

    private const string XSS_ENCODED = '&lt;img src=x onerror=alert(1)&gt;';

    private DtmplEngine $engine;

    /**
     * Every leaf that puts a context value into the page.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function leaves(): iterable
    {
        yield 'var' => ['{var:x}', ['x' => self::XSS]];
        yield 'var via def' => ['{def:y=x}{var:y}', ['x' => self::XSS]];
        yield 'var with a filter' => ['{var:x php.trim}', ['x' => self::XSS]];
        yield 'var default fallback' => ['{var:missing default:`' . self::XSS . '`}', []];
        yield 'loop item' => ['{loop:xs}{var:item}{endloop}', ['xs' => [self::XSS]]];
        yield 'loop item value' => ['{loop:xs}{var:item.value}{endloop}', ['xs' => ['k' => self::XSS]]];
        yield 'const' => ['{const:C}', ['_const' => ['C' => self::XSS]]];
        yield 'var reached through a path' => ['{var:a.b}', ['a' => ['b' => self::XSS]]];
    }

    public static function xss(): string
    {
        return self::XSS;
    }

    #[Test]
    public function textModeEmitsValuesVerbatim(): void
    {
        // DTMPL also drives things that are not web pages -- a filename
        // pattern, a spreadsheet cell, a `<w:t>` run, a value about to be
        // JSON-encoded into a document. HTML-encoding those corrupts
        // them: it puts `O&#039;Hara` in a Word file and double-encodes
        // what the OOXML writer was about to XML-escape itself.
        $text = new DtmplEngine(outputMode: OutputMode::Text);

        self::assertSame(self::XSS, $text->render('{var:x}', ['x' => self::XSS]));
        self::assertSame("O'Hara", $text->render('{var:x}', ['x' => "O'Hara"]));
        self::assertSame(self::XSS, $text->render('{const:C}', ['_const' => ['C' => self::XSS]]));
    }

    #[Test]
    public function textModeIsNotTheDefault(): void
    {
        // The pair is the point: asserting text mode alone would also
        // pass on an engine that had stopped encoding entirely.
        self::assertSame(self::XSS_ENCODED, $this->engine->render('{var:x}', ['x' => self::XSS]));
    }

    #[Test]
    public function withOutputModeCarriesTheRestOfTheConfiguration(): void
    {
        // withLoader() once dropped the translator when it rebuilt the
        // engine by listing its arguments, and `{t:}` went inert in
        // exactly the templates it exists for. Both clones now go
        // through one place; this holds them to it.
        $loader = new class implements TemplateLoaderInterface {
            public function load(string $path, string $basePath = ''): string
            {
                return 'PARTIAL[{var:x}]';
            }

            public function resolve(string $path, string $basePath = ''): string
            {
                return $path;
            }

            public function supports(string $path, string $basePath = ''): bool
            {
                return true;
            }
        };

        $engine = new DtmplEngine(loader: $loader)->withOutputMode(OutputMode::Text);

        // The loader survived the clone, and the new mode took effect.
        self::assertSame('PARTIAL[<b>]', $engine->render('{include:`p`}', ['x' => '<b>']));

        // And the reverse direction: withLoader() keeps the output mode.
        $back = new DtmplEngine(outputMode: OutputMode::Text)->withLoader($loader);
        self::assertSame('PARTIAL[<b>]', $back->render('{include:`p`}', ['x' => '<b>']));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[Test]
    #[DataProvider('leaves')]
    public function everyEmittedValueIsEncoded(string $template, array $data): void
    {
        self::assertSame(self::XSS_ENCODED, $this->engine->render($template, $data));
    }

    #[Test]
    public function theRawFilterIsTheOptOut(): void
    {
        self::assertSame(self::XSS, $this->engine->render('{var:x raw}', ['x' => self::XSS]));
    }

    #[Test]
    public function literalTemplateTextIsNotEncoded(): void
    {
        // The author's own markup is the page. Encoding it would mean
        // the engine renders the template as source, which is the
        // failure mode a blanket "encode everything" produces.
        self::assertSame('<p class="x">hi</p>', $this->engine->render('<p class="x">hi</p>'));
    }

    #[Test]
    public function aRenderedFragmentIsNotReEncoded(): void
    {
        // A slot's default body is template output, not a value.
        self::assertSame('<b>none</b>', $this->engine->render('{slot:s}<b>none</b>{endslot}'));
    }

    #[Test]
    public function quotesAreEncodedSoAValueIsSafeInAnAttribute(): void
    {
        self::assertSame(
            '<a title="say &quot;hi&quot; &amp; go">t</a>',
            $this->engine->render('<a title="{var:x}">t</a>', ['x' => 'say "hi" & go']),
        );
    }

    #[Test]
    public function invalidUtf8IsSubstitutedNotSwallowed(): void
    {
        // htmlspecialchars() without ENT_SUBSTITUTE returns '' for
        // invalid UTF-8, so one bad byte in one row would delete the
        // whole value from the page with no error anywhere.
        $output = $this->engine->render('{var:x}', ['x' => "ok\xC3(bad)"]);

        self::assertStringContainsString('ok', $output);
        self::assertStringContainsString('(bad)', $output);
    }

    #[Test]
    public function aHostSuppliedRenderedHtmlPassesThrough(): void
    {
        // The documented way for an application to hand a template
        // trusted markup without the template remembering `raw`.
        self::assertSame(
            '<em>trusted</em>',
            $this->engine->render('{var:x}', ['x' => new RenderedHtml('<em>trusted</em>')]),
        );
    }

    #[Test]
    public function aPlainStringableIsAValueNotMarkup(): void
    {
        // Fail-closed. An entity or value object that happens to
        // implement __toString() is exactly the kind of thing carrying
        // user input; inheriting a method must not bypass encoding.
        $value = new class implements Stringable {
            public function __toString(): string
            {
                return OutputEncodingTest::xss();
            }
        };

        self::assertSame(self::XSS_ENCODED, $this->engine->render('{var:x}', ['x' => $value]));
    }

    #[Test]
    public function escapeDoesNotEncodeTwice(): void
    {
        self::assertSame(self::XSS_ENCODED, $this->engine->render('{var:x escape}', ['x' => self::XSS]));
    }

    #[Test]
    public function safetyPropagatesAlongTheFilterChain(): void
    {
        // The real theme chain: encode the body, then turn its newlines
        // into <br />. The tags nl2br adds must survive.
        self::assertSame(
            "a&lt;b&gt;<br />\nc",
            $this->engine->render('{var:x escape php.nl2br}', ['x' => "a<b>\nc"]),
        );
    }

    #[Test]
    public function rawSurvivesALaterFilter(): void
    {
        self::assertSame('<B>HI</B>', $this->engine->render('{var:x raw uppercase}', ['x' => '<b>hi</b>']));
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }
}
