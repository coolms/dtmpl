<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Loader;

use CoolMS\Dtmpl\Exception\TemplateException;
use CoolMS\Dtmpl\Exception\TemplateNotFoundException;

/**
 * Loads template source files from the real filesystem. Priority 0.
 *
 * Resolves paths against dtmpl.template_base_path when no calling template
 * directory is available, or against dirname($basePath) for relative includes.
 *
 * Only inside the base path. A template is often content somebody wrote, and
 * an include in it names a file, so an existing file is served only when its
 * canonical path (`realpath`: `..` and symlinks resolved) lies inside the
 * canonical base path. An absolute path, a `../` climb or a symlink that lands
 * outside is not found. Before this, an absolute path was used as-is and a
 * relative one was joined with no check, so an include could read any file the
 * process could.
 */
final readonly class FilesystemTemplateLoader implements PrioritizedLoaderInterface
{
    /** The base path as the filesystem names it, or null when it does not exist. */
    private ?string $root;

    /**
     * @param string $basePath root the loader resolves relative paths against, and the only place it reads
     */
    public function __construct(
        public readonly string $basePath,
    ) {
        $root = realpath($basePath);
        $this->root = false === $root ? null : rtrim($root, '/');
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function supports(string $path, string $basePath = ''): bool
    {
        try {
            return file_exists($this->resolve($path, $basePath));
        } catch (TemplateNotFoundException) {
            return false;
        }
    }

    public function load(string $path, string $basePath = ''): string
    {
        $resolvedPath = $this->resolve($path, $basePath);

        if (!file_exists($resolvedPath)) {
            throw new TemplateNotFoundException($path, $basePath);
        }

        $content = file_get_contents($resolvedPath);

        if (false === $content) {
            throw new TemplateException("Cannot read template file: '$resolvedPath'");
        }

        return $content;
    }

    /**
     * @throws TemplateNotFoundException when the path names an existing file outside the base path
     */
    public function resolve(string $path, string $basePath = ''): string
    {
        if (str_starts_with($path, '/')) {
            $candidate = $path;
        } else {
            // Relative path -- resolve against the directory of the calling template,
            // or against the configured base_path when no calling template is set.
            $baseDir = '' !== $basePath
                ? dirname($basePath)
                : rtrim($this->basePath, '/');
            $candidate = rtrim($baseDir, '/') . '/' . $path;
        }

        $real = realpath($candidate);
        if (false === $real) {
            // Nothing there: load() reports it not found, supports() says no.
            return $candidate;
        }
        if (null === $this->root || !str_starts_with($real, $this->root . '/')) {
            throw new TemplateNotFoundException($path, $basePath);
        }

        return $real;
    }
}
