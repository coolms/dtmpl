<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * What an {@see AssetNode} carries -- a stylesheet fragment or a script.
 *
 * A CLOSED set of two, and it stays closed. The point of the construct is
 * that a partial declares the CSS and JS it needs beside the markup that
 * needs them, so the two cannot drift apart; a third kind would be a
 * general "put arbitrary text somewhere else in the document" mechanism,
 * which is a different and much larger promise.
 */
enum AssetKind: string
{
    case Css = 'css';
    case Js = 'js';

    /**
     * The closing tag that would end the host element early.
     *
     * The gathered body is written into `<style>` / `<script>` verbatim --
     * it is template source, which is code, so it is trusted the same way
     * the surrounding markup is. What it must not contain is its own
     * terminator: `</script>` inside a script body ends the element at that
     * point and turns the remainder into document text. The parser refuses
     * it rather than escaping it, because there is no escape that works in
     * both elements and a template that needs the literal characters wants
     * a real asset file.
     */
    public function terminator(): string
    {
        return match ($this) {
            self::Css => '</style',
            self::Js => '</script',
        };
    }

    public function element(): string
    {
        return match ($this) {
            self::Css => 'style',
            self::Js => 'script',
        };
    }
}
