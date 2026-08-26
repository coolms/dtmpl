<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Widget AST node -- {widget:ns:id} or {widget:ns}.
 *
 * Resolved at runtime by WidgetRegistry to a WidgetRendererInterface.
 */
final readonly class WidgetNode extends Node
{
    /**
     * @param array<string, mixed> $params
     * @param bool                 $onOwnLine the tag was authored alone on its source line, so an
     *                                        empty render collapses that line -- see WhitespaceTrimmer
     */
    public function __construct(
        public string $namespace, // 'form', 'nav', 'breadcrumbs'
        public ?string $widgetId = null, // 'login', 'menu' -- null for short form {widget:breadcrumbs}
        public array $params = [],
        int $line = 0,
        int $column = 0,
        public bool $onOwnLine = false,
    ) {
        parent::__construct($line, $column);
    }
}
