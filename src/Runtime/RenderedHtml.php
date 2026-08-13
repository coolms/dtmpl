<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use Stringable;

/**
 * Stringable wrapper for HTML output produced by widget renderers.
 *
 * Defers string conversion to the latest possible point so widget
 * results can pass through the rendering pipeline as objects and be
 * stringified by the Executor at concatenation time.
 */
final readonly class RenderedHtml implements Stringable
{
    public function __construct(
        private string $html,
    ) {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
