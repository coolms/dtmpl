<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\TemplateException;
use CoolMS\Dtmpl\TemplateLoaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the silent-tag whitespace trimmer pass.
 *
 * Cover the algorithm through the public DtmplEngine::render() API so
 * the trimmer + executor integration is exercised end-to-end.
 */
final class WhitespaceTrimmerTest extends TestCase
{
    private DtmplEngine $engine;

    /**
     * A silent tag alone on a line collapses both surrounding newlines.
     */
    public function testSilentAloneOnLineCollapses(): void
    {
        $template = "line1\n{def:x=1}\nline2";

        self::assertSame("line1\nline2", $this->engine->render($template));
    }

    /**
     * A silent tag at the very start of the template strips its trailing newline.
     */
    public function testSilentFirstLineNoLeadingNewline(): void
    {
        $template = "{def:x=1}\ncontent";

        self::assertSame('content', $this->engine->render($template));
    }

    /**
     * A silent tag on the last source line preserves the trailing newline of the previous text.
     */
    public function testSilentLastLinePreservesTrailingNewline(): void
    {
        $template = "content\n{def:x=1}\n";

        self::assertSame("content\n", $this->engine->render($template));
    }

    /**
     * A mid-line silent tag leaves both surrounding spaces intact.
     */
    public function testSilentInlinePreservesBothSides(): void
    {
        $template = 'prefix {def:x=1} suffix';

        self::assertSame('prefix  suffix', $this->engine->render($template));
    }

    /**
     * A silent tag with leading indentation collapses the indent and the trailing newline together.
     */
    public function testSilentWithLeadingIndent(): void
    {
        $template = "line1\n    {def:x=1}\n    line2";

        self::assertSame("line1\n    line2", $this->engine->render($template));
    }

    /**
     * Two consecutive silent-only lines both collapse and leave a single seam.
     */
    public function testSilentConsecutiveLinesAllCollapse(): void
    {
        $template = "prefix\n{def:x=1}\n{def:y=2}\ncontent";

        self::assertSame("prefix\ncontent", $this->engine->render($template));
    }

    /**
     * A silent tag inside the then-branch of an `{if:}` collapses inside that branch only.
     */
    public function testSilentInsideThenBranch(): void
    {
        $template = "before\n{if:c}\nA\n{def:x=1}\nB\n{endif}\nafter";

        self::assertSame(
            "before\nA\nB\nafter",
            $this->engine->render($template, ['c' => true]),
        );
    }

    /**
     * A silent tag inside the else-branch of an `{if:}` collapses inside that branch only.
     */
    public function testSilentInsideElseBranch(): void
    {
        $template = "{if:c}then{else}\nA\n{def:x=1}\nB\n{endif}";

        self::assertSame(
            "\nA\nB\n",
            $this->engine->render($template, ['c' => false]),
        );
    }

    /**
     * A silent tag inside a loop body collapses on each iteration.
     */
    public function testSilentInsideLoopBody(): void
    {
        $template = "{loop:items}A\n{def:x=1}\nB\n{endloop}";

        self::assertSame(
            "A\nB\nA\nB\n",
            $this->engine->render($template, ['items' => [1, 2]]),
        );
    }

    /**
     * A silent tag inside a slot's default body collapses when the default body renders.
     */
    public function testSilentInsideSlotDefaultBody(): void
    {
        $template = "{slot:s}A\n{def:x=1}\nB{endslot}";

        self::assertSame("A\nB", $this->engine->render($template));
    }

    /**
     * A silent tag immediately before a block node collapses cleanly.
     */
    public function testSilentBeforeBlockNode(): void
    {
        $template = "{def:x=1}\n{if:c}body{endif}";

        self::assertSame('body', $this->engine->render($template, ['c' => true]));
    }

    /**
     * A silent tag immediately after a block node collapses cleanly.
     */
    public function testSilentAfterBlockNode(): void
    {
        $template = "{if:c}body{endif}\n{def:x=1}\ntail";

        self::assertSame("body\ntail", $this->engine->render($template, ['c' => true]));
    }

