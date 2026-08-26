<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

/**
 * A string that IS markup and must reach the output unencoded.
 *
 * The general-purpose {@see HtmlSafe} carrier, and the documented way a
 * host emits trusted HTML through `{var:}`: put a `RenderedHtml` in the
 * render context instead of a plain string and it passes through
 * {@see Output::emit()} untouched.
 *
 * Also what widget renderers return, and what the `raw` / `escape`
 * filters produce -- deferring string conversion to the latest possible
 * point so a widget result can travel the pipeline as an object and be
 * stringified by the Executor at concatenation time.
 */
final readonly class RenderedHtml implements HtmlSafe
{
    public function __construct(
        private string $html,
    ) {
    }

    /**
     * Mark any value as HTML-safe, stringifying it the same way the
     * Executor would. What the `raw` filter calls.
     */
    public static function of(mixed $value): self
    {
        return $value instanceof HtmlSafe
            ? new self((string) $value)
            : new self(Output::stringify($value));
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
