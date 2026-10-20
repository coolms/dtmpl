<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\AST\ContentNode;
use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\CircularIncludeException;
use CoolMS\Dtmpl\Exception\RuntimeException;
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Loader\FilesystemTemplateLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `{content}`: a frame draws the page's content where it says so, through the template the HOST names -- never one
 * the frame picks, and never nothing.
 */
final class ContentTagTest extends TestCase
{
    private string $dir;
    private DtmplEngine $engine;

    #[Test]
    public function itDrawsTheTemplateTheHostNames(): void
    {
        self::assertSame('<main>BLOCKS[hero]</main>', $this->render('<main>{content}</main>', 'content/landing.dtmpl'));
        self::assertSame('<main>BODY[hello]</main>', $this->render('<main>{content}</main>', 'content/page.dtmpl'));
    }

    #[Test]
    public function theContentTemplateReadsTheCallersContext(): void
    {
        self::assertSame('BODY[hello]', $this->render('{content}', 'content/page.dtmpl'));
    }

    #[Test]
    public function itWorksInsideAFillOfAnIncludedLayout(): void
    {
        self::assertSame(
            'HEAD|BODY[hello]|FOOT',
            $this->render('{include:`layout.dtmpl`}{fill:main}{content}{endfill}{endinclude}', 'content/page.dtmpl'),
        );
    }

    #[Test]
    public function unnamedIsAnErrorNotAnEmptyMiddle(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ContentNode::CONTEXT_KEY);

        $this->render('<main>{content}</main>', null);
    }

    #[Test]
    public function anEmptyNameIsAnErrorToo(): void
    {
        $this->expectException(RuntimeException::class);

        $this->render('{content}', '');
    }

    #[Test]
    public function itTakesNoArgument(): void
    {
        $this->expectException(SyntaxException::class);

        $this->render('{content:`content/page.dtmpl`}', 'content/page.dtmpl');
    }

    #[Test]
    public function aContentTemplateThatDrawsItsFrameIsACycle(): void
    {
        $this->expectException(CircularIncludeException::class);

        $this->engine->render(
            (string) file_get_contents($this->dir . '/frame.dtmpl'),
            ['page' => ['body' => 'x'], ContentNode::CONTEXT_KEY => 'loops.dtmpl'],
            $this->dir . '/frame.dtmpl',
        );
    }

    #[Test]
    public function contentStaysAnOrdinaryVariableName(): void
    {
        self::assertSame('v', $this->engine->render('{var:content.body}', ['content' => ['body' => 'v']], $this->dir . '/p.dtmpl'));
    }

    #[Test]
    public function theNamedTemplatesAssetsAreGathered(): void
    {
        $source = '<main>{content}</main>';
        $path = $this->dir . '/page.dtmpl';

        $with = $this->engine->gatherAssets($source, $path, 'content/landing.dtmpl');
        $without = $this->engine->gatherAssets($source, $path);

        self::assertStringContainsString('.blocks{}', $with->styleTag());
        self::assertStringNotContainsString('.blocks{}', $without->styleTag());
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dtmpl_content_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/content', 0o777, true);
        file_put_contents($this->dir . '/content/landing.dtmpl', '{css}.blocks{}{endcss}BLOCKS[{var:page.block}]');
        file_put_contents($this->dir . '/content/page.dtmpl', 'BODY[{var:page.body}]');
        file_put_contents($this->dir . '/layout.dtmpl', 'HEAD|{slot:main}{endslot}|FOOT');
        file_put_contents($this->dir . '/frame.dtmpl', '[{content}]');
        file_put_contents($this->dir . '/loops.dtmpl', '{include:`frame.dtmpl`}');

        $this->engine = new DtmplEngine(loader: new FilesystemTemplateLoader($this->dir));
    }

    protected function tearDown(): void
    {
        foreach ([...glob($this->dir . '/content/*') ?: [], ...glob($this->dir . '/*.dtmpl') ?: []] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/content');
        rmdir($this->dir);
    }

    private function render(string $template, ?string $content): string
    {
        $data = ['page' => ['body' => 'hello', 'block' => 'hero']];
        if (null !== $content) {
            $data[ContentNode::CONTEXT_KEY] = $content;
        }

        return $this->engine->render($template, $data, $this->dir . '/page.dtmpl');
    }
}