    /**
     * Templates without silent tags render unchanged, including blank lines.
     */
    public function testNoSilentNoChangeToText(): void
    {
        $template = "line1\nline2\n\nline4";

        self::assertSame("line1\nline2\n\nline4", $this->engine->render($template));
    }

    /**
     * Silent-tag trimming recurses through nested block structures.
     */
    public function testSilentInNestedBlocksRecurses(): void
    {
        $template = "{if:outer}\nA\n{loop:items}\nB\n{def:x=1}\nC\n{endloop}\nD\n{endif}";

        self::assertSame(
            "A\nB\nC\nB\nC\nD\n",
            $this->engine->render($template, [
                'outer' => true,
                'items' => [1, 2],
            ]),
        );
    }

    /**
     * Repeated rendering of the same template yields identical, stable output.
     */
    public function testTrimmerIsIdempotent(): void
    {
        $template = "line1\n{def:x=1}\nline2";

        $first = $this->engine->render($template);
        $second = $this->engine->render($template);

        self::assertSame($first, $second);
        self::assertSame("line1\nline2", $first);
    }

    /**
     * A silent tag with leading text ending in spaces (no preceding newline) keeps the prefix spaces intact.
     */
    public function testSilentInlinePrefixPreserved(): void
    {
        $template = "prefix {def:x=1}\nmore";

        self::assertSame('prefix more', $this->engine->render($template));
    }

    /**
     * An `{if:}` block on its own line collapses both surrounding lines, preserving body indentation.
     */
    public function testIfBlockBordersOnOwnLines(): void
    {
        $template = "text1\n  {if:c}\n      body\n    {endif}\ntext2";

        self::assertSame(
            "text1\n      body\ntext2",
            $this->engine->render($template, ['c' => true]),
        );
    }

    /**
     * An `{unless:}` block on its own line collapses both surrounding lines.
     */
    public function testUnlessBlockBorders(): void
    {
        $template = "text1\n{unless:c}\n  body\n{endunless}\ntext2";

        self::assertSame(
            "text1\n  body\ntext2",
            $this->engine->render($template, ['c' => false]),
        );
    }

    /**
     * The `{else}` separator on its own line collapses the line break between then- and else-bodies.
     */
    public function testIfElseElseBorderCollapses(): void
    {
        $template = "{if:c}\n  yes\n  {else}\n  no\n  {endif}";

        self::assertSame("  yes\n", $this->engine->render($template, ['c' => true]));
        self::assertSame("  no\n", $this->engine->render($template, ['c' => false]));
    }

    /**
     * A `{loop:}` block on its own line collapses the surrounding lines around its body.
     */
    public function testLoopBlockBorders(): void
    {
        $template = "text1\n{loop:items}\n  body\n{endloop}\ntext2";

        self::assertSame(
            "text1\n  body\n  body\ntext2",
            $this->engine->render($template, ['items' => [1, 2]]),
        );
    }

    /**
     * Two consecutive inline blocks separated by a newline keep the newline -- both close-border gates fail on alphabetic ends.
     */
    public function testConsecutiveBlocksKeepSeparator(): void
    {
        $template = "{if:a}A{endif}\n{if:b}B{endif}";

        self::assertSame(
            "A\nB",
            $this->engine->render($template, ['a' => true, 'b' => true]),
        );
    }

    /**
     * Block-border cleanup recurses through a loop nested inside a conditional.
     */
    public function testNestedLoopInsideConditional(): void
    {
        $template = "{if:show}\n  {loop:items:item}\n    {var:item.name}\n  {endloop}\n{endif}";

        self::assertSame(
            "    A\n    B\n",
            $this->engine->render($template, [
                'show' => true,
                'items' => [['name' => 'A'], ['name' => 'B']],
            ]),
        );
    }

    /**
     * A `{slot:}` with a non-empty default body on its own line collapses the surrounding lines.
     */
    public function testSlotWithBlockDefaultBody(): void
    {
        $template = "before\n{slot:s}\n  default\n{endslot}\nafter";

        self::assertSame(
            "before\n  default\nafter",
            $this->engine->render($template),
        );
    }

