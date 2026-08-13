<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Optimizer;

use CoolMS\Dtmpl\AST\ConditionalNode;
use CoolMS\Dtmpl\AST\DefineNode;
use CoolMS\Dtmpl\AST\FillNode;
use CoolMS\Dtmpl\AST\IncludeNode;
use CoolMS\Dtmpl\AST\LoopNode;
use CoolMS\Dtmpl\AST\Node;
use CoolMS\Dtmpl\AST\SlotNode;
use CoolMS\Dtmpl\AST\TemplateNode;
use CoolMS\Dtmpl\AST\TextNode;

/**
 * AST pass that collapses orphan blank lines around silent tags and
 * around block-tag boundaries (open, close, and `{else}` separators).
 *
 * Silent tags (currently only DefineNode) and block-nodes that sit
 * alone on a line have their surrounding newline + indentation
 * collapsed so the rendered output reads as the author intended,
 * without artifacts left by tags that consumed their own line.
 *
 * Pure and idempotent: trim(trim(x)) === trim(x). Returns the input
 * node unchanged when no cleanup applies.
 */
final class WhitespaceTrimmer
{
    public function trim(TemplateNode $root): TemplateNode
    {
        $trimmed = $this->trimChildren($root->children);

        return $trimmed === $root->children
            ? $root
            : new TemplateNode($trimmed, $root->line, $root->column);
    }

    /**
     * Three-step pass over a sibling list:
     *   1. recurse into each block-node's body
     *   2. silent-tag border between adjacent siblings
     *   3. block-border (open + close, with virtual EOF on close)
     *      between adjacent siblings where one side is a block
     *
     * Edges of body[0] / body[last] are handled by the OUTER parent's
     * pass via open / close borders -- this method only handles
     * borders BETWEEN children.
     *
     * @param Node[] $children
     *
     * @return Node[]
     */
    private function trimChildren(array $children): array
    {
        $result = [];
        $changed = false;

        foreach ($children as $original) {
            $node = $this->recurseInto($original);
            if ($node !== $original) {
                $changed = true;
            }
            $result[] = $node;
        }

        $count = count($result);

        for ($i = 0; $i < $count; ++$i) {
            if (!$this->isSilent($result[$i])) {
                continue;
            }
            $left = $result[$i - 1] ?? null;
            $right = $result[$i + 1] ?? null;
            [$newLeft, $newRight] = $this->trimBorder($left, $right, false);
            if ($newLeft !== $left && $i > 0 && $newLeft instanceof Node) {
                $result[$i - 1] = $newLeft;
                $changed = true;
            }
            if ($newRight !== $right && $i + 1 < $count && $newRight instanceof Node) {
                $result[$i + 1] = $newRight;
                $changed = true;
            }
        }

        for ($i = 0; $i < $count; ++$i) {
            $node = $result[$i];
            if (!$this->isBlockWithBody($node)) {
                continue;
            }
            $prev = $result[$i - 1] ?? null;
            $next = $result[$i + 1] ?? null;
            [$newPrev, $newBlock, $newNext] = $this->applyBlockBorders($node, $prev, $next);
            if ($newBlock !== $node) {
                $result[$i] = $newBlock;
                $changed = true;
            }
            if ($newPrev !== $prev && $i > 0 && $newPrev instanceof Node) {
                $result[$i - 1] = $newPrev;
                $changed = true;
            }
            if ($newNext !== $next && $i + 1 < $count && $newNext instanceof Node) {
                $result[$i + 1] = $newNext;
                $changed = true;
            }
        }

        return $changed ? $result : $children;
    }

    /**
     * Silent predicate -- currently true only for DefineNode.
     */
    private function isSilent(Node $node): bool
    {
        return $node instanceof DefineNode;
    }

    /**
     * Block-with-body predicate -- nodes whose body endpoints meet the
     * outer parent's prev / next siblings and need open / close
     * borders applied at the parent level, plus composition nodes
     * (IncludeNode and empty-default SlotNode) that participate in
     * the on-own-line marking pass.
     */
    private function isBlockWithBody(Node $node): bool
    {
        return $node instanceof ConditionalNode
            || $node instanceof LoopNode
            || $node instanceof SlotNode
            || $node instanceof IncludeNode;
    }

