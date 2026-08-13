<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Loader;

use CoolMS\Dtmpl\TemplateLoaderInterface;

/**
 * Extended loader contract for the composable loader chain.
 *
 * Implementations declare their priority (higher = tried first) and
 * whether they can handle a given path. The CompositeTemplateLoader
 * iterates loaders sorted by priority DESC and delegates to the first
 * whose supports() returns true.
 */
interface PrioritizedLoaderInterface extends TemplateLoaderInterface
{
    /**
     * Higher value = higher priority. Loaders are sorted DESC before the chain is built.
     */
    public function getPriority(): int;

    /**
     * Return true if this loader can resolve the given path.
     * Must not throw -- return false on any uncertainty.
     */
    public function supports(string $path, string $basePath = ''): bool;
}
