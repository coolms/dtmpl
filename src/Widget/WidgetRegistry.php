<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Widget;

use Closure;
use InvalidArgumentException;

/**
 * Mutable registry of widget renderers keyed by their canonical string key.
 *
 * Not readonly -- accumulates registrations at boot time.
 * Populated by WidgetRegistryPass from all services tagged 'dtmpl.widget'.
 *
 * Resolution order (first match wins):
 *   1. Exact key  'media:gallery'          resolves to a dedicated renderer
 *   2. Namespace  'form'                   resolves to FormRenderWidgetRenderer (catch-all)
 *
 * Lazy registration: {@see registerLazy} accepts a Closure that returns the
 * renderer when invoked. The Closure is invoked on first access (via
 * {@see materialize}) and the resolved instance cached. This defers any
 * dependency-graph resolution for the renderer until after the registry's
 * containing DtmplEngine is fully constructed and stored in the DI container,
 * which is essential to break a constructor-arg cycle between DtmplEngine
 * and DocumentFormatProviderRegistry (via DocumentWidgetRenderer ->
 * WordFormatProvider).
 */
final class WidgetRegistry
{
    /** @var array<string, WidgetRendererInterface> */
    private array $renderers = [];

    /** @var array<int, Closure(): WidgetRendererInterface> */
    private array $pendingFactories = [];

    private bool $materialized = false;

    public function register(WidgetRendererInterface $renderer): void
    {
        $this->renderers[$renderer->key] = $renderer;
    }

    /**
     * Register a renderer factory (lazy). The factory is invoked at first
     * access to read the renderer's `->key` and store the instance.
     *
     * @param Closure(): WidgetRendererInterface $factory
     */
    public function registerLazy(Closure $factory): void
    {
        $this->pendingFactories[] = $factory;
        $this->materialized = false;
    }

    public function has(string $namespace, ?string $widgetId): bool
    {
        $this->materialize();
        $key = null !== $widgetId ? "$namespace:$widgetId" : $namespace;

        return isset($this->renderers[$key]) || isset($this->renderers[$namespace]);
    }

    public function get(string $namespace, ?string $widgetId): WidgetRendererInterface
    {
        $this->materialize();
        $key = null !== $widgetId ? "$namespace:$widgetId" : $namespace;

        return $this->renderers[$key]
            ?? $this->renderers[$namespace]
            ?? throw new InvalidArgumentException("Widget not found: $key");
    }

    /**
     * Returns true only when the exact namespace:widgetId key is registered.
     * Used by Executor to decide whether to inject widgetId as formId param.
     */
    public function isExactMatch(string $namespace, ?string $widgetId): bool
    {
        $this->materialize();
        $key = null !== $widgetId ? "$namespace:$widgetId" : $namespace;

        return isset($this->renderers[$key]);
    }

    /**
     * Materialise all pending lazy factories. Runs at most once per batch of
     * registrations; idempotent. By the time this is called (first widget
     * lookup during rendering), DtmplEngine is fully constructed and stored in
     * the DI container, so any renderer that transitively depends on
     * DtmplEngine resolves to the already-built instance via the container's
     * normal `$privates[...]` short-circuit.
     */
    private function materialize(): void
    {
        if ($this->materialized) {
            return;
        }
        $this->materialized = true;
        foreach ($this->pendingFactories as $factory) {
            $renderer = $factory();
            $this->renderers[$renderer->key] = $renderer;
        }
        $this->pendingFactories = [];
    }
}
