<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A comment and a `{css}` / `{js}` asset block render nothing where they are written, so a line holding nothing but
 * one collapses as a `{def:}` line does: its indentation and its newline go with it. A comment written mid-line
 * leaves the line as authored.
 */
final class SilentLinesCollapseTest extends TestCase
{
    private DtmplEngine $engine;

    #[Test]
    public function aCommentAloneOnItsLineTakesTheLine(): void
    {
        self::assertSame("<p>a</p>\n<p>b</p>", $this->engine->render("<p>a</p>\n  {comment}note{endcomment}\n<p>b</p>"));
        self::assertSame("<p>a</p>\n<p>b</p>", $this->engine->render("<p>a</p>\n  {comment:note}\n<p>b</p>"));
    }

    #[Test]
    public function consecutiveCommentLinesAllCollapse(): void
    {
        $template = "<p>a</p>\n{comment:one}\n  {comment:two}\n{comment}three{endcomment}\n<p>b</p>";

        self::assertSame("<p>a</p>\n<p>b</p>", $this->engine->render($template));
    }

    #[Test]
    public function aCommentOverManyLinesOnItsOwnLinesCollapsesEntirely(): void
    {
        $template = "<ul>\n  <li>a</li>\n  {comment}\n    Why the list is short.\n\n    And more.\n  {endcomment}\n  <li>b</li>\n</ul>";

        self::assertSame("<ul>\n  <li>a</li>\n  <li>b</li>\n</ul>", $this->engine->render($template));
    }

    #[Test]
    public function aCommentMidLineStaysAsAuthored(): void
    {
        self::assertSame('<p>d  e</p>', $this->engine->render('<p>d {comment:mid} e</p>'));
        self::assertSame("<p>d</p>\n<p>e</p>", $this->engine->render("<p>d</p>{comment:after}\n<p>e</p>"));
        self::assertSame("<p>d</p>\n<p>e</p>", $this->engine->render("<p>d</p>\n{comment:before}<p>e</p>"));
    }

    #[Test]
    public function aCommentInsideALoopLeavesNoBlankLinePerItem(): void
    {
        $template = "{loop:items:i}\n  <b>{var:i}</b>\n  {comment}one per item{endcomment}\n{endloop}";

        self::assertSame("  <b>1</b>\n  <b>2</b>\n", $this->engine->render($template, ['items' => [1, 2]]));
    }

    #[Test]
    public function aSingleLineAssetBlockAloneOnItsLineTakesTheLine(): void
    {
        $template = "<p>a</p>\n  {js}document.body.hidden = false;{endjs}\n<p>b</p>";

        self::assertSame("<p>a</p>\n<p>b</p>", $this->engine->render($template));
    }

    #[Test]
    public function aMultiLineAssetBlockCollapsesFromItsOpeningToItsClosingTag(): void
    {
        $template = "<p>a</p>\n{css}\n.card{border:1px solid #ddd}\n\n.card p{margin:0}\n{endcss}\n<p>b</p>";

        self::assertSame("<p>a</p>\n<p>b</p>", $this->engine->render($template));
    }

    #[Test]
    public function consecutiveAssetBlocksAllCollapse(): void
    {
        $template = "<p>a</p>\n{css}.a{x:1}{endcss}\n{js}\nrun();\n{endjs}\n  {css}.b{x:2}{endcss}\n<p>b</p>";

        self::assertSame("<p>a</p>\n<p>b</p>", $this->engine->render($template));
    }

    #[Test]
    public function anAssetBlockIsStillGatheredWhereItsLineWent(): void
    {
        $template = "<p>a</p>\n{js}run();{endjs}\n<p>b</p>";
        $gathered = $this->engine->gatherAssets($template);

        self::assertSame("<p>a</p>\n<p>b</p>", $this->engine->render($template));
        self::assertSame(['run();'], $gathered->js);
    }

    protected function setUp(): void
    {
        $this->engine = new DtmplEngine();
    }
}