    /**
     * A self-closing `{slot:}{endslot}` on its own line with no fill content and no default body collapses the surrounding line via the DTMPL-8 composition cleanup.
     */
    public function testSlotSelfClosingUnchanged(): void
    {
        $template = "before\n{slot:s}{endslot}\nafter";

        self::assertSame(
            "before\nafter",
            $this->engine->render($template),
        );
    }

    /**
     * A self-closing `{slot:}` with an inline default attribute renders the default as its single-line value.
     */
    public function testSlotInlineDefaultUnchanged(): void
    {
        $template = "before\n{slot:s default=`hello`}\nafter";

        self::assertSame(
            "before\nhello\nafter",
            $this->engine->render($template),
        );
    }

    /**
     * A `{fill:}` body inside `{include:}{endinclude}` has its leading newline and trailing indent trimmed before the include resolves.
     */
    public function testFillInsideIncludeBodyTrimmed(): void
    {
        $loader = new class implements TemplateLoaderInterface {
            public function load(string $path, string $basePath = ''): string
            {
                if ('layout' === $path) {
                    return '{slot:content}{endslot}';
                }

                throw new TemplateException("Not found: $path");
            }

            public function resolve(string $path, string $basePath = ''): string
            {
                return $path;
            }
        };
        $engine = new DtmplEngine(loader: $loader);

        $template = "{include:`layout`}\n  {fill:content}\n    body\n  {endfill}\n{endinclude}";

        self::assertSame("    body\n", $engine->render($template));
    }

    /**
     * A block at the end of the template trims its body's trailing indent via the virtual-EOF rule, preserving the final newline.
     */
    public function testVirtualEofBlockAtEndOfTemplate(): void
    {
        $template = "{if:c}\n  body\n  {endif}";

        self::assertSame("  body\n", $this->engine->render($template, ['c' => true]));
    }

    /**
     * A wholly-inline `{if:}` block (no newlines around the body) renders unchanged.
     */
    public function testBlockInlineFormUnchanged(): void
    {
        $template = '{if:c}body{endif}';

        self::assertSame('body', $this->engine->render($template, ['c' => true]));
    }

    /**
     * The close border trims a trailing-indent line before `{endif}` when the conditional has only a then-branch.
     */
    public function testThenBranchOnlyCloseBorder(): void
    {
        $template = "{if:c}body\n  {endif}\ntail";

        self::assertSame(
            "body\ntail",
            $this->engine->render($template, ['c' => true]),
        );
    }

    /**
     * The else-border trims the indent before `{else}` and the leading newline of the else-body when the `{else}` sits on its own line.
     */
    public function testElseBranchOnlyOpenBorder(): void
    {
        $template = "{if:c}then\n  {else}\n  else-text{endif}";

        self::assertSame("then\n", $this->engine->render($template, ['c' => true]));
        self::assertSame('  else-text', $this->engine->render($template, ['c' => false]));
    }

    /**
     * Cleanup composes through nested blocks, preserving body indentation while collapsing the tag-line whitespace.
     */
    public function testDeeplyNestedBlocksAllCollapse(): void
    {
        $template = "{if:a}\n  {if:b}\n    body\n  {endif}\n{endif}";

        self::assertSame(
            "    body\n",
            $this->engine->render($template, [
                'a' => true,
                'b' => true,
            ]),
        );
    }

    /**
     * A chain of silent tags followed by a block on its own line collapses cleanly through both passes.
     */
    public function testBlockAfterSilentTagChain(): void
    {
        $template = "{def:a=1}\n{def:b=2}\n{if:c}\nbody\n{endif}\ntail";

        self::assertSame(
            "body\ntail",
            $this->engine->render($template, ['c' => true]),
        );
    }

    /**
     * Two sequential `{if:}/{endif}` blocks on indented lines around a silent tag collapse the orphan-indent fragments left by prior trim passes.
     */
    public function testDtmpl5SequentialBlocksWithOrphanIndentCleans(): void
    {
        $template = "{loop:items:item}\n"
            . "    {def:flag=item.flag}\n"
            . "    {if:flag}\n"
            . "      <li>yes</li>\n"
            . "    {endif}\n"
            . "    {unless:flag}\n"
            . "      <li>no</li>\n"
            . "    {endunless}\n"
            . '  {endloop}';

        self::assertSame(
            "      <li>no</li>\n  ",
            $this->engine->render($template, ['items' => [['flag' => false]]]),
        );
    }

