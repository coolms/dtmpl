# The DTMPL language

Every tag has the same shape:

```
{keyword:argument}
```

Blocks close with `{endkeyword}`. There is no `{/keyword}` form, and no
`{% %}` / `{{ }}` distinction -- one delimiter, one shape.

String literals are written in **backticks**:

```
{if:post.status=`published`}published{endif}
```

That is deliberate: a template lives inside HTML, where both `"` and `'` are
already spoken for. Backticks never collide with an attribute.

A `{` that is not followed by a keyword is literal text, so JSON, CSS and set
notation survive untouched. `{{` and `}}` are the escapes if you need a literal
brace next to one.

---

## Output

### `{var:path}`

```
{var:title}
{var:page.author.name}
```

Paths walk objects and arrays alike. A path that resolves to nothing renders as
nothing -- there is no error for a missing variable.

**Output is HTML-encoded.** See [Escaping](#escaping) for the one way out.

Filters follow the path, separated by spaces. **One colon opens a filter's
argument list; commas separate the arguments inside it:**

```
{var:title uppercase}
{var:body truncate:120}
{var:body truncate:120,`...`}
{var:price currency:`EUR`,`de_DE`}
{var:title lowercase capitalize}
```

A second colon is a syntax error, not a second argument. See
[filters.md](filters.md) for the full set.

### `{def:name=source}`

Assigns without printing. Filters apply the same way:

```
{def:heading=page.title uppercase}
{var:heading}
```

A `{def:}` alone on a line takes the whole line with it -- no blank line is left
behind.

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

<!-- doctest:skip -->
```
{if:a=b}      {if:a!=b}
{if:a<b}      {if:a<=b}
{if:a>b}      {if:a>=b}
```

Equality is a **single `=`**. There is no `==` -- writing one is an error, not a
synonym.

Either side may be a path or a backtick literal:

```
{if:post.status=`published`}yes{endif}
{if:cart.count>0}yes{endif}
```

`=` and `!=` compare as strings. The four ordered operators compare numerically
when both sides are numeric, and as strings otherwise -- which is what makes
ISO-style dates sort correctly.

With no operator, the value is evaluated for truthiness. `0`, `"0"`, `""`,
`null`, `false` and an empty array are all falsy.

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

### Iteration state

Every iteration exposes `loop`:

| | |
|---|---|
| `loop.index` | 0-based position |
| `loop.index1` | 1-based position |
| `loop.key` | the array key |
| `loop.first` / `loop.last` | edges |
| `loop.length` | total number of elements |
| `loop.odd` / `loop.even` | parity of `loop.index` |

```
{loop:posts}{var:loop.index1}. {var:item.title}
{endloop}
```

### Modifiers

Three, and only three. Anything else is an error naming the nearest real one.

| | |
|---|---|
| `odd` | only odd iterations (`loop.index` 1, 3, 5 ...) |
| `even` | only even iterations (`loop.index` 0, 2, 4 ...) |
| ``split=`, ` `` | a separator emitted *between* iterations |

```
{loop:tags split=`, `}{var:item}{endloop}
{loop:rows even}{var:item}{endloop}
```

### Associative arrays

When the keys are not `0..n-1` in order, each element arrives wrapped so both
halves are reachable:

```
{loop:attrs} {var:item.key}="{var:item.value}"{endloop}
```

For a plain list, `item` **is** the element and `item.key` means whatever the
element's own `key` field is.

---

## Composition

### `{include:`path`}`

```
{include:`partials/header`}
```

**`{endinclude}` is only needed when the include carries fills.** A plain
include closes itself.

The path resolves through the loader chain, so what a given name resolves to
depends on which loaders are installed and their priority -- see
[loaders.md](loaders.md).

### Slots and fills

A layout declares a slot; the page that includes the layout fills it.

```
<!-- layout.html.dtmpl -->
{slot:main}
    Nothing here yet.
{endslot}
```

A `{fill:}` lives **inside** the `{include:}` whose layout it is filling, which
is what the `{endinclude}` is for:

```
{include:`layout.html.dtmpl`}
    {fill:main}
        <article>{var:page.body raw}</article>
    {endfill}
{endinclude}
```

An unfilled slot renders its own body as the default. A slot with no body can
also take an inline default, and needs no `{endslot}`:

```
{slot:subtitle default=`Untitled`}
```

Fill bodies are rendered in the **caller's** context, so they can reach the
page's own variables rather than the layout's.

---

## Constants

```
{const:SITE_NAME}
```

Constants come from the host through `ConstantProviderInterface`, not from the
template context. They are for values that are the same on every render, and
they are encoded like any other value.

---

## Translation

```
{t:`Hello`}
{t:`Hello`:`mail`}
{t:`Hello %name%`:`mail` name=user.firstName}
```

The first argument is the message key, the second the domain. The key is the
**full source string**, not an abbreviation of it. A bare identifier in a
parameter is a context path; a backtick literal is a literal.

A `locale` variable in the render data selects the catalogue. Without one the
translator's ambient locale is used -- correct for a web request, wrong for
anything rendered out of band such as an email, so pass it explicitly there.

Translated output is encoded. A catalogue that deliberately contains markup opts
out with the same word as everything else:

```
{t:`terms.intro` raw}
```

In `raw` mode the *parameters* are encoded instead, so trusting the sentence
never means trusting the values substituted into it.

---

## Widgets

```
{widget:comments}
{widget:document:my-template-slug}
```

A widget is a component the host registered. The engine resolves the name, calls
the host's renderer, and inserts the result. Template authors get application
functionality without any PHP. See [widgets.md](widgets.md).

A widget's output is markup by contract and is not encoded. A widget that has
nothing to show renders nothing, and takes its line with it if it was alone on
one.

---

## Verbatim blocks

```
{verbatim}
    <script>if (a < b) { go({ready: true}); }</script>
{endverbatim}
```

`{verbatim}` ... `{endverbatim}` is a **lexer** instruction: nothing inside it
is tokenized, so braces, keyword lookalikes and DTMPL code samples survive
intact. It is for content that must not be *parsed*.

It is not an encoding control. Nothing inside a verbatim block is a value, so
there is nothing there to encode -- see [Escaping](#escaping) for that, and
[the two of them together](#verbatim-is-not-raw) for why they are easy to
confuse.

---

## Comments

```
{comment}
    Anything at all, including half-written tags and unbalanced braces.
{endcomment}

{comment:a short note}
```

A comment is removed by the lexer. Nothing is emitted, so the contents cannot
reach the output in either mode -- `OutputMode::Text` turns *encoding* off, not
the lexer, and a comment never becomes a value for an encoder to see.

This is the only way to leave a note in a template that a visitor cannot read.
An HTML comment is not one: `<!-- ... -->` is ordinary text to DTMPL and is
emitted like any other text, which leaks in exactly the wrong direction, because
a comment is usually where an author writes down what they did not want visible.

The block form does not parse its body, which is what makes it useful for
commenting out a chunk of template while debugging -- and that chunk is usually
broken. Nothing inside needs to be valid.

Two limits, both deliberate:

- **Comments do not nest**, and the FIRST `{endcomment}` terminates. Nesting
  would require the scanner to parse the body it is deliberately not parsing --
  the same reason `{verbatim}` does not nest.
- **An inline comment cannot contain `}`.** It ends at the first one, because
  there is no brace tracking in a body that is never read. Use the block form
  when the note needs a brace.

Only the exact forms open a comment: `{comment}` and `{comment:`. `{commentary}`,
`{comment foo}` and `{comments}` are ordinary text, so prose and code containing
the word are unaffected -- and a comment marker shown *inside* a `{verbatim}`
block stays visible, which is how this section documents itself.

An unterminated comment is a hard error naming the missing terminator, not a
silently swallowed rest-of-file.

---

## Whitespace

A tag that sits alone on a line and renders nothing takes that line with it,
indentation included -- otherwise a layout full of structural tags would print a
blank line for every one of them.

That applies to `{def:}`, which never renders anything, and to the block and
composition tags when they come back empty: `{if:}` / `{unless:}` with no
matching branch, an empty `{loop:}`, an unfilled `{slot:}`, an `{include:}`
whose partial rendered nothing, a `{fill:}` declaration, and a `{widget:}` whose
renderer returned `null`.

It does **not** apply to `{var:}`, `{const:}` or `{t:}`. Those are values, and a
value that happens to be empty this render is not a structural line -- collapsing
it would make the output depend on the data in a way the author did not write.
An optional field on its own line leaves its blank line behind; put it inside an
`{if:}` if you want the line to disappear with it.

---

## Escaping

Every value DTMPL emits is HTML-encoded on the way out: `{var:}`, `{const:}`,
and `{t:}`, including the values interpolated into a translation. Encoding uses
`ENT_QUOTES`, so a value is safe inside an attribute as well as in element text.

Rendered *fragments* are not re-encoded -- the output of `{include}`, `{slot}`,
`{fill}` and a widget partial is already template output, and encoding it would
double-encode the page. Encoding happens where a value crosses into markup.

The one opt-out is the `raw` filter:

```
{var:page.body raw}
```

Once a value is marked raw it stays raw through the rest of its filter chain, so
`{var:body escape php.nl2br}` encodes once and keeps the `<br />` that `nl2br`
adds.

A host can also put a `CoolMS\Dtmpl\Runtime\RenderedHtml` in the context. It
carries the same marker, which is how an application hands a template trusted
markup without the template having to remember `raw`:

<!-- doctest:skip -->
```php
$engine->render($template, ['banner' => new RenderedHtml($trustedHtml)]);
```

### Verbatim is not raw

The block and the filter are different features that happen to sit near each
other, and until 2.0 they shared a name. They answer different questions:

| | `{verbatim}` ... `{endverbatim}` | the `raw` filter |
|---|---|---|
| acts at | lex time | render time |
| operates on | a region of template **source** | a single **value** |
| what it suppresses | parsing | encoding |
| gets it wrong how | your tags render as their own source | untrusted markup reaches the page |

A single line from the shipped theme uses both, which is the clearest way to
see that they are unrelated:

<!-- doctest:skip -->
```
var experiments = {endverbatim}{var:experiments.configJson raw}{verbatim};
```

The surrounding `<script>` body is verbatim, so its braces are not tags. The
JSON is a value, so it needs `raw` to reach the page as JSON rather than as
`&quot;`-encoded text. Closing the block, emitting the value and reopening the
block is exactly what that line does.

Reaching for the wrong one fails in opposite directions. `{verbatim}{var:body}{endverbatim}`
emits the literal characters `{var:body}` -- the value is never looked up.
`{var:body raw}` inside a `<script>` does not stop the surrounding braces being
parsed as tags.

Any other object is a value, even one implementing `Stringable`. That default is
deliberate: entities and value objects are exactly the things most likely to
carry user input, and inheriting a `__toString()` should never be enough to
bypass encoding.

**There is no auto-escaping switch.** Encoding is not something you turn off per
template or per value, because a setting that turns it off is a setting somebody
eventually turns off.

### What escaping does not cover

Encoding protects the **HTML text context** and **quoted attribute values**. It
does not make a value safe in every position a template can put it, and the
three gaps below are not things a filter can close on its own.

**A URL is still a URL.** `javascript:` contains no character encoding touches,
so it passes through intact:

```
<a href="{var:u}">link</a>
```

with `u` = `javascript:alert(1)` renders that scheme unchanged. Validate the
scheme before the value reaches the template -- allow `http`, `https`, `mailto`
and relative paths, reject the rest. **No shipped filter does this**, and
`url_encode` is not a substitute: it percent-encodes the whole string, so a real
URL comes back as `https%3A%2F%2F…` and stops working. `url_encode` is for a
single query-string *component*:

```
<a href="/search?q={var:q url_encode}">go</a>
```

**A `<script>` body needs JavaScript rules, not HTML entities.** Encoding a
value into a script body produces `&quot;`, which a script body never decodes --
the result is broken JavaScript, not safe JavaScript. Use `json raw`:

```
{verbatim}<script>var data = {endverbatim}{var:payload json raw}{verbatim};</script>{endverbatim}
```

`json` escapes `/`, so a value containing `</script>` becomes `<\/script>` and
cannot close the element. It does not escape `<` or `>` on their own, so do not
rely on it to neutralise `<!--` inside a script.

**An unquoted attribute has no delimiters to protect.** Encoding a value for
`class={var:c}` cannot help, because the attribute ends at the first space --
`a onmouseover=alert(1)` becomes two attributes. Always quote.

### When the output is not HTML

The engine is a string templater underneath, and hosts point it at things that
are not web pages -- a filename pattern, a spreadsheet cell, a `<w:t>` run inside
OOXML, a value spliced into document JSON. HTML-encoding those does not make them
safer, it corrupts them: `O'Hara` becomes `O&#039;Hara` in a Word document, and a
value the OOXML writer was about to XML-escape gets encoded twice.

That is a statement about the output format, so it is made once, where the engine
is built:

<!-- doctest:skip -->
```php
use CoolMS\Dtmpl\Runtime\OutputMode;

$filenameEngine = $engine->withOutputMode(OutputMode::Text);
```

`OutputMode::Text` emits values verbatim and leaves encoding to the caller, which
owns the target format. It is not a safe mode and an unsafe mode -- each is
correct for its format and wrong for the other. Anything that reaches a browser
stays `OutputMode::Html`, which is the default.
