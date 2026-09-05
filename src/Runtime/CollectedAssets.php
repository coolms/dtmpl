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
     */
    private function __construct(
        public array $css = [],
        public array $js = [],
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

        return new self($css, $js);
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
