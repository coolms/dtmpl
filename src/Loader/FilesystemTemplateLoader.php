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
 */
final readonly class FilesystemTemplateLoader implements PrioritizedLoaderInterface
{
    /**
     * @param string $basePath root the loader resolves relative paths against
     */
    public function __construct(
        public readonly string $basePath,
    ) {
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function supports(string $path, string $basePath = ''): bool
    {
        return file_exists($this->resolve($path, $basePath));
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

    public function resolve(string $path, string $basePath = ''): string
    {
        // Absolute path -- use as-is (real filesystem, not VFS)
        if (str_starts_with($path, '/')) {
            return $path;
        }

        // Relative path -- resolve against the directory of the calling template,
        // or against the configured base_path when no calling template is set.
        $baseDir = '' !== $basePath
            ? dirname($basePath)
            : rtrim($this->basePath, '/');

        return rtrim($baseDir, '/') . '/' . $path;
    }
}
