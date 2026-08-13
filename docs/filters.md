# Filters

Filters follow a path, separated by spaces. Arguments come after a colon:

```
{var:title uppercase}
{var:body truncate:120}
{var:price currency:`EUR`:`de_DE`}
{var:title lowercase capitalize}
```

They apply left to right, so the last one wins the final shape.

---

## The one that catches everybody

`default` and `coalesce` look interchangeable. They are not.

```
{var:count default:`none`}     count = 0  ->  "none"
{var:count coalesce:`none`}    count = 0  ->  "0"
```

`default` falls back on any **falsy** value, which includes `0`, `"0"`, `""`,
`false` and empty arrays. `coalesce` falls back only on `null`, `""` and
`false`, so a real zero survives.

Reach for `coalesce` on anything numeric. Use `default` for strings where empty
and missing should be treated the same.

---

## Text

| Filter | Effect |
|---|---|
| `uppercase` | `mb_strtoupper` |
| `lowercase` | `mb_strtolower` |
| `capitalize` | first letter upper |
| `title` | Every Word Capitalised |
| `truncate:len:suffix` | cut to length, default 100, suffix `...` |
| `truncate_words:n` | cut to a whole number of words |
| `slug` | URL-friendly lowercase slug |
| `pad:len:char:side` | `left`, `right` (default) or `both` |
| `repeat:n` | repeat the string |
| `wrap:width:break:cut` | word wrap |
| `replace:from:to` | substring replace |
| `split:sep:limit` | string to array |
| `escape` / `e` | explicit HTML escape |
| `raw` | emit unescaped -- see below |

## Numbers

| Filter | Effect |
|---|---|
| `currency:code:locale` | localised money, defaults `USD` / `en_US` |
| `percentage:decimals` | multiplies by 100 and appends `%` |
| `round` `floor` `ceil` `abs` | as named |
| `add:n` `sub:n` `mul:n` `div:n` `mod:n` | arithmetic |
| `clamp:min:max` | bound a value |
| `filesize` | bytes to a human-readable size |

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
| `slice:offset:length` | sub-array |
| `map:filter` / `filter_by:key:value` | transform or narrow |

## Logic

| Filter | Effect |
|---|---|
| `default:fallback` | fallback on any falsy value |
| `coalesce:fallback` | fallback only on null, `""` or `false` |
| `yesno:yes:no` | boolean to words, defaults `Yes` / `No` |
| `ternary:then:else` | inline choice |

## Serialisation

| Filter | Effect |
|---|---|
| `json` | pretty-printed, unescaped unicode |
| `url_encode` / `url_decode` | as named |

## Safe PHP functions

A curated set of PHP string functions is reachable behind a `php.` prefix:

```
{var:name php.strtoupper}
```

The prefix exists so the boundary is visible in the template. Anything not on
the allow-list raises a `SecurityException` rather than being called -- a
template can never reach an arbitrary PHP function.

---

## `raw`

```
{var:body raw}
```

The only per-value way to emit unescaped HTML, and the reason it is a filter
rather than a setting: it appears at the point of use, so a reviewer can see
exactly which values bypass escaping by searching for one word.

---

## Adding your own

```php
$engine->filters()->register('initials', static fn (string $v): string
    => implode('', array_map(static fn ($p) => mb_substr($p, 0, 1), explode(' ', $v))));
```

```
{var:user.fullName initials}
```

A filter is any callable taking the value first and its arguments after. Keep
them pure: they run once per value per render, and a filter that touches the
database turns one page into N queries.
