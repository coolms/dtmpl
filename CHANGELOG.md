# Changelog

All notable changes to `coolms/dtmpl` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

⚠️ Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## Unreleased

### Added: `{comment}` ... `{endcomment}` and `{comment:...}`

A template had no comment syntax. `<!-- ... -->` is ordinary text to this
engine and is emitted like any other text, so there was nowhere to leave a note
the visitor does not read -- and a comment is usually exactly where an author
writes down what they did not want visible.

Removed by the lexer, so the contents reach neither `OutputMode::Html` nor
`OutputMode::Text`. `OutputMode::Text` turns *encoding* off, not the lexer.

The block form does not parse its body, which is what makes it usable for
commenting out a chunk of template while debugging. Comments do not nest --
the first `{endcomment}` terminates -- and an inline comment cannot contain
`}`, both for the same reason: the body is never read, so there is nothing
tracking braces. Only the exact forms open one, so `{commentary}`,
`{comment foo}` and `{comments}` remain ordinary text.

Additive: no existing template changes meaning unless it contained the literal
text `{comment}` or `{comment:`, which previously rendered as itself.

Also: contributor documentation (`CONTRIBUTING.md`), describing the Tuesday
release train, the deprecation window, and how this package's version number
relates to the CoolMS platform packages.

## 2.0.0 - 2026-08-26

Two breaking changes. Both affect templates, not the PHP API.

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

### `{raw}` renamed to `{verbatim}`

The verbatim block and the `raw` filter shared a word while doing unrelated
things. The block suppresses *parsing* of a region of source; the filter
suppresses *encoding* of a value. The block is renamed; the filter is unchanged.

**Migration:** `{raw}` to `{verbatim}`, `{endraw}` to `{endverbatim}`. Leave
every `raw` filter usage alone.

`{raw}` and `{endraw}` raise `RemovedKeywordException` naming the replacement,
so no template silently degrades to literal text. Template source stored outside
the repository -- document templates, spreadsheet templates, user-authored
content -- needs the same rename and fails at render time until it gets it.

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

## 1.0.0 - 2026-08-14

First release. The DTMPL template engine: a designer-facing template language
with a lexer, parser, AST and runtime, plus a widget seam for host-supplied
components.