    /**
     * Two adjacent multi-line block bodies on indented lines render with body indent preserved and the inter-block orphan indent removed.
     */
    public function testDtmpl5TwoBlocksBackToBackClean(): void
    {
        $inline = "start\n  {if:a}A{endif}\n  {if:b}B{endif}\nend";
        self::assertSame(
            "start\n  A\n  B\nend",
            $this->engine->render($inline, ['a' => true, 'b' => true]),
        );

        $multiLine = "start\n  {if:a}\n    A\n  {endif}\n  {if:b}\n    B\n  {endif}\nend";
        self::assertSame(
            "start\n    A\n    B\nend",
            $this->engine->render($multiLine, ['a' => true, 'b' => true]),
        );
    }

    /**
     * User content followed by trailing whitespace before a silent tag is preserved -- the new gate's whitespace-only branch requires the entire prev to be whitespace.
     */
    public function testDtmpl5InlinePrefixWhitespacePreserved(): void
    {
        $template = "prefix {def:x=1}\nmore";

        self::assertSame('prefix more', $this->engine->render($template));
    }

    /**
     * Two complementary conditionals around a single render-empty branch leave no blank line between the wrappers and the active branch.
     */
    public function testDtmpl5EmptyConditionalRegionNoBlankLine(): void
    {
        $template = "<div>\n"
            . "  {if:auth}\n"
            . "    authed\n"
            . "  {endif}\n"
            . "  {unless:auth}\n"
            . "    guest\n"
            . "  {endunless}\n"
            . '</div>';

        self::assertSame(
            "<div>\n    guest\n</div>",
            $this->engine->render($template, ['auth' => false]),
        );
    }

    /**
     * A silent tag at the start of a parent body leaves a whitespace-only sibling that the next block's open border can still trim cleanly.
     */
    public function testDtmpl5WhitespaceOnlyPrevAtBodyStart(): void
    {
        $inline = '{def:x=1}    {if:y}Y{endif}';
        self::assertSame('    Y', $this->engine->render($inline, ['y' => true]));

        $blockForm = "{def:x=1}\n    {if:y}\n      Y\n    {endif}";
        self::assertSame("      Y\n", $this->engine->render($blockForm, ['y' => true]));
    }

    /**
     * After DTMPL-5 internal cleanup, a multi-line block body ends in
     * a whitespace-only TextNode (orphan from the inner close-border
     * having consumed the leading newline). The outer close-border
     * must also trim this orphan because the body is multi-line.
     */
    public function testDtmpl6MultiLineBlockWithOrphanBodyLast(): void
    {
        $template = "<div>\n"
                  . "  {if:c}\n"
                  . "    {if:inner}\n"
                  . "      content\n"
                  . "    {endif}\n"
                  . "  {endif}\n"
                  . '</div>';
        self::assertSame(
            "<div>\n      content\n</div>",
            $this->engine->render($template, ['c' => true, 'inner' => true]),
        );
        self::assertSame(
            "<div>\n</div>",
            $this->engine->render($template, ['c' => false, 'inner' => false]),
        );
    }

    /**
     * Real-world auth / unauth navbar pattern: two sequential
     * conditionals on outer state, each with multi-line bodies
     * containing nested conditionals. The unauthenticated render must
     * have no blank line after <div> and no orphan whitespace line
     * before </div>.
     */
    public function testDtmpl6AuthUnauthPatternNoBlankLine(): void
    {
        $template = "<div>\n"
                  . "  {if:auth}\n"
                  . "    {if:dashboard}<a>Dashboard</a>{endif}\n"
                  . "    {if:logout}<a>Sign out</a>{endif}\n"
                  . "  {endif}\n"
                  . "  {unless:auth}\n"
                  . "    {if:login}<a>Sign in</a>{endif}\n"
                  . "    {if:register}<a>Register</a>{endif}\n"
                  . "  {endunless}\n"
                  . '</div>';
        self::assertSame(
            "<div>\n    <a>Sign in</a>\n    <a>Register</a>\n</div>",
            $this->engine->render($template, [
                'auth' => false,
                'login' => true,
                'register' => true,
            ]),
        );
    }

