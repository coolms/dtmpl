<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Widget;

use function is_string;

/**
 * Config-backed {@see WidgetTemplateResolverInterface}: looks the widget key up
 * in the `dtmpl.widget_templates` override map, falling back to the renderer's
 * built-in default when the key is absent or blank.
 *
 * Registered explicitly in {@see \CoolMS\DtmplBundle\DependencyInjection\DtmplExtension}
 * with the config map as its argument (Domain services are wired there, not
 * auto-scanned).
 */
final readonly class WidgetTemplateResolver implements WidgetTemplateResolverInterface
{
    /** @param array<string, string> $overrides widget key ⇒ theme-partial path */
    public function __construct(private array $overrides = [])
    {
    }

    public function resolve(string $key, string $default): string
    {
        $path = $this->overrides[$key] ?? null;

        return is_string($path) && '' !== $path ? $path : $default;
    }
}
