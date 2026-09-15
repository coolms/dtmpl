<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Loader\FilesystemTemplateLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `{css}` / `{js}` -- a partial declares the assets it needs, and the host
 * gathers them from the whole include tree before rendering starts.
 *
 * The properties worth pinning are the ones that make the construct different
 * from writing a `<style>` in the markup: it emits NOTHING where it is
 * written, it survives being reached N times as one copy, and it is collected
 * from templates that are only reachable through a branch this render does not
 * take -- because the head is written before any branch is taken.
 */
final class AssetBlockTest extends TestCase
{
    private string $dir;
    private DtmplEngine $engine;

    #[Test]
    public function anAssetBlockRendersNothingWhereItIsWritten(): void
    {
        self::assertSame('AB', $this->render("A{css}\n.x{color:red}\n{endcss}B"));
    }

    #[Test]
    public function theDeclarationIsGatheredInsteadOfRendered(): void
    {
        $assets = $this->gather("{css}\n.x{color:red}\n{endcss}body");

        self::assertSame(['.x{color:red}'], $assets->css);
        self::assertSame("<style>\n.x{color:red}\n</style>", $assets->styleTag());
    }

    /**
     * The reason the interior is never tokenized: CSS is made of braces, and a
     * media query nests them. Anything less than a verbatim scan turns
     * `{ color` into a tag and a stylesheet into a syntax error.
     */
    #[Test]
    public function bracesAndDtmplLookalikesSurviveInsideTheBody(): void
    {
        $body = '@media (min-width:768px){ .x{color:red} } /* {var:nope} {loop:a:b} */';
        $assets = $this->gather('{css}' . $body . '{endcss}');

        self::assertSame([$body], $assets->css);
    }

    #[Test]
    public function aPartialIncludedManyTimesContributesOneCopy(): void
    {
        $assets = $this->gather('{loop:items:i}{include:`card.dtmpl`}{endloop}');

        self::assertSame(['.card{color:red}'], $assets->css);
        self::assertSame(["document.title='ok'"], $assets->js);
    }

    /**
     * !! The block dispatch this exists for is a chain of `{if:}`s naming
     * fourteen partials, and the head is written before any of them is
     * evaluated. A gather that followed only the taken branch would ship a
     * page whose styles depend on its data -- correct on the page it was
     * tested with, wrong on the next one.
     */
    #[Test]
    public function anUntakenBranchStillContributesItsAssets(): void
    {
        $assets = $this->gather('{if:never}{include:`card.dtmpl`}{endif}');

        self::assertSame(['.card{color:red}'], $assets->css);
    }

    #[Test]
    public function assetsAreGatheredThroughFillsAndNestedIncludes(): void
    {
        // page -> {include:layout}{fill:body}{include:card}  -- the fill body
        // is a sibling list hanging off the IncludeNode, not off the template,
        // so a walk that only looked at children would miss everything a
        // layout-based page ever declares.
        $assets = $this->gather('{include:`layout.dtmpl`}{fill:body}{include:`card.dtmpl`}{endfill}{endinclude}');

        self::assertSame(['.layout{margin:0}', '.card{color:red}'], $assets->css);
    }

    #[Test]
    public function aMissingPartialIsLeftForTheRenderToReport(): void
    {
        // The gather is a side pass; failing here would replace the render's
        // real error -- with its include stack -- with a worse one.
        $assets = $this->gather('{if:never}{include:`gone.dtmpl`}{endif}{css}.x{color:red}{endcss}');

        self::assertSame(['.x{color:red}'], $assets->css);
    }

    #[Test]
    public function nothingDeclaredMeansNoElementAtAll(): void
    {
        $assets = $this->gather('<p>plain</p>');

        self::assertTrue($assets->isEmpty());
        self::assertSame('', $assets->styleTag());
        self::assertSame('', $assets->scriptTag());
    }

    #[Test]
    public function anEmptyBlockContributesNothing(): void
    {
        self::assertTrue($this->gather("{css}\n \n{endcss}")->isEmpty());
    }

    /**
     * The first version of the lexer found `{css}` at position 0 and nowhere
     * else, because a text run swallowed every later marker -- the same defect
     * the verbatim and comment scans each carry a comment about.
     */
    #[Test]
    public function aBlockIsFoundAfterLeadingText(): void
    {
        $assets = $this->gather("<p>text</p>\n{js}\nvar a = 1;\n{endjs}\n<p>more</p>");

        self::assertSame(['var a = 1;'], $assets->js);
    }

    #[Test]
    public function aNestedBlockIsRefusedByName(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/must be at the top level/');

        $this->render('{loop:items:i}{css}.x{color:red}{endcss}{endloop}');
    }

    #[Test]
    public function aBodyCarryingItsOwnClosingTagIsRefused(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('~would close the .<script>. element early~');

        $this->render("{js}var s = '</script>';{endjs}");
    }

    #[Test]
    public function anUnclosedBlockIsAHardError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('~^Unclosed~');

