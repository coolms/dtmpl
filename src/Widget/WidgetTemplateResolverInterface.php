<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Widget;

/**
 * Resolves the theme-partial path a widget renders, from a central config map
 * (`dtmpl.widget_templates`) keyed by a widget key (e.g. `comments`, `media`,
 * or a sub-key like `embed.audio`).
 *
 * Lets an integrator repoint any widget's partial in one config place -- without
 * editing the renderer or forking a theme. The returned path is still resolved
 * against the active theme by the executor, so theme override composes on top
 * (config picks *which* partial name; the theme provides *the file* for it).
 *
 * A renderer passes its built-in default as the fallback, so an omitted config
 * key keeps the widget working.
 */
interface WidgetTemplateResolverInterface
{
    /**
     * The configured partial path for `$key`, or `$default` when unconfigured.
     */
    public function resolve(string $key, string $default): string;
}
