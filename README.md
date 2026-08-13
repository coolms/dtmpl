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
| comparison | `{if:post.status=`published`}`, also `!=` `<` `<=` `>` `>=` |
| loop | `{loop:posts}` ... `{endloop}` or `{loop:posts:post}` |
| include | ``{include:`partials/header`}`` ... `{endinclude}` |
| slot / fill | `{slot:main}` ... `{endslot}` / `{fill:main}` ... `{endfill}` |
| constant | `{const:SITE_NAME}` |
| translation | ``{t:`Hello`}`` or ``{t:`Hello`:`mail`}`` (key, domain) |
| widget | `{widget:comments}` or `{widget:document:my-slug}` |
| raw block | `{raw}` ... `{endraw}` |

String literals use **backticks**, so a template never fights HTML quoting.

Full reference: **[docs/language.md](docs/language.md)**.

## Filters

51 built-in filters, applied space-separated and taking arguments after a colon:

```
{var:price currency:`EUR`}
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

Output is escaped by default. `{raw}` and the `raw` filter are the explicit
opt-outs, and the only way to emit unescaped HTML. A template cannot reach
anything the context does not expose, and in strict mode an unknown variable is
an error rather than a silent empty string.

## License

MIT © Dmitry Popov
