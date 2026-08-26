<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Runtime;

use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Parser\Parser;
use CoolMS\Dtmpl\Runtime\Executor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * exercise the full `{t:`key`}` chain: Lexer tokenizes
 * the directive, Parser produces TranslateNode, Executor calls the
 * injected Translator and substitutes `%name%` placeholders.
 *
 * The translator is stubbed so the test stays at the DTMPL boundary
 * (Symfony Translator has its own coverage).
 */
final class TranslateExecutorTest extends TestCase
{
    #[Test]
    public function translatesKeyWithDefaultDomain(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('hello.world', [], null)
            ->willReturn('Hello, World!');

        $output = $this->render('{t:`hello.world`}', $translator);
        self::assertSame('Hello, World!', $output);
    }

    #[Test]
    public function translatesKeyWithExplicitDomain(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('subscription.denied', [], 'centrifugo')
            ->willReturn('Subscription denied.');

        $output = $this->render('{t:`subscription.denied`:`centrifugo`}', $translator);
        self::assertSame('Subscription denied.', $output);
    }

    #[Test]
    public function wrapsParamsWithPercentSigns(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'subscription.denied',
                ['%channel%' => 'calendar.items.foo'],
                'centrifugo',
            )
            ->willReturn('Subscription to "calendar.items.foo" denied.');

        $output = $this->render(
            '{t:`subscription.denied`:`centrifugo` channel=`calendar.items.foo`}',
            $translator,
        );
        // The param reaches `trans()` raw -- the WHOLE result is encoded
        // once on the way out, so the quotes the catalogue entry itself
        // contains come back as entities.
        self::assertSame('Subscription to &quot;calendar.items.foo&quot; denied.', $output);
    }

    #[Test]
    public function encodesTheTranslatedStringByDefault(): void
    {
        // A catalogue is content, edited by translators. Markup in it is
        // text unless the template asks for markup.
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('<strong>Hello</strong>');

        self::assertSame('&lt;strong&gt;Hello&lt;/strong&gt;', $this->render('{t:`hello`}', $translator));
    }

    #[Test]
    public function rawEmitsTheCatalogueEntryAsMarkup(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('<strong>Hello</strong>');

        self::assertSame('<strong>Hello</strong>', $this->render('{t:`hello` raw}', $translator));
    }

    #[Test]
    public function rawEncodesTheParamsInstead(): void
    {
        // The half that makes `raw` safe to offer at all: trusting the
        // SENTENCE must not trust the VALUES substituted into it.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('greet', ['%name%' => '&lt;script&gt;x&lt;/script&gt;'], null)
            ->willReturn('Hi &lt;script&gt;x&lt;/script&gt;!');

        $output = $this->render('{t:`greet` raw name=`<script>x</script>`}', $translator);
        self::assertSame('Hi &lt;script&gt;x&lt;/script&gt;!', $output);
    }

    #[Test]
    public function aParamNamedRawIsStillAParam(): void
    {
        // `raw` is a flag only when nothing follows it; `raw=` keeps its
        // placeholder meaning so a catalogue with a `%raw%` entry works.
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('k', ['%raw%' => 'v'], null)
            ->willReturn('<b>out</b>');

        self::assertSame('&lt;b&gt;out&lt;/b&gt;', $this->render('{t:`k` raw=`v`}', $translator));
    }

    #[Test]
    public function returnsKeyAsFallbackWhenNoTranslatorWired(): void
    {
        // Domain unit-test path: Executor constructed without a
        // translator must NOT crash; it returns the key unchanged so
        // the template still renders. Lets DTMPL stay usable in
        // contexts that don't ship Symfony Translation (CLI-only,
        // future portable runtimes).
        $output = $this->render('{t:`hello.world`}', translator: null);
        self::assertSame('hello.world', $output);
    }

    private function render(string $template, ?TranslatorInterface $translator): string
    {
        $lexer = new Lexer();
        $parser = new Parser();
        $ast = $parser->parse($lexer->tokenize($template));

        $executor = new Executor(translator: $translator);

        return $executor->execute($ast);
    }
}