    /**
     * Single-line loop with user-content separator (body = [Variable,
     * Text(" ")]). Body has no newline anywhere, so it is NOT
     * multi-line and the strict gate preserves the user's separator.
     */
    public function testDtmpl6SingleLineLoopSeparatorPreserved(): void
    {
        $template = '{loop:items:item}{var:item} {endloop}rest';
        self::assertSame(
            'a b c rest',
            $this->engine->render($template, ['items' => ['a', 'b', 'c']]),
        );
    }

    /**
     * Multi-line body whose trailing whitespace is inline content, not
     * a whitespace-only TextNode. The gate alternation requires the
     * trailing whitespace at end-of-string to be preceded by start or
     * by a newline, so the user content stays intact.
     */
    public function testDtmpl6MultiLineBodyWithInlineTrailingWsPreserved(): void
    {
        $template = "{if:c}\n  hello  {endif}";
        self::assertSame(
            '  hello  ',
            $this->engine->render($template, ['c' => true]),
        );
    }

    /**
     * Loop at end of template uses the virtual-EOF path. Virtual-EOF
     * is NOT generalized in DTMPL-6, so the trailing space inside the
     * loop body remains preserved as user content.
     */
    public function testDtmpl6VirtualEofStaysStrictUserContent(): void
    {
        $template = '{loop:items:item}{var:item} {endloop}';
        self::assertSame(
            'a b c ',
            $this->engine->render($template, ['items' => ['a', 'b', 'c']]),
        );
    }

    /**
     * Conditional with multi-line then-body and single-line else-body.
     * The else-border between thenBody.last and elseBody.first uses
     * the multi-line check on the then-body.
     */
    public function testDtmpl6ElseBorderMultiLineThenBody(): void
    {
        $template = "{if:c}\n  yes\n  {else}no{endif}";
        self::assertSame("  yes\n", $this->engine->render($template, ['c' => true]));
        self::assertSame('no', $this->engine->render($template, ['c' => false]));
    }

    /**
     * Real-world navbar attribute pattern: three inline conditionals,
     * each on its own line between newline-bearing text. First two
     * render empty and their lines collapse cleanly; the third
     * renders and its line stays.
     */
    public function testDtmpl7InlineEmptyConditionalAttrsCollapse(): void
    {
        $template = "<a href=\"/\"\n"
                  . "   {if:target}target=\"_blank\"{endif}\n"
                  . "   {if:rel}rel=\"noopener\"{endif}\n"
                  . '   {if:current}aria-current="page"{endif}>';
        self::assertSame(
            "<a href=\"/\"\n   aria-current=\"page\">",
            $this->engine->render($template, [
                'target' => false,
                'rel' => false,
                'current' => true,
            ]),
        );
    }

    /**
     * All inline conditionals empty: every empty line collapses, no
     * trailing blank lines remain before the closing chunk.
     */
    public function testDtmpl7AllEmptyConditionalsCollapseToNoLines(): void
    {
        $template = "<a href=\"/\"\n"
                  . "   {if:target}target=\"_blank\"{endif}\n"
                  . '   {if:rel}rel="noopener"{endif}>';
        self::assertSame(
            '<a href="/">',
            $this->engine->render($template, [
                'target' => false,
                'rel' => false,
            ]),
        );
    }

    /**
     * A non-empty inline conditional on its own line keeps its
     * surrounding newlines intact: cleanup only fires when the
     * rendered result is empty.
     */
    public function testDtmpl7NonEmptyInlinePreservesNewlines(): void
    {
        $template = "before\n  {if:c}body{endif}\nafter";
        self::assertSame(
            "before\n  body\nafter",
            $this->engine->render($template, ['c' => true]),
        );
    }

