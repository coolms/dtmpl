<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use CoolMS\Dtmpl\Exception\SecurityException;
use CoolMS\Dtmpl\Exception\UnknownFilterException;
use DateTimeInterface;
use NumberFormatter;

/**
 * Filter Registry.
 *
 * Manages built-in and custom filters with security controls.
 */
final class FilterRegistry
{
    /** Namespace that reaches {@see SAFE_PHP_FUNCTIONS}. Reserved -- see {@see register()}. */
    private const string PHP_PREFIX = 'php.';

    private const array SAFE_PHP_FUNCTIONS = [
        // String functions
        'strtoupper', 'strtolower', 'ucfirst', 'ucwords', 'trim', 'ltrim', 'rtrim',
        'strlen', 'str_repeat', 'wordwrap', 'nl2br', 'strip_tags',
        'htmlspecialchars', 'htmlentities', 'urlencode', 'rawurlencode', 'urldecode',
        'substr', 'mb_substr', 'mb_strtoupper', 'mb_strtolower',
        'str_replace', 'str_pad', 'strrev',

        // Number functions
        'number_format', 'round', 'ceil', 'floor', 'abs', 'max', 'min',

        // Array functions
        'count', 'implode', 'array_reverse', 'array_unique', 'array_keys', 'array_values',

        // Date functions
        'date', 'strtotime',

        // Encoding
        'json_encode', 'base64_encode',
    ];

    // @phpstan-ignore classConstant.unused
    private const array DANGEROUS_PHP_FUNCTIONS = [
        'eval', 'exec', 'system', 'passthru', 'shell_exec', 'popen', 'proc_open',
        'file_get_contents', 'file_put_contents', 'file', 'readfile',
        'unlink', 'rmdir', 'mkdir', 'rename', 'copy',
        'include', 'require', 'include_once', 'require_once',
        'assert', 'create_function', 'call_user_func', 'call_user_func_array',
    ];

    /** @var array<string, callable> */
    private array $filters = [];

    /** @var array<string, string> */
    private array $phpFunctions = [];

    public function __construct()
    {
        $this->registerBuiltInFilters();
        $this->registerExtendedFilters();
        $this->registerSafePHPFunctions();
    }

    /**
     * Register a custom filter.
     *
     * The `php.` prefix is reserved. {@see apply()} resolves custom
     * filters BEFORE the allow-list, so a registration under that prefix
     * would bind a name whose whole purpose is to promise the opposite --
     * that what runs is one of the vetted functions in
     * {@see SAFE_PHP_FUNCTIONS}. Refusing here fails at registration,
     * with the offending name in hand, rather than at render time inside
     * whatever template happened to use it.
     */
    public function register(string $name, callable $filter): void
    {
        if (str_starts_with($name, self::PHP_PREFIX)) {
            throw new SecurityException(sprintf('Cannot register the filter "%s": the "%s" prefix is reserved for the built-in allow-list of safe PHP functions. Register it under a name of its own.', $name, self::PHP_PREFIX));
        }

        $this->filters[$name] = $filter;
    }

    /**
     * Check if filter exists.
     */
    public function has(string $name): bool
    {
        // Check custom filters
        if (isset($this->filters[$name])) {
            return true;
        }

        // Check PHP functions
        if (str_starts_with($name, self::PHP_PREFIX)) {
            return isset($this->phpFunctions[substr($name, strlen(self::PHP_PREFIX))]);
        }

        return false;
    }

    /**
     * Apply filter to value.
     *
     * @param list<mixed> $arguments
     */
    public function apply(string $name, mixed $value, array $arguments = []): mixed
    {
        // Custom filter
        if (isset($this->filters[$name])) {
            return call_user_func($this->filters[$name], $value, ...$arguments);
        }

        // PHP function
        if (str_starts_with($name, self::PHP_PREFIX)) {
            $funcName = substr($name, strlen(self::PHP_PREFIX));

            if (!isset($this->phpFunctions[$funcName])) {
                throw new SecurityException("PHP function '$funcName' is not allowed");
            }

            // Call PHP function
            /** @var callable-string $callableFn */
            $callableFn = $funcName;
            if (empty($arguments)) {
                return $callableFn($value);
            }

            return $callableFn($value, ...$arguments);
        }

        throw new UnknownFilterException("Unknown filter: $name");
    }

