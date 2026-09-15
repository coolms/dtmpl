<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Validation;

use CoolMS\Dtmpl\AST\ComparisonNode;
use CoolMS\Dtmpl\AST\ConditionalNode;
use CoolMS\Dtmpl\AST\ConstNode;
use CoolMS\Dtmpl\AST\DefineNode;
use CoolMS\Dtmpl\AST\FillNode;
use CoolMS\Dtmpl\AST\IncludeNode;
use CoolMS\Dtmpl\AST\LoopNode;
use CoolMS\Dtmpl\AST\Node;
use CoolMS\Dtmpl\AST\SlotNode;
use CoolMS\Dtmpl\AST\TemplateNode;
use CoolMS\Dtmpl\AST\VariableNode;
use CoolMS\Dtmpl\AST\WidgetNode;
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Exception\TemplateValidationException;
use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Parser\Parser;
use CoolMS\Dtmpl\ValueObject\ContextSchema;
use CoolMS\Dtmpl\ValueObject\ContextSchemaConditional;
use CoolMS\Dtmpl\ValueObject\ContextSchemaConstant;
use CoolMS\Dtmpl\ValueObject\ContextSchemaLoop;
use CoolMS\Dtmpl\ValueObject\ContextSchemaVariable;

/**
 * The front door for validating a template. Two responsibilities:
 *
 *   1. Lex + parse arbitrary text content; surface syntax errors as
 *      `TemplateValidationException` with the original
 *      `SyntaxException` chained so callers can show line/column
 *      details.
 *   2. Walk the produced AST and assemble a `ContextSchema` listing
 *      every `{var:..}` / `{const:..}` / `{loop:..}` / `{if:..}` /
 *      `{unless:..}` reference encountered.
 *
 * The schema is deliberately lightweight: paths only, no type
 * inference, no required-vs-optional split. The point is to produce
 * readable hints beside an uploaded template and to gate uploads
 * against grossly malformed templates -- not to fully type-check them.
 * `DtmplEngine::validate()` answers the narrower question of whether a
 * template compiles; this class answers what the template reads.
 *
 * The injected `Parser` is shared: `Parser::parse()` resets its token
 * list and cursor on entry, so one instance serves every validation.
 * The `Lexer` keeps no state across calls either.
 */
