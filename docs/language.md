# The DTMPL language

Every tag has the same shape:

```
{keyword:argument}
```

Blocks close with `{endkeyword}`. There is no `{/keyword}` form, and no
`{% %}` / `{{ }}` distinction -- one delimiter, one shape.

String literals are written in **backticks**:

```
{if:post.status=`published`}
```

That is deliberate: a template lives inside HTML, where both `"` and `'` are
already spoken for. Backticks never collide with an attribute.

---

## Output

### `{var:path}`

```
{var:title}
{var:page.author.name}
```

Paths walk objects and arrays alike. Output is HTML-escaped.

Filters follow the path, separated by spaces, with arguments after a colon:

```
{var:title uppercase}
{var:body truncate:120}
{var:price currency:`EUR`}
{var:title lowercase capitalize}
```

See [filters.md](filters.md) for the full set.

### `{def:name=source}`

Assigns without printing. Filters apply the same way:

```
{def:heading=page.title uppercase}
{var:heading}
```

---

## Conditionals

```
{if:page.isPublished}
    published
{else}
    draft
{endif}
```

`{unless:}` is the negated form and takes no `{else}`:

```
{unless:user.isAnonymous}
    Welcome back.
{endunless}
```

### Comparisons

```
{if:a=b}      {if:a!=b}
{if:a<b}      {if:a<=b}
{if:a>b}      {if:a>=b}
```

Either side may be a path or a backtick literal:

```
{if:post.status=`published`}
{if:cart.count>0}
```

With no operator, the value is evaluated for truthiness.

---

## Loops

```
{loop:posts}
    {var:item.title}
{endloop}
```

The current element is `item` by default. Name it explicitly when loops nest,
or when `item` would be ambiguous:

```
{loop:posts:post}
    {loop:post.tags:tag}
        {var:tag.name}
    {endloop}
{endloop}
```

---

## Composition

### `{include:`path`}`

```
{include:`partials/header`}{endinclude}
```

The path resolves through the loader chain, so what a given name resolves to
depends on which loaders are installed and their priority -- see
[loaders.md](loaders.md).

### Slots and fills

A layout declares a slot; a page fills it.

```
<!-- layout -->
{slot:main}
    Nothing here yet.
{endslot}
```

```
<!-- page -->
{fill:main}
    <article>{var:page.body}</article>
{endfill}
```

An unfilled slot renders its own body as the default.

---

## Constants

```
{const:SITE_NAME}
```

Constants come from the host through `ConstantProviderInterface`, not from the
template context. They are for values that are the same on every render.

---

## Translation

```
{t:`Hello`}
{t:`Hello`:`mail`}
```

The first argument is the message key, the second the domain. The key is the
**full source string**, not an abbreviation of it.

---

## Widgets

```
{widget:comments}
{widget:document:my-template-slug}
```

A widget is a component the host registered. The engine resolves the name, calls
the host's renderer, and inserts the result. Template authors get application
functionality without any PHP. See [widgets.md](widgets.md).

---

## Raw output

```
{raw}
    <script>already trusted</script>
{endraw}
```

and as a filter, for a single value:

```
{var:body raw}
```

These are the only ways to emit unescaped output. Everything else is escaped,
including widget parameters and translated strings.

---

## Strict mode

In strict mode an unknown variable raises rather than rendering empty. Turn it
on while authoring: a typo in a path is otherwise indistinguishable from a value
that happens to be absent, and both render as nothing.