    /**
     * Get list of available filters.
     *
     * @return list<string>
     */
    public function getAvailableFilters(): array
    {
        $filters = array_keys($this->filters);
        $phpFilters = array_map(fn ($f) => self::PHP_PREFIX . $f, array_keys($this->phpFunctions));

        return array_merge($filters, $phpFilters);
    }

    /**
     * Register built-in filters.
     */
    private function registerBuiltInFilters(): void
    {
        // String filters
        $this->register('uppercase', fn ($v) => mb_strtoupper((string) $v));
        $this->register('lowercase', fn ($v) => mb_strtolower((string) $v));
        $this->register('capitalize', fn ($v) => ucfirst((string) $v));
        $this->register('title', fn ($v) => ucwords((string) $v));

        // Truncate with ellipsis
        $this->register('truncate', function ($value, $length = 100, $suffix = '...') {
            $str = (string) $value;
            if (mb_strlen($str) <= $length) {
                return $str;
            }

            return mb_substr($str, 0, $length) . $suffix;
        });

        // Escape for HTML. Returns an HtmlSafe so the encoded string is
        // not encoded a SECOND time on the way out -- `{var:x escape}`
        // must produce `&lt;b&gt;`, never `&amp;lt;b&amp;gt;`.
        $escape = static fn ($v) => new RenderedHtml(Output::escape(Output::stringify($v)));
        $this->register('escape', $escape);
        $this->register('e', $escape);

        // Raw -- the opt-out from output encoding. Marks the value as
        // markup so Output::emit() passes it through untouched.
        // Dangerous by design: only for HTML you control.
        $this->register('raw', static fn ($v) => RenderedHtml::of($v));

        // Default value
        $this->register('default', fn ($v, $default = '') => $v ?: $default);

        // Number filters
        $this->register('currency', function ($value, $currency = 'USD', $locale = 'en_US') {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

            return $formatter->formatCurrency((float) $value, $currency);
        });

        $this->register('percentage', fn ($value, $decimals = 0) => number_format((float) $value * 100, $decimals) . '%');

        // Array filters
        $this->register('count', fn ($v) => is_countable($v) ? count($v) : 0);
        $this->register('length', fn ($v) => is_string($v) ? mb_strlen($v) : (is_countable($v) ? count($v) : 0));
        $this->register('first', fn ($v) => is_array($v) && !empty($v) ? reset($v) : null);
        $this->register('last', fn ($v) => is_array($v) && !empty($v) ? end($v) : null);
        $this->register('join', fn ($v, $sep = ', ') => is_array($v) ? implode($sep, $v) : (string) $v);
        $this->register('keys', fn ($v) => is_array($v) ? array_keys($v) : []);
        $this->register('values', fn ($v) => is_array($v) ? array_values($v) : []);

        // Date filters
        $this->register('date', function ($value, $format = 'Y-m-d H:i:s') {
            if ($value instanceof DateTimeInterface) {
                return $value->format($format);
            }
            if (is_numeric($value)) {
                return date($format, (int) $value);
            }
            if (is_string($value)) {
                $timestamp = strtotime($value);

                return false !== $timestamp ? date($format, $timestamp) : $value;
            }

            return (string) $value;
        });

        $this->register('relative_time', function ($value) {
            $time = null;

            if ($value instanceof DateTimeInterface) {
                $time = $value->getTimestamp();
            } elseif (is_numeric($value)) {
                $time = (int) $value;
            } elseif (is_string($value)) {
                $time = strtotime($value);
            }

            if (null === $time || false === $time) {
                return (string) $value;
            }

            $diff = time() - $time;

            if ($diff < 60) {
                return 'just now';
            } elseif ($diff < 3600) {
                $mins = floor($diff / 60);

                return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
            } elseif ($diff < 86400) {
                $hours = floor($diff / 3600);

                return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
            } elseif ($diff < 604800) {
                $days = floor($diff / 86400);

                return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
            }

            return date('M j, Y', $time);
        });

        // Format filters
        $this->register('json', fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // URL filters
        $this->register('url_encode', fn ($v) => urlencode((string) $v));
        $this->register('url_decode', fn ($v) => urldecode((string) $v));

        // Boolean filters
        $this->register('yesno', fn ($v, $yes = 'Yes', $no = 'No') => $v ? $yes : $no);
    }

    /**
     * Register extended filters: string, array, math, and conditional.
     */
    private function registerExtendedFilters(): void
    {
        // --- String filters ---

        // slug: URL-friendly lowercase slug
        $this->register('slug', function ($v) {
            $str = mb_strtolower((string) $v, 'UTF-8');
            $str = preg_replace('/[^\p{L}\p{N}]+/u', '-', $str);

            return trim((string) $str, '-');
        });

        // pad: str_pad($value, $length, $padChar, $type: right|left|both)
        $this->register('pad', function ($v, $length = 0, $pad = ' ', $type = 'right') {
            $padType = match ($type) {
                'left' => STR_PAD_LEFT,
                'both' => STR_PAD_BOTH,
                default => STR_PAD_RIGHT,
            };

            return str_pad((string) $v, (int) $length, (string) $pad, $padType);
        });

        // repeat: str_repeat
        $this->register('repeat', fn ($v, $times = 1) => str_repeat((string) $v, max(0, (int) $times)));

        // wrap: wordwrap
        $this->register('wrap', fn ($v, $width = 75, $break = "\n", $cut = false) => wordwrap((string) $v, (int) $width, (string) $break, (bool) $cut));

        // split: explode a string into an array -- the inverse of `join`.
        // Usage: {var:csv split:`,`} -> ['a','b','c'] ; {var:word split:``} -> per-char.
        $this->register('split', function ($v, $separator = ',', $limit = PHP_INT_MAX) {
            if (is_array($v)) {
                return $v;
            }
            $str = (string) $v;
            $sep = (string) $separator;
            if ('' === $sep) {
                // explode() rejects an empty separator; split per character instead.
                return '' === $str ? [] : mb_str_split($str);
            }

            return explode($sep, $str, (int) $limit);
        });

        // replace: str_replace with a template-friendly arg order -- the piped
        // value is the SUBJECT. (`php.str_replace` is unusable as a filter: the
        // value lands as str_replace()'s FIRST arg, the search needle, so it
        // replaces the whole value rather than substituting within it.)
        // Usage: {var:text replace:`foo`,`bar`}
        $this->register('replace', fn ($v, $search = '', $replace = '') => str_replace((string) $search, (string) $replace, (string) $v));

        // truncate_words: keep the first $count whitespace-separated words,
        // appending $suffix only when truncation actually happened. The
        // WORD-based counterpart of the char-based `truncate` -- for excerpts /
        // teasers where cutting mid-word looks broken. A value already within
        // the limit (or a non-positive limit) is returned untouched.
        // Usage: {var:body truncate_words:`30`} ; {var:body truncate_words:`20`,` ...`}
        $this->register('truncate_words', function ($value, $count = 30, $suffix = '...') {
            $str = (string) $value;
            $limit = (int) $count;
            if ($limit <= 0) {
                return $str;
            }
            $words = preg_split('/\s+/u', trim($str), -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($words) || count($words) <= $limit) {
                return $str;
            }

            return implode(' ', array_slice($words, 0, $limit)) . (string) $suffix;
        });

        // filesize: render a byte count human-readably -- 1536 -> "1.5 KB",
        // 0 -> "0 B" -- using binary (1024) steps up to YB. Bytes never carry a
        // decimal; KB and up honour $precision (default 1). A non-numeric value
        // passes through unchanged (the math-filter convention). No equivalent
        // single `php.*` function exists, hence a first-class filter.
        // Usage: {var:bytes filesize} ; {var:bytes filesize:`2`}
        $this->register('filesize', function ($v, $precision = 1) {
            if (!is_numeric($v)) {
                return (string) $v;
            }
            $bytes = (float) $v;
            $sign = $bytes < 0 ? '-' : '';
            $bytes = abs($bytes);
            $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
            $i = 0;
            $last = count($units) - 1;
            while ($bytes >= 1024 && $i < $last) {
                $bytes /= 1024;
                ++$i;
            }
            $decimals = 0 === $i ? 0 : max(0, (int) $precision);

            return $sign . number_format($bytes, $decimals) . ' ' . $units[$i];
        });

        // --- Array filters ---

        // sort: sort array values, preserving keys
        $this->register('sort', function ($v) {
            if (!is_array($v)) {
                return $v;
            }
            $arr = $v;
            asort($arr);

            return $arr;
        });

        // reverse: reverse array (keys preserved) or string
        $this->register('reverse', function ($v) {
            if (is_array($v)) {
                return array_reverse($v, true);
            }
            if (is_string($v)) {
                return strrev($v);
            }

            return $v;
        });

        // slice: array_slice or mb_substr
        $this->register('slice', function ($v, $offset = 0, $length = null) {
            if (is_array($v)) {
                return array_slice($v, (int) $offset, null !== $length ? (int) $length : null, true);
            }
            if (is_string($v)) {
                return mb_substr($v, (int) $offset, null !== $length ? (int) $length : null, 'UTF-8');
            }

            return $v;
        });

        // unique: array_unique
        $this->register('unique', fn ($v) => is_array($v) ? array_unique($v) : $v);

        // flatten: flatten one level deep
        $this->register('flatten', function ($v) {
            if (!is_array($v)) {
                return $v;
            }

            return array_merge(...array_map(
                fn ($item) => is_array($item) ? $item : [$item],
                $v,
            ));
        });

        // map: apply a named filter to every element of an array
        // Usage: {var:names map:`uppercase`}
        $this->register('map', function ($v, $filterName = 'raw') {
            if (!is_array($v)) {
                return $v;
            }

            return array_map(fn ($item) => $this->apply((string) $filterName, $item), $v);
        });

        // filter_by: keep array items where $key equals $value
        // Usage: {var:users filter_by:`role`,`admin`}
        $this->register('filter_by', function ($v, $key = '', $expected = null) {
            if (!is_array($v)) {
                return $v;
            }

            return array_values(array_filter($v, function ($item) use ($key, $expected) {
                if (is_array($item)) {
                    return ($item[$key] ?? null) === $expected;
                }
                if (is_object($item)) {
                    $getter = 'get' . ucfirst((string) $key);
                    if (method_exists($item, $getter)) {
                        return $item->$getter() === $expected;
                    }
                    if (property_exists($item, $key)) {
                        return $item->$key === $expected;
                    }
                }

                return false;
            }));
        });

        // --- Math filters ---

        $this->register('add', fn ($v, $n = 0) => is_numeric($v) && is_numeric($n) ? $v + $n : $v);
        $this->register('sub', fn ($v, $n = 0) => is_numeric($v) && is_numeric($n) ? $v - $n : $v);
        $this->register('mul', fn ($v, $n = 1) => is_numeric($v) && is_numeric($n) ? $v * $n : $v);
        $this->register('div', function ($v, $n = 1) {
            if (!is_numeric($v) || !is_numeric($n) || 0 === $n) {
                return $v;
            }

            return $v / $n;
        });
        $this->register('mod', function ($v, $n = 1) {
            if (!is_numeric($v) || !is_numeric($n) || 0 === (int) $n) {
                return $v;
            }

            return (int) $v % (int) $n;
        });

        // Convenience aliases for php.round / php.ceil / php.floor / php.abs
        $this->register('round', fn ($v, $precision = 0) => round((float) $v, (int) $precision));
        $this->register('ceil', fn ($v) => (int) ceil((float) $v));
        $this->register('floor', fn ($v) => (int) floor((float) $v));
        $this->register('abs', fn ($v) => abs((float) $v));

        // clamp: constrain value between min and max
        $this->register('clamp', fn ($v, $min = 0, $max = 100) => max((float) $min, min((float) $max, (float) $v)));

        // --- Conditional filters ---

        // coalesce: return value if non-empty, otherwise fallback
        $this->register('coalesce', fn ($v, $fallback = '') => (null !== $v && '' !== $v && false !== $v) ? $v : $fallback);

        // ternary: return $then if truthy, $else otherwise
        // Usage: {var:isAdmin ternary:`Yes`,`No`}
        $this->register('ternary', fn ($v, $then = '', $else = '') => $v ? $then : $else);
    }

    /**
     * Register safe PHP functions.
     */
    private function registerSafePHPFunctions(): void
    {
        foreach (self::SAFE_PHP_FUNCTIONS as $func) {
            $this->phpFunctions[$func] = $func;
        }
    }
}