final readonly class DtmplSyntaxValidator
{
    /**
     * @param AliasResolverInterface|null $aliases the `@alias` dictionary; without one every alias is unknown
     */
    public function __construct(
        private Lexer $lexer,
        private Parser $parser,
        private ?AliasResolverInterface $aliases = null,
    ) {
    }

    /**
     * Lex + parse + walk. Throws on lexer/parser failure; returns a
     * fully-populated schema otherwise. Empty input yields an empty
     * (but valid) schema rather than a thrown error -- empty templates
     * are a real authoring shape (placeholder uploads).
     */
    public function validateAndExtract(string $content): ContextSchema
    {
        if ('' === trim($content)) {
            return new ContextSchema();
        }

        try {
            $tokens = $this->lexer->tokenize($content);
            $ast = $this->parser->parse($tokens);
        } catch (SyntaxException $e) {
            throw TemplateValidationException::syntaxError($e->getMessage(), $e);
        }

        return $this->extractSchema($ast);
    }

    private function extractSchema(TemplateNode $root): ContextSchema
    {
        $variables = [];
        $constants = [];
        $loops = [];
        $conditionals = [];

        // Track the *innermost* loop alias so a `{var:item.title}`
        // found inside `{loop:items:item}` records `loopAlias=item`.
        // The walk is depth-first, so a stack matches lexical scope.
        /** @var list<string> $loopStack */
        $loopStack = [];

        $this->walk($root, $variables, $constants, $loops, $conditionals, $loopStack);

        return new ContextSchema(
            variables: $this->dedupeVariables($variables),
            constants: $this->dedupeConstants($constants),
            loops: $loops,
            conditionals: $conditionals,
        );
    }

    /**
     * @param list<ContextSchemaVariable>    $variables
     * @param list<ContextSchemaConstant>    $constants
     * @param list<ContextSchemaLoop>        $loops
     * @param list<ContextSchemaConditional> $conditionals
     * @param list<string>                   $loopStack
     */
    private function walk(
        Node $node,
        array &$variables,
        array &$constants,
        array &$loops,
        array &$conditionals,
        array &$loopStack,
    ): void {
        if ($node instanceof VariableNode) {
            $pathSegments = array_values($node->path);
            $loopAlias = $this->matchingLoopAlias($pathSegments, $loopStack);
            $firstSegment = $pathSegments[0] ?? '';

            // `@alias` on the first path segment declares an entity
            // reference. Collapse every variable referencing the same
            // alias to ONE schema entry whose path is the bare `@alias`
            // (no sub-fields); the render-time hydrator replaces that
            // key with the flattened projection, and the existing
            // dotted-path resolver pulls `@alias.email` etc. out of the
            // dict. The dedupe pass at the end of `extractSchema()`
            // swallows duplicate entries from `{var:@alias.x}`,
            // `{var:@alias.y}`, etc.
            if (str_starts_with($firstSegment, '@')) {
                $aliasName = substr($firstSegment, 1);
                $entityType = $this->aliases?->resolve($aliasName);
                if (null === $entityType) {
                    throw TemplateValidationException::syntaxError($this->unknownAliasMessage($aliasName));
                }
                $variables[] = new ContextSchemaVariable(
                    path: $firstSegment,
                    filters: [],
                    loopAlias: $loopAlias,
                    entityType: $entityType,
                );

                return;
            }

            $path = implode('.', $pathSegments);
            $variables[] = new ContextSchemaVariable(
                path: $path,
                filters: array_values(array_map(static fn ($f): string => $f->name, $node->filters)),
                loopAlias: $loopAlias,
            );

            // Variables can themselves carry a comparison/widget source
            // (the `assignSource` shape) -- those are runtime-only and
            // don't introduce new top-level paths to record here.
            return;
        }

        if ($node instanceof ConstNode) {
            $constants[] = new ContextSchemaConstant(name: $node->name);

            return;
        }

        if ($node instanceof LoopNode) {
            $loops[] = new ContextSchemaLoop(
                path: implode('.', $node->path),
                alias: $node->itemName,
            );
            $loopStack[] = $node->itemName;
            foreach ($node->body as $child) {
                $this->walk($child, $variables, $constants, $loops, $conditionals, $loopStack);
            }
            array_pop($loopStack);

            return;
        }

        if ($node instanceof ConditionalNode) {
            $condition = $node->condition;
            $path = $this->extractConditionPath($condition);
            if (null !== $path) {
                $conditionals[] = new ContextSchemaConditional(
                    path: $path,
                    negate: $node->negate,
                );
            }
            // The condition itself may also carry filter/path data the
            // walk should pick up so they show up in the variables
            // list -- recursing into it yields the paths through the
            // VariableNode branch above.
            $this->walk($condition, $variables, $constants, $loops, $conditionals, $loopStack);
            foreach ($node->thenBody as $child) {
                $this->walk($child, $variables, $constants, $loops, $conditionals, $loopStack);
            }
            foreach ($node->elseBody as $child) {
                $this->walk($child, $variables, $constants, $loops, $conditionals, $loopStack);
            }

            return;
        }

        if ($node instanceof ComparisonNode) {
            $this->walk($node->left, $variables, $constants, $loops, $conditionals, $loopStack);
            if ($node->right instanceof Node) {
                $this->walk($node->right, $variables, $constants, $loops, $conditionals, $loopStack);
            }

            return;
        }

        if ($node instanceof TemplateNode) {
            foreach ($node->children as $child) {
                $this->walk($child, $variables, $constants, $loops, $conditionals, $loopStack);
            }

            return;
        }

        if ($node instanceof IncludeNode) {
            // Includes pull in another template by path -- we don't
            // recurse into them here. The included template is
            // validated separately when *it* is uploaded; doing that
            // walk here would couple upload validation to the loader
            // chain (which has no business running during an upload).
            // Fills referenced inside the include still walk because
            // they live on this node.
            foreach ($node->fills as $fill) {
                $this->walk($fill, $variables, $constants, $loops, $conditionals, $loopStack);
            }

            return;
        }

        if ($node instanceof FillNode) {
            foreach ($node->body as $child) {
                $this->walk($child, $variables, $constants, $loops, $conditionals, $loopStack);
            }

            return;
        }

        if ($node instanceof SlotNode) {
            foreach ($node->defaultBody as $child) {
                $this->walk($child, $variables, $constants, $loops, $conditionals, $loopStack);
            }

            return;
        }

        if ($node instanceof DefineNode) {
            // A {def:name=source} introduces a context binding. The
            // source is a VariableSource whose value is either a path
            // (string[]), a scalar literal, or a WidgetNode. We only
            // recurse into the WidgetNode case here -- paths and
            // literals don't introduce additional schema entries that
            // matter for the lightweight overview, and treating them
            // as variables would conflate "this template assigns X"
            // with "this template reads X".
            if ($node->assignSource?->value instanceof Node) {
                $this->walk($node->assignSource->value, $variables, $constants, $loops, $conditionals, $loopStack);
            }

            return;
        }

        if ($node instanceof WidgetNode) {
            // Widget params can themselves contain variable/const refs;
            // walk anything that's a Node value.
            foreach ($node->params as $value) {
                if ($value instanceof Node) {
                    $this->walk($value, $variables, $constants, $loops, $conditionals, $loopStack);
                }
            }
        }

        // TextNode / LiteralNode / unknown: nothing to record.
    }

    /**
     * If the variable path's first segment matches an active loop's
     * alias, return it so the schema can group the variable under the
     * loop. Otherwise null.
     *
     * @param list<string> $path
     * @param list<string> $loopStack innermost alias is the last entry
     */
    private function matchingLoopAlias(array $path, array $loopStack): ?string
    {
        if ([] === $path || [] === $loopStack) {
            return null;
        }
        $first = $path[0];
        // Walk the stack from innermost out; first match wins.
        for ($i = count($loopStack) - 1; $i >= 0; --$i) {
            if ($loopStack[$i] === $first) {
                return $first;
            }
        }

        return null;
    }

    private function extractConditionPath(Node $condition): ?string
    {
        if ($condition instanceof VariableNode) {
            return implode('.', $condition->path);
        }
        if ($condition instanceof ComparisonNode) {
            return implode('.', $condition->left->path);
        }

        return null;
    }

    /**
     * Same path + same filters + same loop scope -> one entry.
     * Repeated mentions of `{var:user.name}` shouldn't dominate the
     * schema rendering.
     *
     * @param list<ContextSchemaVariable> $variables
     *
     * @return list<ContextSchemaVariable>
     */
    private function dedupeVariables(array $variables): array
    {
        $seen = [];
        foreach ($variables as $v) {
            $key = sprintf('%s|%s|%s', $v->path, implode(',', $v->filters), $v->loopAlias ?? '');
            $seen[$key] = $v;
        }

        return array_values($seen);
    }

    /**
     * Format the user-facing message for an unknown entity alias.
     * Lists the known aliases so the author can correct a typo
     * without resorting to a code search.
     */
    private function unknownAliasMessage(string $alias): string
    {
        $known = $this->aliases?->knownAliases() ?? [];
        sort($known);

        return sprintf(
            'Unknown entity alias "@%s". Available aliases: %s.',
            $alias,
            [] === $known ? '(none registered)' : '@' . implode(', @', $known),
        );
    }

    /**
     * @param list<ContextSchemaConstant> $constants
     *
     * @return list<ContextSchemaConstant>
     */
    private function dedupeConstants(array $constants): array
    {
        $seen = [];
        foreach ($constants as $c) {
            $seen[$c->name] = $c;
        }

        return array_values($seen);
    }
}
