<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\SyntaxException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `?` in a filter's argument list marks the argument that receives the piped value, as PHP 8.6's partial function
 * application writes it; with no `?` the value stays the first argument. One `?` per call, `...` is reserved, and
 * outside a tag `?` is ordinary text.
 */
final class PlaceholderTest extends TestCase
{
    private DtmplEngine $engine;

    #[Test]
    public function theValueGoesWhereTheQuestionMarkStandsLast(): void
    {
        // str_replace takes its subject last: the case the placeholder exists for.
        self::assertSame(
            'b-b-c',
            $this->engine->render('{var:text php.str_replace:`a`,`b`,?}', ['text' => 'a-b-c']),
        );
    }

    #[Test]
    public function theValueGoesWhereTheQuestionMarkStandsInTheMiddle(): void
    {
        // str_pad(string, length, pad): `?` first among three is the default; in the middle it is not.
        self::assertSame(
            'xxxxxxxxx7',
            $this->engine->render('{var:n php.str_repeat:`x`,? php.str_pad:10,`7`}', ['n' => 9]),
        );
        self::assertSame('7xx', $this->engine->render('{var:p php.str_pad:`7`,?,`x`}', ['p' => 3]));
    }

    #[Test]
    public function aQuestionMarkFirstIsWhatNoMarkAlreadyMeant(): void
    {
        self::assertSame('ab***', $this->engine->render('{var:s php.str_pad:?,5,`*`}', ['s' => 'ab']));
        self::assertSame('ab***', $this->engine->render('{var:s php.str_pad:5,`*`}', ['s' => 'ab']));
    }

    #[Test]
    public function aSecondQuestionMarkIsRefused(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('one `?` per filter');

        $this->engine->render('{var:s php.str_replace:?,`b`,?}', ['s' => 'a']);
    }

    #[Test]
    public function threeDotsAreReserved(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('`...` is reserved and not supported');

        $this->engine->render('{var:s php.str_replace:`a`,`b`,...}', ['s' => 'a']);
    }

    #[Test]
    public function aQuestionMarkOutsideAFiltersArgumentsIsRefused(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessage('it belongs in a filter\'s argument list');

        // Inside an array literal: a value, not a filter argument.
        $this->engine->render('{var:s php.implode:`-`,[`a`,?]}', ['s' => 'x']);
    }

    #[Test]
    public function aQuestionMarkInTextOrInABacktickStringStaysText(): void
    {
        self::assertSame('Why? Because.', $this->engine->render('Why? Because.', []));
        self::assertSame('a?b', $this->engine->render('{var:s php.str_replace:`-`,`?`,?}', ['s' => 'a-b']));
    }

    #[Test]
    public function anUnderscoreArgumentIsStillTheTextUnderscore(): void
    {
        // `_` was an ordinary identifier before and stays one: no alias in this release.
        self::assertSame('a_b', $this->engine->render('{var:s php.str_replace:`-`,_,?}', ['s' => 'a-b']));
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }
}
