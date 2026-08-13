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
        self::assertSame('Subscription to "calendar.items.foo" denied.', $output);
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
