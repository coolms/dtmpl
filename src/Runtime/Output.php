<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use BackedEnum;
use DateTimeInterface;
use Stringable;
use UnitEnum;

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
            return (string) json_encode(self::plain($value), JSON_UNESCAPED_UNICODE);
        }

        $plain = self::plain($value);

        return is_scalar($plain) ? (string) $plain : '';
    }

    /**
     * A value as output may carry it: scalars and null as they are, an array with each of its values made plain,
     * a date as text, an enum as its value (or its name), a Stringable as its string -- and any other object as
     * null. An object's own properties are never dumped: what a template reads of an object is decided where it is
     * read ({@see ObjectReadPolicy}, {@see EntityWrapper}), and an encoder walking its public properties would read
     * around both.
     */
    public static function plain(mixed $value): mixed
    {
        if (null === $value || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::plain($item);
            }

            return $out;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }
}
