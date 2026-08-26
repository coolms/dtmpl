<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Widget;

use LogicException;
use Stringable;

use function sprintf;

/**
 * A widget's "render this theme view with this data" result.
 *
 * Returning a {@see WidgetView} (instead of building an HTML string in
 * PHP via sprintf/heredoc) lets a widget stay a thin **view-model**: it
 * computes data and names a theme partial, and the DTMPL Executor renders
 * that partial through the active theme's loader -- the same machinery an
 * `{include}` uses. The partial owns the markup (and any `{verbatim}<script>`),
 * so HTML/JS never live in PHP.
 *
 * It implements {@see Stringable} only to satisfy
 * {@see WidgetRendererInterface}'s `?Stringable` return type without a
 * breaking signature change. It must never be stringified directly -- the
 * Executor detects it and renders the partial -- so `__toString()` throws
 * as a guard against accidental concatenation.
 */
final readonly class WidgetView implements Stringable
{
    /**
     * @param string               $template theme-relative partial path (resolved by the loader, like an `{include}` path)
     * @param array<string, mixed> $data     the view's context data (overlaid on the caller context)
     */
    public function __construct(
        public string $template,
        public array $data = [],
    ) {
    }

    public function __toString(): string
    {
        throw new LogicException(sprintf('WidgetView(%s) must be rendered by the DTMPL Executor, not stringified directly.', $this->template));
    }
}