    /**
     * Return true when any TextNode child of the body contains a
     * newline. A body without any internal newlines is treated as
     * single-line for close-border gate purposes.
     *
     * @param Node[] $body
     */
    private function isStructurallyMultiLine(array $body): bool
    {
        foreach ($body as $child) {
            if ($child instanceof TextNode && str_contains($child->content, "\n")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return true when the block node was authored at line start in
     * source. The prev sibling must be null or a TextNode ending with
     * `\n[ \t]*$`. The next sibling must be null, a TextNode starting
     * with `^[ \t]*\n`, or a TextNode that contains no newlines (the
     * block shares the physical source line with inline trailing
     * content like a closing `>`, in which case an empty render
     * collapses the leading line-break cleanly).
     */
    private function isStructurallyOnOwnLine(?Node $prev, ?Node $next): bool
    {
        $prevOk = null === $prev
            || ($prev instanceof TextNode && 1 === preg_match('/\n[ \t]*$/', $prev->content));
        $nextOk = null === $next
            || ($next instanceof TextNode && (
                1 === preg_match('/^[ \t]*\n/', $next->content)
                || !str_contains($next->content, "\n")
            ));

        return $prevOk && $nextOk;
    }

    /**
     * Recurse into a block-node, returning a copy with trimmed
     * bodies. Leaf nodes are returned as-is.
     */
    private function recurseInto(Node $node): Node
    {
        return match (true) {
            $node instanceof ConditionalNode => $this->recurseConditional($node),
            $node instanceof LoopNode => $this->recurseLoop($node),
            $node instanceof SlotNode => $this->recurseSlot($node),
            $node instanceof IncludeNode => $this->recurseInclude($node),
            default => $node,
        };
    }

    /**
     * Trim then- and else-bodies internally, then apply the else-
     * border between thenBody.last and elseBody[0]. When the then-
     * body is structurally multi-line, the `{else}` tag also absorbs
     * any trailing source-line indent on thenBody.last, even when
     * elseBody[0] does not start with whitespace + newline.
     */
    private function recurseConditional(ConditionalNode $node): ConditionalNode
    {
        $newThen = $this->trimChildren($node->thenBody);
        $newElse = $this->trimChildren($node->elseBody);

        if ([] !== $newElse && [] !== $newThen) {
            $thenLastKey = array_key_last($newThen);
            $thenLast = $newThen[$thenLastKey];
            $elseFirst = $newElse[0];
            $thenMultiLine = $this->isStructurallyMultiLine($newThen);
            [$updatedLast, $updatedFirst] = $this->trimBorder(
                $thenLast,
                $elseFirst,
                true,
                $thenMultiLine,
            );
            if ($updatedLast !== $thenLast && $updatedLast instanceof Node) {
                $newThen[$thenLastKey] = $updatedLast;
                $thenLast = $updatedLast;
            }
            if ($updatedFirst !== $elseFirst && $updatedFirst instanceof Node) {
                $newElse[0] = $updatedFirst;
            }
            if ($thenMultiLine
                && $thenLast instanceof TextNode
                && 1 === preg_match('/(?:^|\n)[ \t]+$/', $thenLast->content)
            ) {
                $stripped = preg_replace('/[ \t]+$/', '', $thenLast->content);
                if (null !== $stripped && $stripped !== $thenLast->content) {
                    $newThen[$thenLastKey] = new TextNode($stripped, $thenLast->line, $thenLast->column);
                }
            }
        } elseif ([] !== $newElse) {
            $elseFirst = $newElse[0];
            [, $updatedFirst] = $this->trimBorder(null, $elseFirst, true);
            if ($updatedFirst !== $elseFirst && $updatedFirst instanceof Node) {
                $newElse[0] = $updatedFirst;
            }
        }

        if ($newThen === $node->thenBody && $newElse === $node->elseBody) {
            return $node;
        }

        return new ConditionalNode(
            $node->condition,
            $newThen,
            $newElse,
            $node->negate,
            $node->line,
            $node->column,
        );
    }

    private function recurseLoop(LoopNode $node): LoopNode
    {
        $newBody = $this->trimChildren($node->body);
        if ($newBody === $node->body) {
            return $node;
        }

        return new LoopNode(
            $node->path,
            $newBody,
            $node->itemName,
            $node->odd,
            $node->even,
            $node->split,
            $node->line,
            $node->column,
        );
    }

    private function recurseSlot(SlotNode $node): SlotNode
    {
        $newBody = $this->trimChildren($node->defaultBody);
        if ($newBody === $node->defaultBody) {
            return $node;
        }

        return new SlotNode(
            $node->name,
            $node->default,
            $newBody,
            $node->line,
            $node->column,
        );
    }

    /**
     * Recurse into each FillNode body inside IncludeNode.fills.
     *
     * Fills have no parent-level TextNode siblings (the parser skips
     * whitespace between fill blocks), so the open border uses null
     * left and the close border uses the virtual-EOF rule directly.
     */
    private function recurseInclude(IncludeNode $node): IncludeNode
    {
        $newFills = [];
        $changed = false;

        foreach ($node->fills as $fill) {
            $newBody = $this->trimChildren($fill->body);

            if ([] !== $newBody) {
                $first = $newBody[0];
                [, $newFirst] = $this->trimBorder(null, $first, false);
                if ($newFirst !== $first && $newFirst instanceof Node) {
                    $newBody[0] = $newFirst;
                }
            }
            if ([] !== $newBody) {
                $lastKey = array_key_last($newBody);
                $last = $newBody[$lastKey];
                if ($last instanceof TextNode && 1 === preg_match('/\n[ \t]+$/', $last->content)) {
                    $trimmed = preg_replace('/[ \t]+$/', '', $last->content);
                    if (null !== $trimmed && $trimmed !== $last->content) {
                        $newBody[$lastKey] = new TextNode($trimmed, $last->line, $last->column);
                    }
                }
            }

            if ($newBody !== $fill->body) {
                $newFills[] = new FillNode($fill->name, $newBody, $fill->line, $fill->column);
                $changed = true;
            } else {
                $newFills[] = $fill;
            }
        }

        if (!$changed) {
            return $node;
        }

        return new IncludeNode($node->templatePath, $newFills, $node->params, $node->line, $node->column);
    }

    /**
     * Dispatch open + close borders to the correct per-block helper.
     *
     * @return array{?Node, Node, ?Node}
     */
    private function applyBlockBorders(Node $block, ?Node $prev, ?Node $next): array
    {
        if ($block instanceof ConditionalNode) {
            return $this->applyConditionalBorders($block, $prev, $next);
        }
        if ($block instanceof LoopNode) {
            return $this->applyLoopBorders($block, $prev, $next);
        }
        if ($block instanceof SlotNode) {
            return $this->applySlotBorders($block, $prev, $next);
        }
        if ($block instanceof IncludeNode) {
            return $this->applyIncludeBorders($block, $prev, $next);
        }

        return [$prev, $block, $next];
    }

    /**
     * @return array{?Node, ConditionalNode, ?Node}
     */
    private function applyConditionalBorders(ConditionalNode $node, ?Node $prev, ?Node $next): array
    {
        $newThen = $node->thenBody;
        $newElse = $node->elseBody;
        $newPrev = $prev;
        $newNext = $next;

        $closeOnElse = [] !== $newElse;
        $closeMultiLine = $this->isStructurallyMultiLine($closeOnElse ? $newElse : $newThen);

        $thenSingleLine = !$this->isStructurallyMultiLine($newThen);
        $elseSingleLine = [] === $newElse || !$this->isStructurallyMultiLine($newElse);
        $markOnOwnLine = !$node->onOwnLine
            && $thenSingleLine
            && $elseSingleLine
            && $this->isStructurallyOnOwnLine($prev, $next);

        if ([] !== $newThen) {
            [$newPrev, $newFirst] = $this->trimBorder($newPrev, $newThen[0], false);
            if ($newFirst !== $newThen[0] && $newFirst instanceof Node) {
                $newThen[0] = $newFirst;
            }
        }

        $arr = $closeOnElse ? $newElse : $newThen;
        if ([] !== $arr) {
            $lastKey = array_key_last($arr);
            [$newLast, $newNext] = $this->trimCloseBorderWithEof(
                $arr[$lastKey],
                $newNext,
                $closeMultiLine,
            );
            if ($newLast !== $arr[$lastKey]) {
                $arr[$lastKey] = $newLast;
                if ($closeOnElse) {
                    $newElse = $arr;
                } else {
                    $newThen = $arr;
                }
            }
        }

        if ($newThen === $node->thenBody && $newElse === $node->elseBody && !$markOnOwnLine) {
            return [$newPrev, $node, $newNext];
        }

        return [
            $newPrev,
            new ConditionalNode(
                $node->condition,
                $newThen,
                $newElse,
                $node->negate,
                $node->line,
                $node->column,
                onOwnLine: $node->onOwnLine || $markOnOwnLine,
            ),
            $newNext,
        ];
    }

    /**
     * @return array{?Node, LoopNode, ?Node}
     */
    private function applyLoopBorders(LoopNode $node, ?Node $prev, ?Node $next): array
    {
        $markOnOwnLine = !$node->onOwnLine
            && !$this->isStructurallyMultiLine($node->body)
            && $this->isStructurallyOnOwnLine($prev, $next);

        if ([] === $node->body) {
            if (!$markOnOwnLine) {
                return [$prev, $node, $next];
            }

            return [
                $prev,
                new LoopNode(
                    $node->path,
                    $node->body,
                    $node->itemName,
                    $node->odd,
                    $node->even,
                    $node->split,
                    $node->line,
                    $node->column,
                    onOwnLine: true,
                ),
                $next,
            ];
        }
        $newBody = $node->body;
        $newPrev = $prev;
        $newNext = $next;
        $closeMultiLine = $this->isStructurallyMultiLine($newBody);

        [$newPrev, $newFirst] = $this->trimBorder($newPrev, $newBody[0], false);
        if ($newFirst !== $newBody[0] && $newFirst instanceof Node) {
            $newBody[0] = $newFirst;
        }

        $lastKey = array_key_last($newBody);
        [$newLast, $newNext] = $this->trimCloseBorderWithEof(
            $newBody[$lastKey],
            $newNext,
            $closeMultiLine,
        );
        if ($newLast !== $newBody[$lastKey]) {
            $newBody[$lastKey] = $newLast;
        }

        if ($newBody === $node->body && !$markOnOwnLine) {
            return [$newPrev, $node, $newNext];
        }

        return [
            $newPrev,
            new LoopNode(
                $node->path,
                $newBody,
                $node->itemName,
                $node->odd,
                $node->even,
                $node->split,
                $node->line,
                $node->column,
                onOwnLine: $node->onOwnLine || $markOnOwnLine,
            ),
            $newNext,
        ];
    }

    /**
     * @return array{?Node, SlotNode, ?Node}
     */
    private function applySlotBorders(SlotNode $node, ?Node $prev, ?Node $next): array
    {
        if ([] === $node->defaultBody) {
            if ($node->onOwnLine || !$this->isStructurallyOnOwnLine($prev, $next)) {
                return [$prev, $node, $next];
            }

            return [
                $prev,
                new SlotNode(
                    $node->name,
                    $node->default,
                    $node->defaultBody,
                    $node->line,
                    $node->column,
                    onOwnLine: true,
                ),
                $next,
            ];
        }
        $newBody = $node->defaultBody;
        $newPrev = $prev;
        $newNext = $next;
        $closeMultiLine = $this->isStructurallyMultiLine($newBody);

        [$newPrev, $newFirst] = $this->trimBorder($newPrev, $newBody[0], false);
        if ($newFirst !== $newBody[0] && $newFirst instanceof Node) {
            $newBody[0] = $newFirst;
        }

        $lastKey = array_key_last($newBody);
        [$newLast, $newNext] = $this->trimCloseBorderWithEof(
            $newBody[$lastKey],
            $newNext,
            $closeMultiLine,
        );
        if ($newLast !== $newBody[$lastKey]) {
            $newBody[$lastKey] = $newLast;
        }

        if ($newBody === $node->defaultBody) {
            return [$newPrev, $node, $newNext];
        }

        return [
            $newPrev,
            new SlotNode(
                $node->name,
                $node->default,
                $newBody,
                $node->line,
                $node->column,
            ),
            $newNext,
        ];
    }

    /**
     * Apply on-own-line marking to an IncludeNode. Includes carry no
     * body to trim at this layer (fills are processed inside
     * recurseInclude); the only structural action here is to mark
     * the include as on-own-line so the runtime can collapse its
     * surrounding line when the partial renders empty.
     *
     * @return array{?Node, IncludeNode, ?Node}
     */
    private function applyIncludeBorders(IncludeNode $node, ?Node $prev, ?Node $next): array
    {
        if ($node->onOwnLine || !$this->isStructurallyOnOwnLine($prev, $next)) {
            return [$prev, $node, $next];
        }

        return [
            $prev,
            new IncludeNode(
                $node->templatePath,
                $node->fills,
                $node->params,
                $node->line,
                $node->column,
                onOwnLine: true,
            ),
            $next,
        ];
    }

    /**
     * Apply close-border (right=$next, requireLeftNewline=true) and
     * the virtual-EOF rule when $next is null. The $multiLineBody flag
     * is forwarded to the close-border's left-gate; the virtual-EOF
     * gate stays strict regardless of the flag.
     *
     * @return array{Node, ?Node}
     */
    private function trimCloseBorderWithEof(Node $last, ?Node $next, bool $multiLineBody = false): array
    {
        [$newLeftSide, $newNext] = $this->trimBorder($last, $next, true, $multiLineBody);
        $newLast = $newLeftSide instanceof Node ? $newLeftSide : $last;

        if (null === $newNext
            && $newLast instanceof TextNode
            && 1 === preg_match('/\n[ \t]+$/', $newLast->content)
        ) {
            $trimmed = preg_replace('/[ \t]+$/', '', $newLast->content);
            if (null !== $trimmed && $trimmed !== $newLast->content) {
                $newLast = new TextNode($trimmed, $newLast->line, $newLast->column);
            }
        }

        return [$newLast, $newNext];
    }

    /**
     * Border-trim primitive.
     *
     * Right must be a TextNode whose content matches `^[ \t]*\n` --
     * the prefix (incl. the newline) is stripped. When
     * $requireLeftNewline is true, left must end with `\n[ \t]*$` (or
     * be null) for the trim to fire. When $requireLeftNewline and
     * $multiLineBody are both true, the left-gate additionally accepts
     * a whitespace-only TextNode as the trailing-indent of the
     * close-tag of a structurally multi-line body. The left's trailing
     * indent is stripped when it sits at the end of a line (after a
     * newline) or when the entire node is whitespace-only orphan
     * indent from prior border trimming.
     *
     * @return array{?Node, ?Node}
     */
    private function trimBorder(
        ?Node $left,
        ?Node $right,
        bool $requireLeftNewline,
        bool $multiLineBody = false,
    ): array {
        if (!$right instanceof TextNode) {
            return [$left, $right];
        }
        if (1 !== preg_match('/^[ \t]*\n/', $right->content)) {
            return [$left, $right];
        }
        if ($requireLeftNewline) {
            if ($left instanceof TextNode) {
                $gate = $multiLineBody
                    ? '/(?:^|\n)[ \t]*$/'
                    : '/\n[ \t]*$/';
                if (1 !== preg_match($gate, $left->content)) {
                    return [$left, $right];
                }
            } elseif (null !== $left) {
                return [$left, $right];
            }
        }

        $newRightContent = preg_replace('/^[ \t]*\n/', '', $right->content, 1);
        if (null === $newRightContent || $newRightContent === $right->content) {
            return [$left, $right];
        }
        $newRight = new TextNode($newRightContent, $right->line, $right->column);

        $newLeft = $left;
        if ($left instanceof TextNode && 1 === preg_match('/(?:^|\n)[ \t]+$/', $left->content)) {
            $newLeftContent = preg_replace('/[ \t]+$/', '', $left->content);
            if (null !== $newLeftContent && $newLeftContent !== $left->content) {
                $newLeft = new TextNode($newLeftContent, $left->line, $left->column);
            }
        }

        return [$newLeft, $newRight];
    }
}
