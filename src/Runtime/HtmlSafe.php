<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use Stringable;

/**
 * Marker for a value that is ALREADY HTML and must reach the output
 * unencoded.
 *
 * DTMPL encodes every value it emits (see {@see Output::emit()}). That is
 * the safe default: a context value is data until something says
 * otherwise. This interface is the "otherwise" -- the single, explicit
 * way for a host, a widget or the `raw` filter to say "this string IS
 * markup, do not encode it".
 *
 * A plain {@see Stringable} deliberately does NOT qualify. Marking every
 * Stringable safe would make the default fail-open: any entity, value
 * object or ORM proxy that happens to implement `__toString()` would
 * bypass encoding, which is exactly the class of value most likely to
 * carry user input. Declaring the intent costs one interface and cannot
 * happen by accident.
 *
 * Implemented by {@see RenderedHtml} (the general-purpose carrier) and
 * {@see \CoolMS\Dtmpl\Widget\WidgetResult} (a rendered widget partial).
 *
 * Safety propagates through a filter chain: once a value is HTML-safe,
 * {@see Executor::applyFilter()} hands the filter
 * the underlying string and re-marks the result, so `{var:x escape
 * php.nl2br}` is not encoded a second time.
 */
interface HtmlSafe extends Stringable
{
}
