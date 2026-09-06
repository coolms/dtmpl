# Changelog

All notable changes to `coolms/dtmpl` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

!! Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## Unreleased

### Open question: should a fill with no matching slot be an error in debug?

Not a change -- a decision to take. Recorded here because it was found the
expensive way and will be found that way again otherwise.

`{include:}` renders every fill body into a map and passes it to the partial;
`{slot:}` looks its own name up. A fill whose name nothing looks up is never
read, and nothing reports it. On a real site this rendered a page in the wrong
palette for two commits with no error, no warning and no log line, because an
intermediate layout did not forward the slot the page was filling.

Filling a slot that does not exist is almost always a typo or a broken layout
chain rather than an intention. Nobody writes a fill they mean to be discarded.

**Both detection points are feasible, and they catch different things.**

*At render time* -- `executeInclude` already builds the fill map, and
`executeSlot` is the only thing that reads it. Marking a name as consumed and
reporting the leftovers when the partial finishes is a handful of lines, has
**no false positives at all**, and fires exactly when the author loads the page
they broke. It only sees paths that actually render.

*Statically* -- slot names are literals in the AST, so "which names does this
partial accept?" is answerable by walking it and following its includes, which
is the walk `AssetGatherer` already performs. It covers branches a given render
does not take. The subtlety is that a forwarded slot (`{fill:x}{slot:x}{endfill}`)
means the accepted set includes names appearing inside fill bodies, not only
top-level slots -- miss that and the check reports a false positive on the
correct idiom.

**Recommendation: render time, debug only, as a thrown exception rather than a
log line.** A silent failure is not fixed by a diagnostic nobody reads, and a
template that fills nothing is not a template anybody wanted to ship. The
static version is the better long-term answer and the riskier first move.

!! Deliberately NOT implemented here. This package is published, so a new
failure mode is a release decision rather than a drive-by.

### Added: `{css}` ... `{endcss}` and `{js}` ... `{endjs}`

A partial can now declare the stylesheet and script it needs in the same file
as the markup that needs them. The interior is scanned like `{verbatim}` --
never tokenized, because CSS is made of braces -- but it renders nothing where
it is written. The declarations are lifted into the compiled AST as metadata;
`DtmplEngine::gatherAssets()` walks a template and everything it includes,
deduplicates, and hands the host one `<style>` and one `<script>` to put in the
document head before the render starts.

The alternative it replaces was a choice between two defects: a `<style>` in
the partial ships one copy per instance, in the body, in an order nobody chose;
a separate stylesheet fixes that and breaks the pairing, so the rules and the
markup are then edited apart and drift.

Rules, all enforced at compile time: top level only (a conditional asset block
would ship regardless of its branch, so it is refused rather than quietly
meaning something else); the body cannot contain its own closing tag; empty
blocks contribute nothing. The gather is deliberately STATIC -- every
`{include:}` is followed, including ones a given render will not reach -- because
the head is written before any branch is taken.

!! `AST_VERSION` is bumped to `3`: `TemplateNode` gained a property.

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
