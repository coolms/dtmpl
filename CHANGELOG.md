# Changelog

All notable changes to `coolms/dtmpl` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

!! Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## 2.1.0 - 2026-09-10

Additive throughout: nothing was removed and no public signature changed, so
`^2.0` keeps resolving and no consumer manifest moves. The number goes up for
new template syntax, a new loader and a new filter.

### Added: an `href` filter, because `escape` answers a different question

A block field holding `javascript:alert(1)` reached the page intact through
`{var:block.ctaUrl escape}`. `escape` answers "can this break out of the
attribute"; it reads as though it answered "is this a URL the page should
navigate to". It does not, and the gap is silent, because encoded output looks
exactly like safe output.

`href` answers the second question. Control characters are stripped ANYWHERE
rather than trimmed, since a browser ignores them inside a scheme and an
interior tab or NUL makes a scheme live while a prefix check on the raw string
sees something harmless. Backslashes fold to slashes before the checks, as every
browser's URL parser folds them. Authority-relative `//host` is rejected: an
off-site link wearing a first-party spelling. A colon counts as a scheme only
when it precedes the first slash, so `docs/a:b` stays a path this filter has no
business having an opinion about.

Use it on any value that becomes an `href` or `src`.

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

### Added: `VfsTemplateLoader`

It implements this package's `PrioritizedLoaderInterface`, takes this package's
`TemplateStorageInterface` and throws this package's `TemplateException`, naming
no type from anywhere else -- so it was this engine's adapter living in the
contracts package, and the sole reason that package imported a template engine.
It lands beside `FilesystemTemplateLoader`, which is the same shape over
different storage. Nothing published ever shipped it from its old home, so
nothing has to be migrated.

### Changed: a fill whose partial declares no such slot is now reported

**This answers the open question that stood in this section, and answers it
differently.** That note recommended a thrown exception in debug; the
implementation emits a notice.

`{fill:name}` against a partial with no `{slot:name}` was inert and silent: the
body rendered, went into the slot map and was never read, so the page came back
missing whatever the fill was for with nothing saying which end was wrong. It
cost a session on a real site -- a page filled `head`, the layout declared
`styles`, and the token set evaporated three layers from the typo.

A notice rather than an exception, because an unread fill is harmless in itself
and a template that renders is worth more than a template that is right. The
slot walk is reflective: a slot inside an `{if}` branch or a `{loop}` body still
counts as declared, since reporting it missing would send a reader hunting a typo
that is not there.

### Changed: an asset block that never reached the document head is now reported

The gather is a static walk of `{include:}` from the page root, so a template
reached any other way -- a widget names its partial at render time -- is
invisible to it, and a `{css}` block inside one was silently dropped. A notice
again, and deliberately: a menu widget catches `Throwable` and degrades to an
empty menu on every public page, so throwing would turn an unstyled menu into no
menu. The problem was the silence, not the limitation.

### Changed: a widget lookup builds one renderer, not all of them

`WidgetRegistry` could only learn a renderer's key by constructing it, so the
first widget lookup on a page constructed every registered renderer and whatever
each constructor pulls in. `registerKeyed(key, factory)` takes the key from the
caller, so `has()`, `get()` and `isExactMatch()` answer from the key alone and
build only what they matched. `registerLazy()` stays for renderers whose key is
not known at compile time; behaviour is unchanged and only the count moves.

### Also

- `CONTRIBUTING.md`, describing the release train, the deprecation window and how
  this package's version relates to the platform packages.
- The package declares its own documentation in the manifest.
- A test asserts that every imported sibling class exists in the installed tree,
  reporting every file that imports an absent one rather than stopping at the
  first, and another asserts the two keyword lists agree.
- Comments, docblocks and changelogs are ascii and no longer carry identifiers a
  reader outside this organisation cannot resolve.
- Development-only files are export-ignored, so `composer require` no longer
  downloads them.

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
