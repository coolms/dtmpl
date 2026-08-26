<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Widget;

use Stringable;

/**
 * Contract for DTMPL widget renderers.
 *
 * Implementations are auto-tagged with 'dtmpl.widget' and collected into
 * WidgetRegistry. Tag services with the interface for autoconfiguration.
 *
 * Key format: 'namespace:id' (e.g., 'form:login', 'nav:breadcrumbs')
 *             or just 'namespace' for the short form (e.g., 'breadcrumbs').
 */
interface WidgetRendererInterface
{
    /**
     * Unique registry key -- matches the template tag:
     *   'form:login'      -- {widget:form:login}
     *   'nav:breadcrumbs' -- {widget:nav:breadcrumbs}
     *   'breadcrumbs'     -- {widget:breadcrumbs}
     */
    public string $key { get; }

    /**
     * Produce a Stringable output for the widget given context and tag
     * parameters, or null when the widget has no result to emit.
     *
     * **Return markup as a `WidgetView` or a `RenderedHtml`.** The engine
     * encodes what it emits, and those two are how a value says it is
     * already HTML -- a bare Stringable is treated as a value and
     * encoded, which is the safe default when the engine cannot tell
     * whether a renderer escaped what it interpolated. Returning a
     * `WidgetView` is usually the better answer anyway: the partial gets
     * the encoding right for you.
     *
     * `null` means "nothing to show". Prefer it to an empty string --
     * the caller can then drop the surrounding markup, and a widget
     * alone on a line takes that line with it.
     *
     * @param array<string, mixed> $context current template context
     * @param array<string, mixed> $params  tag-level parameters
     */
    public function __invoke(array $context, array $params = []): ?Stringable;
}