    /**
     * A conditional NOT on its own line: prev does not end with a
     * newline. Empty render leaves the surrounding spaces unchanged
     * because the structural signal never fired.
     */
    public function testDtmpl7InlineEmptyMidLineUnchanged(): void
    {
        $template = 'prefix {if:c}body{endif} suffix';
        self::assertSame(
            'prefix  suffix',
            $this->engine->render($template, ['c' => false]),
        );
    }

    /**
     * A zero-iteration loop on its own line collapses the line; a
     * non-empty render emits content normally.
     */
    public function testDtmpl7EmptyLoopOnOwnLineCollapses(): void
    {
        $template = "before\n  {loop:items}<li>{var:item}</li>{endloop}\nafter";
        self::assertSame(
            "before\nafter",
            $this->engine->render($template, ['items' => []]),
        );
        self::assertSame(
            "before\n  <li>a</li><li>b</li>\nafter",
            $this->engine->render($template, ['items' => ['a', 'b']]),
        );
    }

    /**
     * A loop with user-content separator: non-empty render emits the
     * intended content. Empty render in a single-line surrounding
     * context never marks onOwnLine, so the surrounding spaces stay.
     */
    public function testDtmpl7LoopWithSeparatorPreservesUserContent(): void
    {
        $template = 'prefix {loop:items:item}{var:item} {endloop}suffix';
        self::assertSame(
            'prefix a b c suffix',
            $this->engine->render($template, ['items' => ['a', 'b', 'c']]),
        );
        self::assertSame(
            'prefix suffix',
            $this->engine->render($template, ['items' => []]),
        );
    }

    /**
     * Three sequential inline conditionals on their own lines: when
     * all empty, the whole region collapses; mixed empty / non-empty
     * collapses only the empty lines.
     */
    public function testDtmpl7SequentialInlineEmptiesCollapse(): void
    {
        $template = "a\n  {if:x}X{endif}\n  {if:y}Y{endif}\n  {if:z}Z{endif}\nb";
        self::assertSame(
            "a\nb",
            $this->engine->render($template, ['x' => false, 'y' => false, 'z' => false]),
        );
        self::assertSame(
            "a\n  X\n  Z\nb",
            $this->engine->render($template, ['x' => true, 'y' => false, 'z' => true]),
        );
    }

    /**
     * A conditional flanked by non-newline TextNodes: the structural
     * signal fails, onOwnLine stays false, and an empty render
     * leaves the join intact.
     */
    public function testDtmpl7InlineEmptyNoNeighbourNewlinesNotMarked(): void
    {
        $template = 'abc{if:c}body{endif}def';
        self::assertSame(
            'abcdef',
            $this->engine->render($template, ['c' => false]),
        );
    }

    /**
     * An include that renders entirely empty (its partial is wrapped
     * in a never-true conditional) on its own line collapses the
     * surrounding line at runtime.
     */
    public function testDtmpl8EmptyIncludeOnOwnLineCollapses(): void
    {
        $engine = $this->makeEngineWithLoader([
            'empty-partial' => '{if:never}content{endif}',
        ]);
        $template = "before\n  {include:`empty-partial`}\nafter";
        self::assertSame(
            "before\nafter",
            $engine->render($template),
        );
    }

    /**
     * A non-empty include on its own line keeps its surrounding
     * newlines intact: empty-render cleanup does not fire. DTMPL-9
     * case 4 also consumes the layout's placeholder indent so the
     * partial's content renders at its own authored column.
     */
    public function testDtmpl8NonEmptyIncludePreservesNewlines(): void
    {
        $engine = $this->makeEngineWithLoader([
            'partial' => 'content',
        ]);
        $template = "before\n  {include:`partial`}\nafter";
        self::assertSame(
            "before\ncontent\nafter",
            $engine->render($template),
        );
    }

    /**
     * An empty slot on its own line with no fill content and no
     * default body collapses the surrounding line.
     */
    public function testDtmpl8EmptySlotOnOwnLineCollapses(): void
    {
        $template = "before\n  {slot:s}{endslot}\nafter";
        self::assertSame(
            "before\nafter",
            $this->engine->render($template),
        );
    }

