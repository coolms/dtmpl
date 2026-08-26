# Changelog

## 2.0.0

### Output is HTML-encoded

`{var:}`, `{const:}` and `{t:}` encode what they emit, including the values
interpolated into a translation. 1.x encoded nothing.

**Migration:** add the `raw` filter to any value that is deliberately markup --
`{var:page.body raw}`. For a catalogue entry containing markup, `{t:key raw}`
emits the sentence as markup and encodes its parameters instead. A host can put
a `CoolMS\Dtmpl\Runtime\RenderedHtml` in the context to mark a value as markup
without every template remembering `raw`.

`OutputMode::Text` turns encoding off for a render that is not producing HTML --
a filename pattern, a spreadsheet cell, an OOXML run.

### Also in 2.0

- Filter arguments are comma-separated after a single colon. A second colon was
  silently dropping every argument after it; it is now a syntax error naming the
  corrected form.
- `==`, `!==` and `> =` raise messages naming the real operator.
- An unrecognised `{loop:}` modifier is an error naming the nearest real one,
  instead of being ignored.
- Unknown filters raise `UnknownFilterException` (under `TemplateException`)
  rather than a bare SPL `InvalidArgumentException`.
- The `php.` prefix is reserved: registering a filter under it is refused.
- Negative number literals parse -- `{def:offset=-5}`, `{var:n add:-5}`.
- `{var:x default:` ... `}` reaches the `default` filter; `default=` remains the
  path fallback.
- A widget rendering nothing collapses its line, like every other construct that
  can render empty.
- The compiled-AST cache key carries a schema version, so an engine upgrade
  cannot read back a stale object graph.

## 1.0.0

First release.
