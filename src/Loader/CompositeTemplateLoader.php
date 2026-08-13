<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Loader;

use CoolMS\Dtmpl\Exception\TemplateNotFoundException;
use CoolMS\Dtmpl\TemplateLoaderInterface;

/**
 * Delegates load()/resolve() to the first PrioritizedLoaderInterface
 * whose supports() returns true. Loaders are pre-sorted priority DESC
 * by LoaderChainPass before injection.
 *
 * Resolution uses two strategies, tried in order:
 *
 *   Strategy 1 -- logical name (root-relative):
 *     Passes $path with an empty basePath to each loader's supports().
 *     Every loader resolves from its own configured root directory, so
 *     'forms/input.html.dtmpl' finds theme-bootstrap/templates/forms/input.html.dtmpl
 *     regardless of which template issued the {include:} tag.
 *
 *   Strategy 2 -- directory-relative:
 *     Passes the original $basePath through, so each loader prepends
 *     dirname($basePath) to $path.
 *     Used only for genuinely relative paths (e.g. {include:`../partials/nav.html.dtmpl`}).
 *
 * Throws TemplateNotFoundException when neither strategy finds a match.
 */
readonly class CompositeTemplateLoader implements TemplateLoaderInterface
{
    /** @param list<PrioritizedLoaderInterface> $loaders Pre-sorted DESC by priority */
    public function __construct(
        private array $loaders = [],
    ) {
    }

    public function load(string $path, string $basePath = ''): string
    {
        // Strategy 1: root-relative -- try logical name against each loader's own base
        foreach ($this->loaders as $loader) {
            if ($loader->supports($path, '')) {
                return $loader->load($path, '');
            }
        }

        // Strategy 2: dir-relative -- for ../sibling patterns when basePath is set
        if ('' !== $basePath) {
            foreach ($this->loaders as $loader) {
                if ($loader->supports($path, $basePath)) {
                    return $loader->load($path, $basePath);
                }
            }
        }

        throw new TemplateNotFoundException($path, $basePath);
    }

    public function resolve(string $path, string $basePath = ''): string
    {
        // Strategy 1: root-relative -- try logical name against each loader's own base
        foreach ($this->loaders as $loader) {
            if ($loader->supports($path, '')) {
                return $loader->resolve($path, '');
            }
        }

        // Strategy 2: dir-relative -- for ../sibling patterns when basePath is set
        if ('' !== $basePath) {
            foreach ($this->loaders as $loader) {
                if ($loader->supports($path, $basePath)) {
                    return $loader->resolve($path, $basePath);
                }
            }
        }

        throw new TemplateNotFoundException($path, $basePath);
    }
}