    /**
     * A slot filled by caller-provided fill content preserves the
     * layout's surrounding newlines. DTMPL-9 case 4 consumes the
     * layout's placeholder indent so the fill content renders at
     * its own authored column (here the fill is plain `HELLO`, so
     * it renders at column 0).
     */
    public function testDtmpl8SlotWithFillContentPreserves(): void
    {
        $engine = $this->makeEngineWithLoader([
            'layout' => "wrap\n  {slot:content}{endslot}\n/wrap",
        ]);
        $template = '{include:`layout`}{fill:content}HELLO{endfill}{endinclude}';
        self::assertSame(
            "wrap\nHELLO\n/wrap",
            $engine->render($template),
        );
    }

    /**
     * A slot whose rendered fill content ends with newline + indent
     * has the trailing indent (and the newline) consumed against a
     * newline-leading next sibling, so the parent's next line break
     * provides a single separation rather than a blank line.
     * DTMPL-9 case 4 also consumes the layout placeholder indent on
     * the leading side, so `<section/>` renders at its own authored
     * 4-space column rather than at the stacked 6-space column.
     */
    public function testDtmpl8SlotTrailingWhitespaceNoBlankLine(): void
    {
        $engine = $this->makeEngineWithLoader([
            'layout' => "<main>\n  {slot:content}{endslot}\n</main>",
        ]);
        $template = "{include:`layout`}{fill:content}\n    <section/>\n  {endfill}{endinclude}";
        self::assertSame(
            "<main>\n    <section/>\n</main>",
            $engine->render($template),
        );
    }

    /**
     * An include whose partial body ends with newline + indent has
     * the trailing newline + indent consumed against a newline-
     * leading next sibling. DTMPL-9 case 4 consumes the parent
     * placeholder indent on the leading side as well.
     */
    public function testDtmpl8IncludeTrailingWhitespaceNoBlankLine(): void
    {
        $engine = $this->makeEngineWithLoader([
            'partial' => "line\n  ",
        ]);
        $template = "before\n  {include:`partial`}\nafter";
        self::assertSame(
            "before\nline\nafter",
            $engine->render($template),
        );
    }

    /**
     * A slot inside a layout whose call site is mid-line (no
     * flanking newlines) is not marked on-own-line and renders
     * without any composition cleanup.
     */
    public function testDtmpl8SlotMidLineUnchanged(): void
    {
        $engine = $this->makeEngineWithLoader([
            'layout' => 'prefix {slot:s}{endslot} suffix',
        ]);
        $template = '{include:`layout`}{fill:s}MID{endfill}{endinclude}';
        self::assertSame(
            'prefix MID suffix',
            $engine->render($template),
        );
    }

    /**
     * Nested layout / fill / slot composition mirroring the real
     * home-page failure: the outer layout's slot trailing whitespace
     * is consumed so the closing `</html>` sits on its own line.
     * DTMPL-9 case 4 also consumes the placeholder indent at each
     * level, so `<main>` renders at its authored 4-space column and
     * `HELLO` (with no own indent) renders at column 0.
     */
    public function testDtmpl8NestedSlotFillTrailingWhitespace(): void
    {
        $engine = $this->makeEngineWithLoader([
            'base' => "<html>\n  {slot:body}{endslot}\n</html>",
            'middle' => "{include:`base`}{fill:body}\n    <main>\n      {slot:content}{endslot}\n    </main>\n  {endfill}{endinclude}",
        ]);
        $template = '{include:`middle`}{fill:content}HELLO{endfill}{endinclude}';
        self::assertSame(
            "<html>\n    <main>\nHELLO\n    </main>\n</html>",
            $engine->render($template),
        );
    }

    /**
     * Layout's `      {slot:content}` placeholder indent is consumed
     * so the fill body's authored 4-space indent positions
     * `<section/>` at its own column rather than stacked with the
     * 6-space placeholder.
     */
    public function testDtmpl9SlotIndentDoesNotStack(): void
    {
        $engine = $this->makeEngineWithLoader([
            'layout' => "<main>\n      {slot:content}{endslot}\n</main>",
        ]);
        $template = "{include:`layout`}{fill:content}\n    <section/>\n  {endfill}{endinclude}";
        self::assertSame(
            "<main>\n    <section/>\n</main>",
            $engine->render($template),
        );
    }

