<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl;

use CoolMS\Dtmpl\Exception\TemplateException;

/**
 * Abstracts template source loading for the include system.
 * The Executor receives a loader instance to resolve partial paths
 * relative to the currently executing template.
 */
interface TemplateLoaderInterface
{
    /**
     * Load the template source by a logical path.
     *
     * @param string $path     The logical path from the include tag, e.g., 'partials/card'
     * @param string $basePath VFS path of the template issuing the include, for relative resolution
     *
     * @throws TemplateException When the template cannot be found or read
     */
    public function load(string $path, string $basePath = ''): string;

    /**
     * Resolve the absolute logical path for an include.
     * Used for stable cache keys and circular-include detection.
     *
     * @param string $path     The logical path from the include tag
     * @param string $basePath VFS path of the calling template
     */
    public function resolve(string $path, string $basePath = ''): string;
}
