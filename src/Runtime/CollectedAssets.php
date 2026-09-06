<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use CoolMS\Dtmpl\AST\AssetKind;

/**
 * The `{css}` / `{js}` declarations gathered from one template and
 * everything it includes, deduplicated, in document order.
 *
 * Immutable and render-independent: two renders of the same page with
 * different data produce the same instance content, which is what makes it
 * safe for the host to build once and cache.
 */
final readonly class CollectedAssets
{
    /**
     * @param list<string> $css
     * @param list<string> $js
     * @param list<string> $keys
     */
    private function __construct(
        public array $css = [],
        public array $js = [],
        /**
         * The {@see \CoolMS\Dtmpl\AST\AssetNode::key()} of every declaration
         * that made it into this set.
         *
         * ⚠️ Kept because the interesting question is about what is absent.
         * The two source lists answer "what goes in the head"; only the keys
         * answer "was this particular declaration reached", which is what lets
         * a render notice that its own `{css}` is not in the document it is
         * being rendered into. Without them the check would have to compare
         * fragment text, and a partial declaring the same rule twice would
         * read as gathered when it was not.
         *
         * @var list<string>
         */
        public array $keys = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param array<string, array{AssetKind, string}> $fragments keyed by AssetNode::key()
     */
    public static function fromFragments(array $fragments): self
    {
        $css = [];
        $js = [];
        foreach ($fragments as [$kind, $source]) {
            match ($kind) {
                AssetKind::Css => $css[] = $source,
                AssetKind::Js => $js[] = $source,
            };
        }

        return new self($css, $js, array_keys($fragments));
    }

    /**
     * Whether a declaration with this key was gathered into the document.
     */
    public function has(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    public function isEmpty(): bool
    {
        return [] === $this->css && [] === $this->js;
    }

    /**
     * The gathered CSS as one `<style>` element, or `''` when there is none.
     *
     * ⚠️ Returns the EMPTY STRING rather than an empty element. A page with no
     * block styles should carry no evidence that the mechanism exists; an
     * always-present `<style></style>` is the kind of artifact that later gets
     * "fixed" by adding a condition in the layout, which puts the decision in
     * the wrong place.
     */
    public function styleTag(): string
    {
        return $this->tag(AssetKind::Css, $this->css);
    }

    public function scriptTag(): string
    {
        return $this->tag(AssetKind::Js, $this->js);
    }

    /**
     * @param list<string> $fragments
     */
    private function tag(AssetKind $kind, array $fragments): string
    {
        if ([] === $fragments) {
            return '';
        }

        $element = $kind->element();

        return sprintf("<%s>\n%s\n</%s>", $element, implode("\n", $fragments), $element);
    }
}