    /**
     * An include placed on an indented placeholder line in the
     * parent has its placeholder indent consumed; the partial's
     * authored first-line indent positions the rendered content.
     */
    public function testDtmpl9IncludeIndentDoesNotStack(): void
    {
        $engine = $this->makeEngineWithLoader([
            'partial' => "\n    <p>content</p>\n  ",
        ]);
        $template = "<main>\n      {include:`partial`}\n</main>";
        self::assertSame(
            "<main>\n    <p>content</p>\n</main>",
            $engine->render($template),
        );
    }

    /**
     * A fill body's first-content indent is preserved across nested
     * composition: case 4 only fires on output buffer text preceded
     * by a newline (the lookbehind gate), so a fill body's leading
     * 4-space indent at output buffer start is not stripped.
     */
    public function testDtmpl9FillBodyFirstIndentPreserved(): void
    {
        $engine = $this->makeEngineWithLoader([
            'base' => "<html>\n  {slot:body}{endslot}\n</html>",
            'middle' => "{include:`base`}{fill:body}\n    {include:`leaf`}\n  {endfill}{endinclude}",
            'leaf' => 'LEAF',
        ]);
        $template = '{include:`middle`}{endinclude}';
        self::assertSame(
            "<html>\n    LEAF\n</html>",
            $engine->render($template),
        );
    }

    /**
     * A slot inline in mid-line context is not onOwnLine so case 4
     * does not fire; the inline render concatenates verbatim.
     */
    public function testDtmpl9InlineCompositionNoIndentConsume(): void
    {
        $engine = $this->makeEngineWithLoader([
            'layout' => 'prefix {slot:s}{endslot} suffix',
        ]);
        $template = '{include:`layout`}{fill:s}MID{endfill}{endinclude}';
        self::assertSame(
            'prefix MID suffix',
            $engine->render($template),
        );
    }

    /**
     * Case 1 / 2 still applies for empty composition on its own
     * line: the full `\n[ \t]*$` is stripped from the output buffer.
     * Case 4 only fires for non-empty renders.
     */
    public function testDtmpl9EmptyCompositionStillCollapses(): void
    {
        $engine = $this->makeEngineWithLoader([
            'partial' => '{if:never}body{endif}',
        ]);
        $template = "before\n      {include:`partial`}\nafter";
        self::assertSame(
            "before\nafter",
            $engine->render($template),
        );
    }

    /**
     * Combined cases 1, 3, and 4: an indented empty include
     * collapses its line cleanly while a non-empty slot just below
     * has both its placeholder indent and trailing fill whitespace
     * consumed.
     */
    public function testDtmpl9CombinedCleanupAllCases(): void
    {
        $engine = $this->makeEngineWithLoader([
            'empty-partial' => '{if:never}x{endif}',
            'layout' => "<wrap>\n  {include:`empty-partial`}\n  {slot:c}{endslot}\n</wrap>",
        ]);
        $template = "{include:`layout`}{fill:c}\n    <inner/>\n  {endfill}{endinclude}";
        self::assertSame(
            "<wrap>\n    <inner/>\n</wrap>",
            $engine->render($template),
        );
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }

    /**
     * Build a DtmplEngine wired to an in-memory loader keyed by
     * template path. Returns Not-Found for unknown paths.
     *
     * @param array<string, string> $files
     */
    private function makeEngineWithLoader(array $files): DtmplEngine
    {
        $loader = new class($files) implements TemplateLoaderInterface {
            /**
             * @param array<string, string> $files
             */
            public function __construct(private array $files)
            {
            }

            public function load(string $path, string $basePath = ''): string
            {
                if (array_key_exists($path, $this->files)) {
                    return $this->files[$path];
                }

                throw new TemplateException("Not found: $path");
            }

            public function resolve(string $path, string $basePath = ''): string
            {
                return $path;
            }
        };

        return new DtmplEngine(loader: $loader);
    }
}
