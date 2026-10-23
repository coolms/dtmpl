<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Exception\TemplateNotFoundException;
use CoolMS\Dtmpl\Loader\FilesystemTemplateLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * An include resolves only inside the loader's base path; anything that
 * resolves outside it is refused.
 *
 * A template is often content somebody wrote, and an include in it names a
 * file. Before this, an absolute path was used as-is, and `../` or a symlink
 * walked out of the base path, so an include could read any file the process
 * could. Each refusal below failed on that loader.
 *
 * The probes reach only what the test owns: a base path and a marker file
 * beside it, both in its own temporary directory.
 */
final class IncludesStayInsideTheRootTest extends TestCase
{
    private const string MARKER = 'MARKER-OUTSIDE-THE-ROOT';

    private string $dir;
    private string $root;

    #[Test]
    public function aTemplateInsideTheRootStillLoads(): void
    {
        $loader = new FilesystemTemplateLoader($this->root);

        self::assertSame('INSIDE', $loader->load('partials/inside.dtmpl'), 'relative to the base path');
        self::assertSame('INSIDE', $loader->load('../partials/inside.dtmpl', $this->root . '/pages/page.dtmpl'), 'a sibling');
        self::assertSame('INSIDE', $loader->load($this->root . '/partials/inside.dtmpl'), 'absolute, inside');
        // Relative to the calling template (pages/page.dtmpl), as this loader resolves.
        self::assertSame('INSIDE', $this->render('{include:`../partials/inside.dtmpl`}'), 'through the engine');
    }

    #[Test]
    public function anAbsolutePathOutsideTheRootIsRefused(): void
    {
        $outside = $this->dir . '/outside/marker.dtmpl';

        $this->assertRefused(fn () => new FilesystemTemplateLoader($this->root)->load($outside), 'an absolute path');
        self::assertStringNotContainsString(self::MARKER, $this->render('{include:`' . $outside . '`}'));
    }

    #[Test]
    public function aClimbOutOfTheRootIsRefused(): void
    {
        $loader = new FilesystemTemplateLoader($this->root);

        $this->assertRefused(fn () => $loader->load('../outside/marker.dtmpl'), 'a climb from the base path');
        $this->assertRefused(
            fn () => $loader->load('../../outside/marker.dtmpl', $this->root . '/pages/page.dtmpl'),
            'a climb from the caller',
        );
        self::assertFalse($loader->supports('../outside/marker.dtmpl'), 'supports() says no, and does not throw');
    }

    #[Test]
    public function aSymlinkIsNotFollowedOut(): void
    {
        $this->assertRefused(
            fn () => new FilesystemTemplateLoader($this->root)->load('partials/link-out/marker.dtmpl'),
            'a symlink out of the base path',
        );
    }

    /**
     * A NUL byte is not found, never an error. realpath() throws ValueError on one (PHP 8.5), where the
     * file_exists() it replaced answered false, so an include naming "\0" escaped the loader as an uncaught error.
     */
    #[Test]
    public function aNulInThePathIsNotFound(): void
    {
        $loader = new FilesystemTemplateLoader($this->root);

        $this->assertRefused(fn () => $loader->load("partials/inside.dtmpl\0"), 'a NUL after an existing name');
        $this->assertRefused(fn () => $loader->load($this->dir . "/outside/marker.dtmpl\0.dtmpl"), 'a NUL in an absolute path');
        self::assertFalse($loader->supports("partials/inside.dtmpl\0"), 'supports() says no, and does not throw');
        self::assertStringStartsNotWith('refused: ValueError', $this->render("{include:`../partials/inside.dtmpl\0`}"));
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dtmpl_root_' . bin2hex(random_bytes(4));
        $this->root = $this->dir . '/templates';
        mkdir($this->root . '/pages', 0o777, true);
        mkdir($this->root . '/partials', 0o777, true);
        mkdir($this->dir . '/outside');
        file_put_contents($this->root . '/pages/page.dtmpl', 'PAGE');
        file_put_contents($this->root . '/partials/inside.dtmpl', 'INSIDE');
        file_put_contents($this->dir . '/outside/marker.dtmpl', self::MARKER);
        symlink($this->dir . '/outside', $this->root . '/partials/link-out');
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/partials/link-out');
        foreach (['/templates/pages/page.dtmpl', '/templates/partials/inside.dtmpl', '/outside/marker.dtmpl'] as $file) {
            @unlink($this->dir . $file);
        }
        foreach (['/templates/pages', '/templates/partials', '/templates', '/outside', ''] as $dir) {
            @rmdir($this->dir . $dir);
        }
    }

    /** A refusal is the loader saying it has no such template -- counted as the assertion it is. */
    private function assertRefused(callable $load, string $what): void
    {
        try {
            $content = $load();
        } catch (TemplateNotFoundException) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail('FOUND: ' . $what . ' read a file outside the base path' . (self::MARKER === $content ? ' (the marker)' : ''));
    }

    private function render(string $template): string
    {
        try {
            return new DtmplEngine(loader: new FilesystemTemplateLoader($this->root))->render($template, [], $this->root . '/pages/page.dtmpl');
        } catch (Throwable $e) {
            return 'refused: ' . $e::class;
        }
    }
}
