# Filters

Filters follow a path, separated by spaces. **One colon opens a filter's
argument list; commas separate the arguments inside it:**

```
{var:title uppercase}
{var:body truncate:120}
{var:price currency:`EUR`,`de_DE`}
{var:title lowercase capitalize}
```

A second colon is a syntax error naming the corrected form -- it is not a second
argument separator, and never was.

They apply left to right, so the last one wins the final shape.

Below, `filter:a,b` means "takes arguments `a` then `b`". Every argument is
optional unless stated; the defaults are in the table.

---

## The one that catches everybody

`default` and `coalesce` look interchangeable. They are not.

```
{var:count default:`none`}
{var:count coalesce:`none`}
```

With `count = 0` the first prints `none` and the second prints `0`.

`default` falls back on any **falsy** value, which includes `0`, `"0"`, `""`,
`false` and empty arrays. `coalesce` falls back only on `null`, `""` and
`false`, so a real zero survives.

Reach for `coalesce` on anything numeric. Use `default` for strings where empty
and missing should be treated the same.

Note the neighbour: `{var:count default=`none`}` -- with an `=` rather than a
`:` -- is a different thing. That one is the **path fallback** and applies only
when the path is missing, not when it is falsy.

---

## Text

| Filter | Effect |
|---|---|
| `uppercase` | `mb_strtoupper` |
| `lowercase` | `mb_strtolower` |
| `capitalize` | first letter upper |
| `title` | Every Word Capitalised |
| `truncate:len,suffix` | cut to length, default 100, suffix `...` |
| `truncate_words:n,suffix` | cut to a whole number of words, default 30 |
| `slug` | URL-friendly lowercase slug |
| `pad:len,char,side` | `left`, `right` (default) or `both` |
| `repeat:n` | repeat the string |
| `wrap:width,break,cut` | word wrap, default width 75 |
| `replace:from,to` | substring replace; the piped value is the subject |
| `split:sep,limit` | string to array, default separator `,` |
| `escape` / `e` | encode explicitly -- rarely needed, output is encoded anyway |
| `raw` | emit as markup -- see below |

## Numbers

| Filter | Effect |
|---|---|
| `currency:code,locale` | localised money, defaults `USD` / `en_US` |
| `percentage:decimals` | multiplies by 100 and appends `%` |
| `round:precision` `floor` `ceil` `abs` | as named |
| `add:n` `sub:n` `mul:n` `div:n` `mod:n` | arithmetic |
| `clamp:min,max` | bound a value, defaults `0` / `100` |
| `filesize:precision` | bytes to a human-readable size |

## Dates

| Filter | Effect |
|---|---|
| `date:format` | defaults `Y-m-d H:i:s` |
| `relative_time` | "3 hours ago" |

## Collections

| Filter | Effect |
|---|---|
| `count` / `length` | element count; `length` also handles strings |
| `first` / `last` | ends of an array |
| `join:sep` | implode, default `, ` |
| `keys` / `values` | array parts |
| `reverse` `sort` `unique` `flatten` | as named |
| `slice:offset,length` | sub-array, keys preserved |
| `map:filter` | apply a named filter to every element |
| `filter_by:key,value` | keep elements whose `key` equals `value` |

## Logic

| Filter | Effect |
|---|---|
| `default:fallback` | fallback on any falsy value |
| `coalesce:fallback` | fallback only on null, `""` or `false` |
| `yesno:yes,no` | boolean to words, defaults `Yes` / `No` |
| `ternary:then,else` | inline choice |

## Serialisation

| Filter | Effect |
|---|---|
| `json` | pretty-printed, unescaped unicode |
| `url_encode` / `url_decode` | as named |

## Safe PHP functions

A fixed allow-list of **41** pure value-transform functions is reachable behind
a `php.` prefix:

```
{var:name php.strtoupper}
{var:x php.str_pad:8,`0`,0}
```

The list is compiled into the engine. It is:

`strtoupper` `strtolower` `ucfirst` `ucwords` `trim` `ltrim` `rtrim` `strlen`
`str_repeat` `wordwrap` `nl2br` `strip_tags` `htmlspecialchars` `htmlentities`
`urlencode` `rawurlencode` `urldecode` `substr` `mb_substr` `mb_strtoupper`
`mb_strtolower` `str_replace` `str_pad` `strrev` `number_format` `round` `ceil`
`floor` `abs` `max` `min` `count` `implode` `array_reverse` `array_unique`
`array_keys` `array_values` `date` `strtotime` `json_encode` `base64_encode`

Anything not on it raises a `SecurityException` rather than being called -- a
template can never reach an arbitrary PHP function. The list is not
configurable: it cannot be extended or narrowed by a host, and the `php.` prefix
is reserved, so a host cannot register a filter into it either. The prefix
exists so the boundary is visible in the template.

---

## `raw`

```
{var:body raw}
```

Output is encoded by default (see [language.md](language.md#escaping)), and this
is the per-value way out. It is a filter rather than a setting so it appears at
the point of use: a reviewer can find every value that bypasses encoding by
searching for one word.

Marking is sticky -- once a value is raw, the rest of the chain stays raw:

```
{var:body escape php.nl2br}
```

encodes the body once, then keeps the `<br />` tags `nl2br` adds, instead of
encoding them a second time.

---

## Adding your own

```php
$engine->registerFilter('initials', static fn (string $v): string
    => implode('', array_map(static fn ($p) => mb_substr($p, 0, 1), explode(' ', $v))));
```

```
{var:user.fullName initials}
```

`$engine->getFilters()->register(...)` is the same thing one level down, if you
already hold the registry.

A filter is any callable taking the value first and its arguments after. Keep
them pure: they run once per value per render, and a filter that touches the
database turns one page into N queries.

A filter's return value is encoded like any other, so a filter that builds
markup must return a `CoolMS\Dtmpl\Runtime\RenderedHtml` to say so:

```php
$engine->registerFilter('badge', static fn (string $v)
    => new CoolMS\Dtmpl\Runtime\RenderedHtml('<span class="badge">' . htmlspecialchars($v) . '</span>'));
```

Names beginning `php.` are rejected -- that prefix belongs to the allow-list
above.
