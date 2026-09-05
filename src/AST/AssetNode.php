<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * A `{css}` ... `{endcss}` or `{js}` ... `{endjs}` block.
 *
 * ⚠️ THIS NODE IS NEVER EXECUTED. It is not in {@see TemplateNode::$children};
 * it lives in {@see TemplateNode::$assets}, which is metadata of the compiled
 * template rather than part of its output. A partial that declares a style
 * block emits nothing extra where the block was written -- the host gathers
 * the declarations from the whole include tree before rendering starts and
 * writes them once, in the document head.
 *
 * That is the whole reason the construct exists. CSS written inline in a
 * partial renders once per instance: eight feature cards means eight copies of
 * the same rules in the body, in an order the author never chose. Moving the
 * CSS to a separate stylesheet fixes the duplication and breaks the pairing --
 * the file and the markup are edited apart, published apart, and drift. This
 * keeps them in one file and still emits them once.
 *
 * The body is a literal: the interior is never tokenized (CSS is made of
 * braces, so it could not be), which is what makes the gather a COMPILE-time
 * operation -- the text is fixed in the cached AST and cannot depend on
 * render data.
 */
final readonly class AssetNode extends Node
{
    public function __construct(
        public AssetKind $kind,
        public string $source,
        int $line = 0,
        int $column = 0,
    ) {
        parent::__construct($line, $column);
    }

    /**
     * Dedupe identity: same kind, same bytes, one copy.
     *
     * Keyed on the CONTENT rather than on the declaring template path,
     * because the same partial included twice and two partials shipping an
     * identical rule set are the same situation from the document's side.
     */
    public function key(): string
    {
        return $this->kind->value . ':' . hash('xxh128', $this->source);
    }
}
