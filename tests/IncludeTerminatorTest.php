<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Loader\FilesystemTemplateLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `{endinclude}` is optional, and nothing proved it.
 *
 * Every include in the existing suite was written with fills, so the
 * bare form -- which the shipped themes use roughly twice as often as
 * the terminated one -- had no coverage at all. That is how README and
 * docs/language.md were able to show `{endinclude}` as mandatory
 * without anything failing.
 */
final class IncludeTerminatorTest extends TestCase
{
    private string $dir;
    private DtmplEngine $engine;

    #[Test]
    public function aBareIncludeNeedsNoTerminator(): void
    {
        self::assertSame('HEADER[dflt]', $this->render('{include:`header.dtmpl`}'));
    }

    #[Test]
    public function theTerminatedFormIsIdentical(): void
    {
        self::assertSame(
            $this->render('{include:`header.dtmpl`}'),
            $this->render('{include:`header.dtmpl`}{endinclude}'),
        );
    }

    #[Test]
    public function aBareIncludeDoesNotSwallowWhatFollows(): void
    {
        self::assertSame('HEADER[dflt] tail', $this->render('{include:`header.dtmpl`} tail'));
        self::assertSame('HEADER[dflt]HEADER[dflt]', $this->render('{include:`header.dtmpl`}{include:`header.dtmpl`}'));
    }

    #[Test]
    public function aBareIncludeStillTakesParameters(): void
    {
        // The parameter is scoped to the PARTIAL's context, not the
        // caller's -- so this asserts it inside the partial, which is
        // the only place it exists.
        self::assertSame('WHO[me]', $this->render('{include:`who.dtmpl` who=`me`}'));
    }

    #[Test]
    public function fillsRequireTheTerminator(): void
    {
        // The terminator is what closes the fill list, so leaving it off
        // when fills ARE present is a real error -- this is the half of
        // the rule that must keep failing.
        $this->expectException(SyntaxException::class);

        $this->render('{include:`header.dtmpl`}{fill:body}X{endfill}');
    }

    #[Test]
    public function fillsWithTheTerminatorWork(): void
    {
        self::assertSame('HEADER[X]', $this->render('{include:`header.dtmpl`}{fill:body}X{endfill}{endinclude}'));
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dtmpl_include_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir . '/header.dtmpl', 'HEADER[{slot:body default=`dflt`}]');
        file_put_contents($this->dir . '/who.dtmpl', 'WHO[{var:who}]');

        $this->engine = new DtmplEngine(loader: new FilesystemTemplateLoader($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function render(string $template): string
    {
        return $this->engine->render($template, [], $this->dir . '/page.dtmpl');
    }
}
