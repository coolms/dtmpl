<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Parser;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\SyntaxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Mistakes that used to pass silently, or fail while naming the wrong
 * thing.
 *
 * The lexer already had the right instinct -- an unknown tag keyword
 * gets "did you mean `var`?" rather than being swallowed. These are the
 * places that did not follow it, each found by a documentation pass that
 * could not tell from the code what the language actually accepted.
 */
final class SyntaxDiagnosticsTest extends TestCase
{
    private DtmplEngine $engine;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejected(): iterable
    {
        // A second colon silently ENDED the argument list and the rest
        // was skipped, so `truncate:5:`~~`` rendered with the default
        // suffix. Published docs taught exactly this form.
        yield 'colon-separated filter args' => [
            '{var:body truncate:5:`~~`}',
            'commas, not colons',
        ];
        yield 'colon after a comma-separated list' => [
            '{var:n pad:8,`0`:`left`}',
            'commas, not colons',
        ];

        // `==` produced "expected comparison RHS", which names
        // everything except the mistake.
        yield 'double equals' => [
            '{if:a==`b`}y{endif}',
            '`==` is not an operator',
        ];
        yield 'triple equals' => [
            '{if:a===`b`}y{endif}',
            '`==` is not an operator',
        ];
        yield 'not-identical' => [
            '{if:a!==`b`}y{endif}',
            '`!==` is not an operator',
        ];
        yield 'spaced greater-or-equal' => [
            '{if:a> =1}y{endif}',
            'no space between the characters',
        ];

        // An unknown loop modifier was skipped, so the loop ran without
        // it and the author never learned it does not exist.
        yield 'unknown loop modifier' => [
            '{loop:xs reverse}{endloop}',
            'Unknown loop modifier `reverse`',
        ];
        yield 'misspelled loop modifier' => [
            '{loop:xs evne}{endloop}',
            'Did you mean `even`?',
        ];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function negativeNumbers(): iterable
    {
        yield 'define' => ['{def:o=-5}{var:o}', '-5'];
        yield 'define float' => ['{def:o=-5.5}{var:o}', '-5.5'];
        yield 'filter argument' => ['{var:n add:-5}', '5'];
        yield 'comparison rhs' => ['{if:n>-5}y{else}n{endif}', 'y'];
        yield 'array literal' => ['{def:a=[-1,2]}{var:a}', '[-1,2]'];
    }

    #[Test]
    #[DataProvider('rejected')]
    public function itRefusesAndSaysWhy(string $template, string $expectedFragment): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expectedFragment, '/') . '/');

        $this->engine->compile($template);
    }

    #[Test]
    public function multiArgumentFiltersReceiveEveryArgument(): void
    {
        // The gap that let the colon examples survive review: nothing
        // exercised a filter with more than one argument, so dropping
        // the second and third was invisible.
        self::assertSame('abcde~~', $this->engine->render('{var:b truncate:5,`~~`}', ['b' => 'abcdefghij']));
        self::assertSame('00000042', $this->engine->render('{var:n pad:8,`0`,`left`}', ['n' => 42]));
        self::assertSame('420000', $this->engine->render('{var:n pad:6,`0`}', ['n' => 42]));
    }

    #[Test]
    public function aSpelledOutLoopModifierStillWorks(): void
    {
        $data = ['xs' => ['a', 'b', 'c', 'd']];

        self::assertSame('bd', $this->engine->render('{loop:xs odd}{var:item}{endloop}', $data));
        self::assertSame('ac', $this->engine->render('{loop:xs even}{var:item}{endloop}', $data));
        self::assertSame('a, b, c, d', $this->engine->render('{loop:xs split=`, `}{var:item}{endloop}', $data));
    }

    #[Test]
    #[DataProvider('negativeNumbers')]
    public function negativeNumberLiteralsParse(string $template, string $expected): void
    {
        // `-` had no branch in the tag dispatch, so every one of these
        // died on "Unexpected character '-'" and a negative had to
        // arrive as a backtick string or through the context.
        self::assertSame($expected, $this->engine->render($template, ['n' => 10]));
    }

    #[Test]
    public function defaultWithAColonIsTheFilter(): void
    {
        // `default` was claimed by the `default=` special form before
        // the filter loop saw it, so `default:` -- the form the docs
        // teach -- died on "Expected EQUALS, got COLON".
        self::assertSame('none', $this->engine->render('{var:count default:`none`}', ['count' => 0]));
    }

    #[Test]
    public function defaultWithAnEqualsIsStillThePathFallback(): void
    {
        // The two are not synonyms: `=` falls back only when the path is
        // MISSING, so a real zero survives it.
        self::assertSame('0', $this->engine->render('{var:count default=`none`}', ['count' => 0]));
        self::assertSame('none', $this->engine->render('{var:count default=`none`}', []));
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }
}
