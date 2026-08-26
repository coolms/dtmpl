# coolms/dtmpl

[![CI](https://github.com/coolms/dtmpl/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/dtmpl/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/coolms/dtmpl)](https://packagist.org/packages/coolms/dtmpl)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**A template language for people who are not PHP developers.** Lexer, parser,
AST and runtime, with a widget seam so an application can offer components to
template authors without exposing any PHP.

```
{if:page.isPublished}
    <h1>{var:page.title uppercase}</h1>

    {loop:posts:post}
        <article>{var:post.excerpt truncate:120}</article>
    {endloop}
{endif}
```

- **Sandboxed by design** -- a template reads what the context gives it and calls
  nothing else. No PHP, no filesystem, no arbitrary calls.
- **Framework-optional** -- the engine needs no HTTP kernel and no DI container.
  For Symfony, add [`coolms/dtmpl-bundle`](https://packagist.org/packages/coolms/dtmpl-bundle).
- **Extensible where it matters** -- widgets, filters, constants and loaders are
  seams the host fills.

## Installation

```bash
composer require coolms/dtmpl
```

Requires PHP `^8.5`. Depends on `psr/cache` and two Symfony *contract* packages
(`property-access`, `translation-contracts`) -- no framework.

```php
use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Loader\FilesystemTemplateLoader;

$engine = new DtmplEngine(
    loader: new FilesystemTemplateLoader('/path/to/templates'),
);

echo $engine->render('page.html.dtmpl', ['page' => $page, 'posts' => $posts]);
```

### Symfony

```bash
composer require coolms/dtmpl-bundle
```

That registers the engine, the loader chain, the widget registry and the
constant providers.

## Syntax at a glance

Every tag is `{keyword:argument}`. Blocks close with `{endkeyword}` -- there is
no `{/keyword}` form.

| | |
|---|---|
| variable | `{var:page.title}` |
| with filters | `{var:page.title uppercase truncate:80}` |
| assignment | `{def:total=cart.count}` |
| conditional | `{if:page.isPublished}` ... `{else}` ... `{endif}` |
| negated | `{unless:user.isAnonymous}` ... `{endunless}` |
| comparison | ``{if:post.status=`published`}`` -- a single `=`; also `!=` `<` `<=` `>` `>=` |
| loop | `{loop:posts}` ... `{endloop}`, `{loop:posts:post}`, `{loop:posts odd}`, ``{loop:posts split=`, `}`` |
| include | ``{include:`partials/header`}`` -- `{endinclude}` only when it carries fills |
| slot / fill | `{slot:main}` ... `{endslot}` / `{fill:main}` ... `{endfill}` |
| constant | `{const:SITE_NAME}` |
| translation | ``{t:`Hello`}`` or ``{t:`Hello`:`mail`}`` (key, domain) |
| translation, as markup | ``{t:`terms`:`mail` raw}`` |
| widget | `{widget:comments}` or `{widget:document:my-slug}` |
| unencoded value | `{var:page.body raw}` |
| verbatim block | `{verbatim}` ... `{endverbatim}` (not tokenized -- for code samples) |

String literals use **backticks**, so a template never fights HTML quoting.

Full reference: **[docs/language.md](docs/language.md)**.

## Filters

51 built-in filters, applied space-separated. One colon opens a filter's
argument list; commas separate the arguments inside it:

```
{var:price currency:`EUR`,`de_DE`}
{var:body truncate_words:40}
{var:tags join:`, `}
```

One distinction is worth learning early, because the two look
interchangeable and are not:

```
{var:count default:`none`}    <- 0 is falsy, so this prints "none"
{var:count coalesce:`none`}   <- 0 survives; only null/""/false fall back
```

Full list: **[docs/filters.md](docs/filters.md)**.

## Safety

**Every value is HTML-encoded on the way out** -- `{var:}`, `{const:}`, `{t:}`,
and the values interpolated into a translation. The `raw` filter is the single
opt-out, and it is a filter precisely so it shows up at the point of use.
Encoding is not configurable: a switch that turns it off is a switch somebody
eventually turns off.

Encoding covers the HTML text context and quoted attribute values. It does not
make a value safe in a URL, a `<script>` body or an unquoted attribute -- see
[what escaping does not cover](docs/language.md#what-escaping-does-not-cover).

A template cannot reach anything the context does not expose -- no PHP, no
filesystem, no arbitrary calls. The `php.` prefix reaches a fixed allow-list of
41 pure value-transform functions and nothing else; the prefix is reserved, so a
host cannot widen it either.

A missing variable renders as nothing rather than raising. Paths are how a
designer explores a context they did not define, and a half-written path that
throws makes that hostile.

### Upgrading from 1.x

Two breaking changes, both in 2.0.

#### 1. Output is encoded

**1.x did not encode anything**, despite this section having claimed otherwise.
Every value your templates emit is now encoded, so a template relying on a
context value carrying markup renders that markup as text.

The fix is one word per site, and the compiler cannot find them for you --
whether a value is markup is a fact about your data, not about the template:

```
{var:page.body raw}
```

Two things to check while upgrading:

- **Templates that emitted HTML from the context** -- article bodies, rendered
  Markdown, stored snippets. Add `raw`.
- **Catalogue entries containing markup** -- `{t:}` output is encoded too. Add
  `raw` to the tag, which then encodes the interpolated parameters instead.

Values that were already correct need no change. `escape` and `e` still work and
no longer double-encode, so an existing `{var:x escape}` is safe to leave alone.

Hosts that hand templates trusted HTML from PHP should wrap it rather than
teaching every template `raw`:

<!-- doctest:skip -->
```php
use CoolMS\Dtmpl\Runtime\RenderedHtml;

$engine->render($template, ['banner' => new RenderedHtml($trustedHtml)]);
```

#### 2. `{raw}` is now `{verbatim}`

The verbatim block and the `raw` filter used to share a word while doing
unrelated things -- one suppresses *parsing* of a region of source, the other
suppresses *encoding* of a value. The block is renamed; the filter is not.

```
{verbatim}<script>if (a < b) { go({ready: true}); }</script>{endverbatim}
```

`{raw}` and `{endraw}` no longer render. They raise a syntax error naming the
replacement, so nothing degrades into literal text:

<!-- doctest:skip -->
```
`{raw}` was renamed to `{verbatim}` in DTMPL 2.0. Use `{verbatim}` ...
`{endverbatim}`. The `raw` *filter* is unrelated and unchanged.
```

**Every `raw` filter usage stays exactly as it is** -- `{var:x raw}` and
`{t:key raw}` are untouched by this change.

Template source is not only in your theme directory. Document templates,
spreadsheet templates and anything a user authored live as content, and the
engine cannot rewrite those for you -- the error above is what you get, at
render time, for each one you miss.

## License

MIT © Dmitry Popov
