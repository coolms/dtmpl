<?php

declare(strict_types=1);
namespace CoolMS\Dtmpl\Loader;

use CoolMS\Dtmpl\Exception\TemplateException;
use CoolMS\Dtmpl\Storage\TemplateStorageInterface;

/**
 * Loads template partials from the Virtual File System. Priority 10.
 *
 * Paths are resolved relative to the calling template's VFS directory.
 * Degrades gracefully when $storage is null -- supports() returns false
 * and load() throws TemplateException.
 */
final readonly class VfsTemplateLoader implements PrioritizedLoaderInterface
{
    public function __construct(
        private ?TemplateStorageInterface $storage = null,
    ) {
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function supports(string $path, string $basePath = ''): bool
    {
        if (null === $this->storage) {
            return false;
        }

        return $this->storage->exists($this->resolve($path, $basePath));
    }

    public function load(string $path, string $basePath = ''): string
    {
        if (null === $this->storage) {
            throw new TemplateException("Cannot load template '$path': VFS storage is not available.");
        }

        $resolvedPath = $this->resolve($path, $basePath);
        $content = $this->storage->read($resolvedPath);
        if (false === $content) {
            throw new TemplateException("Cannot load included template '$resolvedPath': file not found or unreadable.");
        }

        return $content;
    }

    public function resolve(string $path, string $basePath = ''): string
    {
        // Absolute VFS path -- use as-is
        if (str_starts_with($path, '/')) {
            return $this->normalizePath($path);
        }

        // Relative path -- resolve against the directory of the calling template
        $baseDir = '' !== $basePath ? dirname($basePath) : '/';
        $combined = rtrim($baseDir, '/') . '/' . $path;

        return $this->normalizePath($combined);
    }

    /**
     * Normalize a VFS path by collapsing redundant . segments.
     * Note: VFS already rejects .. traversal, so we only handle . (current-dir).
     */
    private function normalizePath(string $path): string
    {
        $parts = explode('/', $path);
        $normalized = [];
        foreach ($parts as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }
            $normalized[] = $part;
        }

        return '/' . implode('/', $normalized);
    }
}
