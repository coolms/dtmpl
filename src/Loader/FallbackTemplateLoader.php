<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Loader;

use CoolMS\Dtmpl\Exception\TemplateNotFoundException;
use CoolMS\Dtmpl\TemplateLoaderInterface;
use Throwable;

/**
 * Last-resort loader in the template chain. Always tried after all other loaders.
 *
 * Wraps FilesystemTemplateLoader ($secondary) as the static fallback. Call
 * withPrimary() to get a clone that tries a per-render loader first and
 * falls back to $secondary on TemplateNotFoundException:
 *
 *   $activeLoader = $this->fallbackLoader->withPrimary($themeLoader);
 *
 * $primary is not a constructor parameter -- set exclusively via withPrimary().
 */
final class FallbackTemplateLoader implements PrioritizedLoaderInterface
{
    private ?TemplateLoaderInterface $primary = null;

    public function __construct(
        private readonly FilesystemTemplateLoader $secondary,
    ) {
    }

    /**
     * Returns a new instance with the given primary loader.
     * Used per-render to layer a theme-specific loader on top of the filesystem fallback.
     */
    public function withPrimary(TemplateLoaderInterface $primary): self
    {
        return clone ($this, [
            'primary' => $primary,
        ]);
    }

    public function getPriority(): int
    {
        return -10;
    }

    /**
     * Returns true when the primary (if set) or secondary can handle $path.
     * Must not throw -- returns false on any uncertainty.
     *
     * For primaries that do not implement PrioritizedLoaderInterface, assumes
     * coverage and defers failure to load()/resolve().
     */
    public function supports(string $path, string $basePath = ''): bool
    {
        if (null !== $this->primary) {
            if ($this->primary instanceof PrioritizedLoaderInterface) {
                try {
                    if ($this->primary->supports($path, $basePath)) {
                        return true;
                    }
                } catch (Throwable) {
                    // primary failed to answer -- fall through to secondary
                }
            } else {
                // primary has no supports() contract -- assume it covers this path
                return true;
            }
        }

        return $this->secondary->supports($path, $basePath);
    }

    public function load(string $path, string $basePath = ''): string
    {
        if (null !== $this->primary) {
            try {
                return $this->primary->load($path, $basePath);
            } catch (TemplateNotFoundException) {
                // fall through to secondary
            }
        }

        return $this->secondary->load($path, $basePath);
    }

    public function resolve(string $path, string $basePath = ''): string
    {
        if (null !== $this->primary) {
            try {
                return $this->primary->resolve($path, $basePath);
            } catch (TemplateNotFoundException) {
                // fall through to secondary
            }
        }

        return $this->secondary->resolve($path, $basePath);
    }
}
