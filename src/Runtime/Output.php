<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use DateTimeInterface;
use Stringable;

/**
 * The one place a value becomes output text.
 *
 * Two steps, deliberately separate:
 *   1. {@see stringify()} -- turn any PHP value into its template
 *      representation. No encoding.
 *   2. {@see escape()}    -- HTML-encode a string.
 *
 * {@see emit()} composes them and is what every leaf tag calls:
 * `{var:}`, `{const:}`, `{t:}`. Step 2 is skipped for two reasons and
 * no others: the value carries {@see HtmlSafe} (it is already markup),
 * or the render is not producing HTML at all ({@see OutputMode::Text}).
 *
 * Rendered fragments do NOT come through here. The output of
 * `{include}`, `{slot}`, `{fill}`, a widget partial and literal template
 * text is already template output; encoding it would double-encode every
 * page. Encoding belongs on the leaves, where a context VALUE crosses
 * into markup.
 *
 * Lives as a static helper rather than a service because both the
 * {@see Executor} and the {@see FilterRegistry} need the identical rule,
 * and a second implementation of "how a value becomes text" is how the
 * two drift apart.
 */
final class Output
{
    /**
     * Flags used for every encode in the package.
     *
     * `ENT_QUOTES` covers both quote styles, so a value is safe inside a
     * single- or double-quoted attribute, not just in element text.
     *
     * `ENT_SUBSTITUTE` matters more than it looks: without it,
     * `htmlspecialchars()` returns the EMPTY STRING for a value carrying
     * invalid UTF-8, so one bad byte in one database row silently deletes
     * the whole value from the page. Substituting U+FFFD keeps the rest.
     */
    public const int FLAGS = ENT_QUOTES | ENT_SUBSTITUTE;

    /**
     * Render a value as output text, encoding it for `$mode` unless it
     * is marked {@see HtmlSafe}.
     */
    public static function emit(mixed $value, OutputMode $mode = OutputMode::Html): string
    {
        if ($value instanceof HtmlSafe) {
            return (string) $value;
        }

        $text = self::stringify($value);

        return OutputMode::Html === $mode ? self::escape($text) : $text;
    }

    /**
     * HTML-encode a string.
     */
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, self::FLAGS, 'UTF-8');
    }

    /**
     * Convert a value to its template representation, without encoding.
     *
     * `null` is the empty string rather than the literal "null" so an
     * absent path renders as nothing -- the null-on-miss contract
     * {@see Context::get()} keeps.
     */
    public static function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Fallback to JSON
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
