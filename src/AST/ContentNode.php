<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * `{content}` -- the place in a frame where the page's own content is drawn.
 *
 * A frame is the chrome around a page (header, footer, the sheet); what goes in the middle depends on what the page
 * IS, and the frame does not decide that. The host does: it names the template that draws this page's content under
 * {@see self::CONTEXT_KEY} in the render data, and `{content}` includes that template at this point -- the same
 * loader, the same circular-include check, the same context as `{include:}`.
 *
 * !! No fallback. A `{content}` rendered without a named template is an error, not an empty string: a frame that
 * quietly draws nothing in its middle is a page that looks deliberate and says nothing.
 */
final readonly class ContentNode extends Node
{
    /** The render-data key under which the host names the content template (a loader path). */
    public const string CONTEXT_KEY = '__content';

    public function __construct(
        int $line = 0,
        int $column = 0,
        public bool $onOwnLine = false,
    ) {
        parent::__construct($line, $column);
    }
}
