<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\SyntaxException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the `{else}` block inside `{if:}` and `{unless:}`.
 */
final class IfElseTest extends TestCase
{
    private DtmplEngine $engine;

    /**
     * `{if:cond}then{else}else{endif}` renders the then-branch when the condition is truthy.
     */
    public function testIfWithElseRendersThenWhenTruthy(): void
    {
        $result = $this->engine->render('{if:flag}then{else}else{endif}', ['flag' => true]);

        $this->assertSame('then', $result);
    }

    /**
     * `{if:cond}then{else}else{endif}` renders the else-branch when the condition is falsy.
     */
    public function testIfWithElseRendersElseWhenFalsy(): void
    {
        $result = $this->engine->render('{if:flag}then{else}else{endif}', ['flag' => false]);

        $this->assertSame('else', $result);
    }

    /**
     * `{unless:cond}then{else}else{endunless}` renders the then-branch when the condition is falsy.
     */
    public function testUnlessWithElseRendersThenWhenFalsy(): void
    {
        $result = $this->engine->render('{unless:flag}then{else}else{endunless}', ['flag' => false]);

        $this->assertSame('then', $result);
    }

    /**
     * `{unless:cond}then{else}else{endunless}` renders the else-branch when the condition is truthy.
     */
    public function testUnlessWithElseRendersElseWhenTruthy(): void
    {
        $result = $this->engine->render('{unless:flag}then{else}else{endunless}', ['flag' => true]);

        $this->assertSame('else', $result);
    }

    /**
     * `{if:cond}body{endif}` without `{else}` continues to render unchanged.
     */
    public function testIfWithoutElseStillWorks(): void
    {
        $result = $this->engine->render('{if:flag}body{endif}', ['flag' => true]);

        $this->assertSame('body', $result);
    }

    /**
     * Equality comparison against a backtick literal selects the appropriate branch.
     */
    public function testIfWithEqualityComparisonAndElse(): void
    {
        $result = $this->engine->render(
            '{if:a=`x`}eq{else}neq{endif}',
            ['a' => 'y'],
        );

        $this->assertSame('neq', $result);
    }

    /**
     * Equality comparison between two context paths selects the appropriate branch.
     */
    public function testIfWithPathComparisonAndElse(): void
    {
        $result = $this->engine->render(
            '{if:a=b}eq{else}neq{endif}',
            ['a' => 'hello', 'b' => 'hello'],
        );

        $this->assertSame('eq', $result);
    }

    /**
     * A nested `{if}` inside the then-branch resolves with the outer condition truthy.
     */
    public function testNestedIfInsideThenBranchWithOuterElse(): void
    {
        $template = '{if:outer}OUT[{if:inner}I{else}NI{endif}]{else}ELSE{endif}';
        $result = $this->engine->render($template, ['outer' => true, 'inner' => false]);

        $this->assertSame('OUT[NI]', $result);
    }

    /**
     * A nested `{if}` inside the else-branch resolves with the outer condition falsy.
     */
    public function testNestedIfInsideElseBranch(): void
    {
        $template = '{if:outer}OUT{else}ELSE[{if:inner}I{else}NI{endif}]{endif}';
        $result = $this->engine->render($template, ['outer' => false, 'inner' => true]);

        $this->assertSame('ELSE[I]', $result);
    }

    /**
     * An empty then-branch with a falsy condition renders only the else-branch content.
     */
    public function testEmptyThenBranch(): void
    {
        $result = $this->engine->render('{if:c}{else}only-else{endif}', ['c' => false]);

        $this->assertSame('only-else', $result);
    }

    /**
     * An empty else-branch with a truthy condition renders only the then-branch content.
     */
    public function testEmptyElseBranch(): void
    {
        $result = $this->engine->render('{if:c}only-then{else}{endif}', ['c' => true]);

        $this->assertSame('only-then', $result);
    }

    /**
     * A stray `{else}` outside any `{if:}` or `{unless:}` block raises a SyntaxException.
     */
    public function testStrayElseOutsideConditionalRaisesSyntaxError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/`\{else\}` is only valid inside/');

        $this->engine->render('before {else} after');
    }

    /**
     * `{else:foo}` with arguments raises a SyntaxException -- the closing brace is expected immediately after `else`.
     */
    public function testElseWithColonRaisesSyntaxError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/CLOSE_BRACE/');

        $this->engine->render('{if:flag}then{else:foo}else{endif}', ['flag' => true]);
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }
}