        $this->render('{css}.x{color:red}');
    }

    #[Test]
    public function aNearMissIsOrdinaryText(): void
    {
        // Same exact-form rule as verbatim and comment: only `{css}` opens a
        // block, so prose and code samples are unaffected.
        self::assertSame('{cssoverride} {css x}', $this->render('{cssoverride} {css x}'));
    }

    /**
     * !! The gap this closes is silence. The gather is a static walk of
     * `{include:}` from the page root, so a template reached any other way is
     * invisible to it -- a widget names its partial at render time, and the
     * site menu's `{css}` was therefore never gathered and the menu rendered
     * unstyled with nothing anywhere saying why.
     */
    #[Test]
    public function aDeclarationThatDidNotReachTheHeadIsReportedInDebug(): void
    {
        $source = "{css}\n.widget-only{color:red}\n{endcss}<nav></nav>";

        $out = $this->debugEngine()->render(
            $source,
            ['_assets' => ['keys' => ['css:gathered-from-somewhere-else']]],
            $this->dir . '/widget.dtmpl',
        );

        self::assertStringContainsString('did not reach the document head', $out);
        self::assertStringContainsString('widget.dtmpl', $out);
        self::assertStringContainsString('<nav></nav>', $out, 'The page still renders.');
    }

    /**
     * The positive control, and the one that would catch a check that fires on
     * everything -- which would be worse than the silence, because a warning
     * nobody can act on is one everybody learns to ignore.
     */
    #[Test]
    public function aDeclarationThatDidReachTheHeadIsSilent(): void
    {
        $source = "{css}\n.ordinary{color:red}\n{endcss}<p>x</p>";
        $engine = $this->debugEngine();
        $gathered = $engine->gatherAssets($source, $this->dir . '/page.dtmpl');

        $out = $engine->render($source, ['_assets' => ['keys' => $gathered->keys]], $this->dir . '/page.dtmpl');

        self::assertSame('<p>x</p>', $out);
    }

    /**
     * No `_assets` at all means nobody gathered -- which shipped once, when
     * one of two render paths was given the pass and the other was not, and
     * every doc page came back with empty tokens.
     */
    #[Test]
    public function aRenderThatGatheredNothingAtAllIsCalledOutAsSuch(): void
    {
        $out = $this->debugEngine()->render("{css}\n.x{color:red}\n{endcss}ok", [], $this->dir . '/page.dtmpl');

        self::assertStringContainsString('nothing was gathered for this render at all', $out);
    }

    #[Test]
    public function theNoticeIsDebugOnlyAndNeverReachesAVisitor(): void
    {
        // Same input as the reported case, on the ordinary engine.
        $out = $this->engine->render(
            "{css}\n.widget-only{color:red}\n{endcss}<nav></nav>",
            ['_assets' => ['keys' => ['css:something-else']]],
            $this->dir . '/widget.dtmpl',
        );

        self::assertSame('<nav></nav>', $out);
    }

    /**
     * Filling a slot the partial does not declare is almost always a typo, and
     * it used to cost nothing and say nothing: the body renders, lands in the
     * `__slots` map, and is never read. The page comes back missing whatever
     * the fill was for.
     *
     * It cost a session on this site -- a page filled `head`, the layout
     * declared `styles`, and a stylesheet evaporated three layers from the typo.
     */
    #[Test]
    public function aFillWithNoMatchingSlotIsReportedInDebug(): void
    {
        file_put_contents($this->dir . '/layout_styles.dtmpl', '<main>{slot:styles}</main>');

        $out = $this->debugEngine()->render(
            '{include:`layout_styles.dtmpl`}{fill:head}x{endfill}{endinclude}',
            [],
            $this->dir . '/page.dtmpl',
        );

        self::assertStringContainsString('does not declare as a {slot}', $out);
        self::assertStringContainsString('`head`', $out);
        self::assertStringContainsString('styles', $out, 'it names what IS declared');
        self::assertStringContainsString('<main></main>', $out, 'the page still renders');
    }

    /** The control: a fill that matches must produce no notice at all. */
    #[Test]
    public function aFillThatMatchesItsSlotIsSilent(): void
    {
        file_put_contents($this->dir . '/layout_ok.dtmpl', '<main>{slot:body}</main>');

        $out = $this->debugEngine()->render(
            '{include:`layout_ok.dtmpl`}{fill:body}hello{endfill}{endinclude}',
            [],
            $this->dir . '/page.dtmpl',
        );

        self::assertSame('<main>hello</main>', $out);
    }

    /**
     * A slot inside a branch this render does not take is still DECLARED.
     * Reporting it as missing would make the notice worse than silence, because
     * the reader would go looking for a typo that is not there.
     */
    #[Test]
    public function aSlotNestedInsideAConditionalCountsAsDeclared(): void
    {
        file_put_contents(
            $this->dir . '/layout_nested.dtmpl',
            '{if:never}<main>{slot:body}</main>{endif}',
        );

        $out = $this->debugEngine()->render(
            '{include:`layout_nested.dtmpl`}{fill:body}hi{endfill}{endinclude}',
            [],
            $this->dir . '/page.dtmpl',
        );

        self::assertStringNotContainsString('does not declare', $out);
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dtmpl_assets_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        file_put_contents(
            $this->dir . '/card.dtmpl',
            "{css}\n.card{color:red}\n{endcss}{js}\ndocument.title='ok'\n{endjs}<div class=\"card\"></div>",
        );
        file_put_contents(
            $this->dir . '/layout.dtmpl',
            "{css}\n.layout{margin:0}\n{endcss}<main>{slot:body}</main>",
        );

        $this->engine = new DtmplEngine()->withLoader(new FilesystemTemplateLoader($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function debugEngine(): DtmplEngine
    {
        return new DtmplEngine(debug: true)->withLoader(new FilesystemTemplateLoader($this->dir));
    }

    private function render(string $template): string
    {
        return $this->engine->render($template, ['items' => [1, 2, 3]], $this->dir . '/page.dtmpl');
    }

    private function gather(string $template): \CoolMS\Dtmpl\Runtime\CollectedAssets
    {
        return $this->engine->gatherAssets($template, $this->dir . '/page.dtmpl');
    }
}
