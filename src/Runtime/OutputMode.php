<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

/**
 * What a render is producing, and therefore how a value has to be
 * encoded on its way into it.
 *
 * DTMPL is an HTML template language by default and by intent, so
 * {@see Html} is the default and a caller has to say otherwise. But the
 * engine is a string templater underneath, and hosts legitimately point
 * it at things that are not HTML at all -- a filename pattern, a cell in
 * a spreadsheet, a `<w:t>` run inside OOXML, a value spliced into
 * document JSON. HTML-encoding those is not merely unnecessary, it is
 * wrong: it puts `O&#039;Hara` in a Word document and `&amp;lt;b&amp;gt;`
 * in a `.docx` that the writer was about to XML-escape itself.
 *
 * This is deliberately NOT a "turn escaping off" switch. It is a
 * statement about the OUTPUT FORMAT, chosen once where the engine is
 * constructed rather than per value, and the two modes are not a safe
 * one and an unsafe one -- each is correct for its format and wrong for
 * the other. An HTML page rendered in {@see Text} mode would be
 * injectable; a filename rendered in {@see Html} mode is corrupt.
 */
enum OutputMode
{
    /**
     * The output is HTML. Every emitted value is HTML-encoded unless it
     * carries {@see HtmlSafe}. The default.
     */
    case Html;

    /**
     * The output is not HTML -- plain text, a filename, a spreadsheet
     * cell, a value about to be XML- or JSON-encoded by the caller for
     * its own format. Values are emitted verbatim.
     *
     * The caller owns encoding for its format. Choosing this mode for
     * something that ends up in a browser reintroduces exactly the hole
     * {@see Html} closes.
     */
    case Text;
}
