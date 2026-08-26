<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Widget;

use ArrayAccess;
use ArrayIterator;
use CoolMS\Dtmpl\Runtime\HtmlSafe;
use IteratorAggregate;
use LogicException;
use Traversable;

use function is_array;

/**
 * The bound value of a widget that returned a {@see WidgetView}.
 *
 * A widget is a "module-contributed value source: data + optional view". When
 * such a widget is bound with `{def:x=widget:...}`, this wrapper lets the SAME
 * name behave two ways with no ambiguity:
 *
 *   - `{var:x}` (and the inline `{widget:...}` concat path) → **stringifies to the
 *     eagerly-rendered partial HTML** (identical to the previous
 *     {@see \CoolMS\Dtmpl\Runtime\RenderedHtml} behaviour -- fully
 *     backward-compatible).
 *   - `{loop:x:item}` → **iterates the widget's data** -- its `items` list when
 *     the view names one (the {@see WidgetView::$data}`['items']` convention that
 *     the media/gallery view already uses), else the data map itself.
 *   - `{var:x.field}` → **reads `data[field]`** via the Executor's
 *     PropertyAccessor magic-get, so a single-record data-widget exposes its
 *     fields.
 *
 * The semantic is: "unwrap a WidgetView to its
 * `.data` for loop/property access" while keeping `{var:x}` = rendered HTML. It
 * is what makes a data-widget like `widget:vfs` usable:
 *   {def:articles=widget:vfs:`news/tech`}
 *   {loop:articles:a}<a href="{var:a.href}">{var:a.title}</a>{endloop}
 *
 * Deliberately NOT `Countable`: a public `count()` method would be resolved by
 * the Executor's PropertyAccessor as the getter for a `count` data field, and
 * `count` is a common key (e.g. a VFS listing's size). Read the count from the
 * data instead -- `{var:x.count}`.
 *
 * @implements IteratorAggregate<int|string, mixed>
 * @implements ArrayAccess<string, mixed>
 */
final class WidgetResult implements HtmlSafe, IteratorAggregate, ArrayAccess
{
    /**
     * @param string               $html the eagerly-rendered partial HTML (what `{var:x}` emits)
     * @param array<string, mixed> $data the widget's view data (what `{loop}`/`{var:x.field}` reach)
     */
    public function __construct(
        private readonly string $html,
        private readonly array $data,
    ) {
    }

    public function __toString(): string
    {
        return $this->html;
    }

    /**
     * Magic-get backs `{var:x.field}` -- the Executor's PropertyAccessor is built
     * with MAGIC_GET, so a dotted path segment on a WidgetResult resolves here.
     */
    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    /**
     * `{loop:x:item}` iterates the canonical `items` list when the view names
     * one, else the data map itself.
     */
    public function getIterator(): Traversable
    {
        $items = isset($this->data['items']) && is_array($this->data['items'])
            ? $this->data['items']
            : $this->data;

        return new ArrayIterator($items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('WidgetResult is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('WidgetResult is read-only.');
    }
}
