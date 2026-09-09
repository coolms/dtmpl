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
 * There are three ways in, and the difference between the last two is the
 * whole point of this class:
 *
 *   {@see register}       an already-built renderer. Used by tests and by any
 *                         caller that has an instance in hand.
 *   {@see registerKeyed}  a key and a factory. The key is known WITHOUT
 *                         building anything, so a lookup constructs the one
 *                         renderer it matched and no others.
 *   {@see registerLazy}   a factory alone. The key can only be read off a
 *                         built instance, so the first lookup that misses the
 *                         other two paths has to build EVERY renderer
 *                         registered this way, just to learn their keys.
 *
 * `registerLazy` is kept for renderers that cannot declare a `KEY` constant,
 * and for back-compat. Prefer `registerKeyed`: with every renderer keyed, a
 * page carrying one `{widget:...}` builds one renderer instead of all of them.
 *
 * Both lazy paths defer construction past the point where the containing
 * DtmplEngine is stored in the DI container, which is what breaks the
 * constructor-arg cycle between DtmplEngine and DocumentFormatProviderRegistry
 * (via DocumentWidgetRenderer -> WordFormatProvider). Keying changes how
 * MANY get built, not WHEN.
 */
final class WidgetRegistry
{
    /** @var array<string, WidgetRendererInterface> */
    private array $renderers = [];

    /** @var array<string, Closure(): WidgetRendererInterface> */
    private array $keyedFactories = [];

    /** @var array<int, Closure(): WidgetRendererInterface> */
    private array $pendingFactories = [];

    private bool $materialized = false;

    public function register(WidgetRendererInterface $renderer): void
    {
        $this->renderers[$renderer->key] = $renderer;
    }

    /**
     * Register a renderer factory under a key that is already known.
     *
     * Nothing is built here, and a later lookup builds only what it matched.
     *
     * @param Closure(): WidgetRendererInterface $factory
     */
    public function registerKeyed(string $key, Closure $factory): void
    {
        // Later registration wins, as it does for the other two paths -- so a
        // cached instance from an earlier one must go.
        unset($this->renderers[$key]);
        $this->keyedFactories[$key] = $factory;
    }

    /**
     * Register a renderer factory whose key is not known yet (lazy).
     *
     * The factory is invoked at first access that cannot be answered from the
     * keyed entries, in order to read the renderer's `->key`.
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
        $key = null !== $widgetId ? "$namespace:$widgetId" : $namespace;

        if ($this->knows($key) || $this->knows($namespace)) {
            return true;
        }

        $this->materialize();

        return isset($this->renderers[$key]) || isset($this->renderers[$namespace]);
    }

    public function get(string $namespace, ?string $widgetId): WidgetRendererInterface
    {
        $key = null !== $widgetId ? "$namespace:$widgetId" : $namespace;

        $renderer = $this->resolve($key) ?? $this->resolve($namespace);
        if (null !== $renderer) {
            return $renderer;
        }

        $this->materialize();

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
        $key = null !== $widgetId ? "$namespace:$widgetId" : $namespace;

        if ($this->knows($key)) {
            return true;
        }

        $this->materialize();

        return isset($this->renderers[$key]);
    }

    /** Is this key answerable without building anything that is not already built? */
    private function knows(string $key): bool
    {
        return isset($this->renderers[$key]) || isset($this->keyedFactories[$key]);
    }

    /** Build the renderer for exactly this key, or report that nothing claims it. */
    private function resolve(string $key): ?WidgetRendererInterface
    {
        if (isset($this->renderers[$key])) {
            return $this->renderers[$key];
        }

        if (!isset($this->keyedFactories[$key])) {
            return null;
        }

        return $this->renderers[$key] = ($this->keyedFactories[$key])();
    }

    /**
     * Build every renderer registered WITHOUT a key, because reading their keys
     * is the only way to answer a lookup they might claim. Runs at most once
     * per batch of registrations; idempotent, and a no-op when every renderer
     * came in through {@see registerKeyed}.
     *
     * By the time this can be called (first widget lookup during rendering),
     * DtmplEngine is fully constructed and stored in the DI container, so any
     * renderer that transitively depends on DtmplEngine resolves to the
     * already-built instance via the container's normal `$privates[...]`
     * short-circuit.
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
