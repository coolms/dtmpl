<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for comparison operators in `{if:}` and `{unless:}`.
 *
 * Covers `=`, `!=`, `>`, `<`, `>=`, `<=` end-to-end through the
 * public DtmplEngine::render() API.
 */
final class ComparisonOperatorsTest extends TestCase
{
    private DtmplEngine $engine;

    /**
     * `=` against a backtick literal continues to render the then-branch on a match.
     */
    public function testEqRegressionExistingEqualsStillWorks(): void
    {
        $result = $this->engine->render(
            '{if:field.type=`fieldset`}yes{endif}',
            ['field' => ['type' => 'fieldset']],
        );

        self::assertSame('yes', $result);
    }

    /**
     * `!=` renders the then-branch when the values differ.
     */
    public function testNeTruthy(): void
    {
        $result = $this->engine->render(
            '{if:status!=`draft`}publish{endif}',
            ['status' => 'live'],
        );

        self::assertSame('publish', $result);
    }

    /**
     * `!=` skips the then-branch when the values match.
     */
    public function testNeFalsy(): void
    {
        $result = $this->engine->render(
            '{if:status!=`draft`}publish{endif}',
            ['status' => 'draft'],
        );

        self::assertSame('', $result);
    }

    /**
     * `>` with numeric operands renders when LHS is greater.
     */
    public function testGtNumericTruthy(): void
    {
        $result = $this->engine->render(
            '{if:age>18}adult{endif}',
            ['age' => 21],
        );

        self::assertSame('adult', $result);
    }

    /**
     * `>` is strict -- equal values do not render.
     */
    public function testGtNumericFalsyOnEqual(): void
    {
        $result = $this->engine->render(
            '{if:age>18}adult{endif}',
            ['age' => 18],
        );

        self::assertSame('', $result);
    }

    /**
     * `>` does not render when the LHS is less than the RHS.
     */
    public function testGtNumericFalsyOnLess(): void
    {
        $result = $this->engine->render(
            '{if:age>18}adult{endif}',
            ['age' => 12],
        );

        self::assertSame('', $result);
    }

    /**
     * `<` with numeric operands renders when LHS is less.
     */
    public function testLtNumericTruthy(): void
    {
        $result = $this->engine->render(
            '{if:count<5}few{endif}',
            ['count' => 2],
        );

        self::assertSame('few', $result);
    }

    /**
     * `<` is strict -- equal values do not render.
     */
    public function testLtNumericFalsyOnEqual(): void
    {
        $result = $this->engine->render(
            '{if:count<5}few{endif}',
            ['count' => 5],
        );

        self::assertSame('', $result);
    }

    /**
     * `>=` renders when LHS is strictly greater.
     */
    public function testGeNumericTruthyOnGreater(): void
    {
        $result = $this->engine->render(
            '{if:age>=18}adult{endif}',
            ['age' => 30],
        );

        self::assertSame('adult', $result);
    }

    /**
     * `>=` renders when both sides are equal.
     */
    public function testGeNumericTruthyOnEqual(): void
    {
        $result = $this->engine->render(
            '{if:age>=18}adult{endif}',
            ['age' => 18],
        );

        self::assertSame('adult', $result);
    }

    /**
     * `<=` renders when LHS is strictly less.
     */
    public function testLeNumericTruthyOnLess(): void
    {
        $result = $this->engine->render(
            '{if:count<=5}few{endif}',
            ['count' => 2],
        );

        self::assertSame('few', $result);
    }

    /**
     * `<=` renders when both sides are equal.
     */
    public function testLeNumericTruthyOnEqual(): void
    {
        $result = $this->engine->render(
            '{if:count<=5}few{endif}',
            ['count' => 5],
        );

        self::assertSame('few', $result);
    }

    /**
     * A backtick string RHS is coerced to numeric when both sides parse as numbers.
     */
    public function testStringRhsWithNumericCoercion(): void
    {
        $result = $this->engine->render(
            '{if:age>`18`}adult{endif}',
            ['age' => 21],
        );

        self::assertSame('adult', $result);
    }

    /**
     * Non-numeric strings fall back to lexicographic comparison.
     */
    public function testStringCompareFallbackForIsoDates(): void
    {
        $result = $this->engine->render(
            '{if:date>`2026-01-01`}future{endif}',
            ['date' => '2026-02-15'],
        );

        self::assertSame('future', $result);
    }

    /**
     * Whitespace around an operator is allowed inside the tag.
     */
    public function testWhitespaceAroundOperatorTolerated(): void
    {
        $result = $this->engine->render(
            '{if:age >= 18}adult{endif}',
            ['age' => 25],
        );

        self::assertSame('adult', $result);
    }

    /**
     * A bare number RHS (no backticks) parses as a Number token.
     */
    public function testNumberRhsLiteral(): void
    {
        $result = $this->engine->render(
            '{if:count=42}match{endif}',
            ['count' => 42],
        );

        self::assertSame('match', $result);
    }

    /**
     * A bare boolean RHS (no backticks) parses as a Boolean token.
     */
    public function testBooleanRhsLiteral(): void
    {
        $result = $this->engine->render(
            '{if:flag=true}on{endif}',
            ['flag' => true],
        );

        self::assertSame('on', $result);
    }

    /**
     * `{unless:}` negates the comparison verdict.
     */
    public function testUnlessWithComparisonOperator(): void
    {
        $result = $this->engine->render(
            '{unless:age>=18}minor{endunless}',
            ['age' => 15],
        );

        self::assertSame('minor', $result);
    }

    /**
     * A truthy-only condition (no operator) continues to render unchanged.
     */
    public function testTruthyCheckWithoutOperatorStillWorks(): void
    {
        $result = $this->engine->render(
            '{if:flag}on{endif}',
            ['flag' => true],
        );

        self::assertSame('on', $result);
    }

    /**
     * A path-valued RHS resolves against the runtime context.
     */
    public function testVariableRhsPath(): void
    {
        $result = $this->engine->render(
            '{if:user.age>limits.adult}adult{endif}',
            [
                'user' => ['age' => 25],
                'limits' => ['adult' => 18],
            ],
        );

        self::assertSame('adult', $result);
    }

    /**
     * The else-branch renders when the comparison verdict is false.
     */
    public function testElseBranchWithComparison(): void
    {
        $result = $this->engine->render(
            '{if:age>=18}adult{else}minor{endif}',
            ['age' => 15],
        );

        self::assertSame('minor', $result);
    }

    /**
     * A comparison nested inside the then-branch evaluates independently of the outer comparison.
     */
    public function testChainedInThenBranchDoesNotCrossPollute(): void
    {
        $template = '{if:age>=18}{if:age>=65}senior{else}adult{endif}{else}minor{endif}';

        self::assertSame('adult', $this->engine->render($template, ['age' => 30]));
        self::assertSame('senior', $this->engine->render($template, ['age' => 70]));
        self::assertSame('minor', $this->engine->render($template, ['age' => 12]));
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }
}
