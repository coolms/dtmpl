<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use CoolMS\Dtmpl\AST\ComparisonNode;
use CoolMS\Dtmpl\AST\ConditionalNode;
use CoolMS\Dtmpl\AST\ConstNode;
use CoolMS\Dtmpl\AST\DefineNode;
use CoolMS\Dtmpl\AST\FillNode;
use CoolMS\Dtmpl\AST\FilterNode;
use CoolMS\Dtmpl\AST\IncludeNode;
use CoolMS\Dtmpl\AST\LoopNode;
use CoolMS\Dtmpl\AST\Node;
use CoolMS\Dtmpl\AST\SlotNode;
use CoolMS\Dtmpl\AST\TemplateNode;
use CoolMS\Dtmpl\AST\TextNode;
use CoolMS\Dtmpl\AST\TranslateNode;
use CoolMS\Dtmpl\AST\VariableNode;
use CoolMS\Dtmpl\AST\VariableSource;
use CoolMS\Dtmpl\AST\VariableSourceType;
use CoolMS\Dtmpl\AST\WidgetNode;
use CoolMS\Dtmpl\Exception\CircularIncludeException;
use CoolMS\Dtmpl\Exception\RuntimeException;
use CoolMS\Dtmpl\TemplateLoaderInterface;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use CoolMS\Dtmpl\Widget\WidgetResult;
use CoolMS\Dtmpl\Widget\WidgetView;
use DateTimeInterface;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;
use Traversable;

/**
 * Executes the AST and produces an output string.
 */
final class Executor
{
    /** VFS path of the template currently being executed (for relative include resolution). */
    private string $currentPath = '';

    /** @var string[] Stack of absolute paths currently being rendered, for circular-include detection. */
    private array $includeStack = [];

    public function __construct(
        private readonly FilterRegistry $filters = new FilterRegistry(),
        private readonly ?TemplateLoaderInterface $loader = null,
        private readonly ?TemplateCompilerInterface $compiler = null,
        private readonly ?WidgetRegistry $widgets = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Execute template with context data.
     *
     * @param array<string, mixed> $data
     * @param string               $templatePath VFS path of this template (used for relative include resolution)
     */
    public function execute(TemplateNode $template, array $data = [], string $templatePath = ''): string
    {
        $this->currentPath = $templatePath;
        $context = new Context($data);

        return $this->executeNode($template, $context);
    }

    /**
     * Get filter registry.
     */
    public function getFilters(): FilterRegistry
    {
        return $this->filters;
    }

    /**
     * Register custom filter.
     */
    public function registerFilter(string $name, callable $filter): void
    {
        $this->filters->register($name, $filter);
    }

    /**
     * The render's target locale, or null to mean "use the ambient one".
     *
     * Read from the ordinary `locale` render variable rather than a separate
     * channel, because every consumer that knows the locale already passes it
     * for the template's own use -- mail composition and server-side variant
     * rendering both do. A second, parallel way to say the same thing is how
     * the two drift apart.
     */
    private static function localeOf(Context $context): ?string
    {
        $locale = $context->get(['locale']);

        return is_string($locale) && '' !== $locale ? $locale : null;
    }

    /**
     * Execute a single node.
     */
    private function executeNode(Node $node, Context $context): string
    {
        return match (true) {
            $node instanceof TemplateNode => $this->executeTemplate($node, $context),
            $node instanceof TextNode => $this->executeText($node, $context),
            $node instanceof DefineNode => $this->executeDefine($node, $context),
            $node instanceof VariableNode => $this->executeVariable($node, $context),
            $node instanceof LoopNode => $this->executeLoop($node, $context),
            $node instanceof ConditionalNode => $this->executeConditional($node, $context),
            $node instanceof IncludeNode => $this->executeInclude($node, $context),
            $node instanceof SlotNode => $this->executeSlot($node, $context),
            $node instanceof ConstNode => $this->executeConst($node, $context),
            $node instanceof WidgetNode => $this->executeWidget($node, $context),
            $node instanceof TranslateNode => $this->executeTranslate($node, $context),
            $node instanceof FillNode => '', // consumed by executeInclude, never dispatched directly
            default => throw new RuntimeException('Unknown node type: ' . get_class($node)),
        };
    }

    /**
     * Execute template (root node).
     */
    private function executeTemplate(TemplateNode $node, Context $context): string
    {
        return $this->renderChildren($node->children, $context);
    }

    /**
     * Render a children array and concatenate the results.
     *
     * When a child is marked as structurally on its own line and its
     * render result is the empty string, the trailing `\n[ \t]*$` of
     * the accumulated output is consumed so the otherwise-empty line
     * collapses cleanly. When a composition node (IncludeNode or
     * SlotNode) renders non-empty content that ends with `\n[ \t]*$`
     * and the next sibling is a TextNode starting with `^[ \t]*\n`,
     * the rendered output's trailing newline-and-indent is consumed
     * so the parent's next text provides a single line-break instead
     * of producing a blank line. The marking is set at compile time
     * by WhitespaceTrimmer; both runtime trims are conditional on
     * the actual rendered output.
     *
     * @param Node[] $children
     */
    private function renderChildren(array $children, Context $context): string
    {
        $output = '';
        $indexedChildren = array_values($children);
        $count = count($indexedChildren);
        for ($i = 0; $i < $count; ++$i) {
            $child = $indexedChildren[$i];
            $rendered = $this->executeNode($child, $context);
            if ('' === $rendered && $this->shouldCleanupOnOwnLine($child)) {
                $output = (string) preg_replace('/\n[ \t]*$/', '', $output);
                continue;
            }
            // Consume the layout's placeholder-line indent so the rendered composition content
            // starts at its own authored column instead of stacking with the placeholder indent.
            // When the rendered content carries its own leading newline, consume the full
            // newline-plus-indent from the output buffer so the two line breaks do not combine
            // into a blank line.
            if ('' !== $rendered
                && $this->isCompositionNode($child)
                && $this->shouldCleanupOnOwnLine($child)
            ) {
                $pattern = 1 === preg_match('/^[ \t]*\n/', $rendered)
                    ? '/\n[ \t]*$/'
                    : '/(?<=\n)[ \t]+$/';
                $output = (string) preg_replace($pattern, '', $output);
            }
            if ('' !== $rendered && $this->isCompositionNode($child)) {
                $next = $indexedChildren[$i + 1] ?? null;
                if ($next instanceof TextNode
                    && 1 === preg_match('/^[ \t]*\n/', $next->content)
                    && 1 === preg_match('/\n[ \t]*$/', $rendered)
                ) {
                    $rendered = (string) preg_replace('/\n[ \t]*$/', '', $rendered);
                }
            }
            $output .= $rendered;
        }

        return $output;
    }

    /**
     * Return true when the child carries the structural on-own-line
     * marking from WhitespaceTrimmer.
     */
    private function shouldCleanupOnOwnLine(Node $child): bool
    {
        return ($child instanceof ConditionalNode && $child->onOwnLine)
            || ($child instanceof LoopNode && $child->onOwnLine)
            || ($child instanceof IncludeNode && $child->onOwnLine)
            || ($child instanceof SlotNode && $child->onOwnLine);
    }

    /**
     * Return true when the child is a composition node whose
     * rendered output may carry trailing newline-and-indent that
     * needs trimming against a newline-leading next sibling.
     */
    private function isCompositionNode(Node $child): bool
    {
        return $child instanceof IncludeNode || $child instanceof SlotNode;
    }

    /**
     * Execute text node (literal content).
     */
    private function executeText(TextNode $node, Context $context): string
    {
        return $node->content;
    }

    /**
     * Execute define node -- assign to context, return ''.
     *
     * When assignSource is set, value comes from source (path/literal/widget).
     */
    private function executeDefine(DefineNode $node, Context $context): string
    {
        $value = null !== $node->assignSource
            ? $this->resolveSource($node->assignSource, $context)
            : $context->get($node->path, $node->value);

        // Apply filters
        foreach ($node->filters as $filter) {
            $value = $this->applyFilter($filter, $value, $context);
        }

        $context->set($node->path, $value);

        return '';
    }

    /**
     * Execute variable node -- optionally assign, then output.
     *
     * When assignSource is set, value comes from source, is assigned to context,
     * filters applied, then output returned (echo-assign semantics).
     */
    private function executeVariable(VariableNode $node, Context $context): string
    {
        if (null !== $node->assignSource) {
            // Assignment + echo
            $value = $this->resolveSource($node->assignSource, $context);

            // Apply filters
            foreach ($node->filters as $filter) {
                $value = $this->applyFilter($filter, $value, $context);
            }

            $context->set($node->path, $value);

            return $this->valueToString($value);
        }

        // Regular variable output
        $value = $context->get($node->path, $node->default);

        // Apply filters
        foreach ($node->filters as $filter) {
            $value = $this->applyFilter($filter, $value, $context);
        }

        // Convert to string
        return $this->valueToString($value);
    }

    /**
     * Resolve a VariableSource to a value.
     *
     * For Widget sources, the raw Stringable result is returned so
     * downstream consumers (e.g., `{def:foo=widget:...}` assignment)
     * keep the object form for property navigation. Concatenation
     * paths still stringify via `executeWidget` directly.
     */
    private function resolveSource(VariableSource $source, Context $context): mixed
    {
        return match ($source->type) {
            VariableSourceType::Path => $context->get($source->value),
            VariableSourceType::Literal => $source->value,
            VariableSourceType::Widget => $this->resolveWidgetRaw($source->value, $context),
        };
    }

    /**
     * Execute loop node.
     */
    private function executeLoop(LoopNode $node, Context $context): string
    {
        // Get collection
        $collection = $context->get($node->path, []);

        if (!is_array($collection) && !($collection instanceof Traversable)) {
            return '';
        }

        // Convert to array if needed
        if ($collection instanceof Traversable) {
            $collection = iterator_to_array($collection);
        }

        if (empty($collection)) {
            return '';
        }

        $output = '';
        $index = 0;
        $total = count($collection);
        $keys = array_keys($collection);

        // Detect associative arrays (at least one non-integer or out-of-order key).
        // Sequential arrays (0-based integer keys in order) keep existing behaviour:
        //   item = the element value directly.
        // Associative arrays wrap each entry as ['key' => $k, 'value' => $v] so
        // templates can access both sides: {var:item.key} and {var:item.value}.
        // {var:loop.key} continues to work for both cases.
        $isAssoc = $keys !== range(0, $total - 1);

        foreach ($keys as $key) {
            $value = $collection[$key];

            // Check odd/even filters
            if ($node->odd && 0 === $index % 2) {
                ++$index;
                continue;
            }

            if ($node->even && 0 !== $index % 2) {
                ++$index;
                continue;
            }

            // For associative arrays expose key+value as a sub-object so templates
            // can write {var:item.key} and {var:item.value}. For sequential arrays
            // item is the element directly (backwards-compatible).
            $itemValue = $isAssoc ? ['key' => $key, 'value' => $value] : $value;

            // Create loop context using the custom alias
            $loopData = [
                $node->itemName => $itemValue, // Use the alias: 'item', 'product', etc.
                'loop' => [
                    'index' => $index,
                    'index1' => $index + 1,
                    'key' => $key,
                    'first' => 0 === $index,
                    'last' => $index === $total - 1,
                    'length' => $total,
                    'odd' => 0 !== $index % 2,
                    'even' => 0 === $index % 2,
                ],
            ];

            // Execute loop body with child context
            $childContext = $context->createChild($loopData);

            $output .= $this->renderChildren($node->body, $childContext);

            // Add separator (split)
            if (null !== $node->split && $index < $total - 1) {
                $output .= $node->split;
            }

            ++$index;
        }

        return $output;
    }

    /**
     * Execute conditional node.
     */
    private function executeConditional(ConditionalNode $node, Context $context): string
    {
        // Evaluate condition
        $conditionValue = $this->evaluateCondition($node->condition, $context);

        // Negate if unless
        if ($node->negate) {
            $conditionValue = !$conditionValue;
        }

        // Execute appropriate branch
        $body = $conditionValue ? $node->thenBody : $node->elseBody;

        return $this->renderChildren($body, $context);
    }

    /**
     * Evaluate condition (check if truthy or compare equality).
     *
     * - VariableNode: truthy check on the resolved value.
     * - ComparisonNode('eq'): string equality between LHS and RHS.
     *   RHS may be a plain PHP scalar (backtick literal) or a VariableNode (path).
     */
    private function evaluateCondition(Node $node, Context $context): bool
    {
        if ($node instanceof VariableNode) {
            $value = $context->get($node->path);

            return $this->isTruthy($value);
        }

        if ($node instanceof ComparisonNode) {
            $lhs = $context->get($node->left->path);
            $rhs = $node->right instanceof VariableNode
                ? $context->get($node->right->path)
                : $node->right;

            return match ($node->operator) {
                'eq' => (string) $lhs === (string) $rhs,
                'ne' => (string) $lhs !== (string) $rhs,
                'gt' => $this->orderedCompare($lhs, $rhs) > 0,
                'lt' => $this->orderedCompare($lhs, $rhs) < 0,
                'ge' => $this->orderedCompare($lhs, $rhs) >= 0,
                'le' => $this->orderedCompare($lhs, $rhs) <= 0,
                default => false,
            };
        }

        return false;
    }

    /**
     * Check if value is truthy.
     */
    private function isTruthy(mixed $value): bool
    {
        if (null === $value || false === $value) {
            return false;
        }

        if ('' === $value || 0 === $value || '0' === $value) {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        return !(is_countable($value) && 0 === count($value));
    }

    /**
     * Three-way comparison used by ordered operators (gt, lt, ge, le).
     *
     * Numeric on both sides means numeric comparison (so `'10' > '9'`
     * works as expected). Otherwise fall back to string comparison --
     * useful for ISO-style dates and other ordered strings. Null on
     * either side is coerced via the same rules: null is_numeric is
     * false, so null falls into the string branch as the empty string.
     */
    private function orderedCompare(mixed $lhs, mixed $rhs): int
    {
        if (is_numeric($lhs) && is_numeric($rhs)) {
            return (float) $lhs <=> (float) $rhs;
        }

        return (string) $lhs <=> (string) $rhs;
    }

    /**
     * Execute include node -- loads a partial and renders it, optionally with slot fills.
     */
    private function executeInclude(IncludeNode $node, Context $context): string
    {
        if (null === $this->loader || null === $this->compiler) {
            throw new RuntimeException('{include} tags require a TemplateLoader and TemplateCompiler. Pass them to the Executor constructor or use DtmplEngine::withLoader().');
        }

        $resolvedPath = $this->loader->resolve($node->templatePath, $this->currentPath);

        // Circular include detection
        if (in_array($resolvedPath, $this->includeStack, true)) {
            throw new CircularIncludeException([...$this->includeStack, $resolvedPath]);
        }

        // Render fill bodies against the caller's context (fills can reference caller variables)
        $fillMap = [];
        foreach ($node->fills as $fill) {
            $fillMap[$fill->name] = $this->renderChildren($fill->body, $context);
        }

        // Load and compile the partial (DtmplEngine::compile() handles caching)
        $source = $this->loader->load($node->templatePath, $this->currentPath);
        $partialAst = $this->compiler->compile($source);

        // Build child context: inherits caller variables, adds slot fills and include params
        $partialContext = $context->createChild(['__slots' => $fillMap, ...$node->params]);

        // Push include stack and update current path for nested includes
        $this->includeStack[] = $this->currentPath;
        $previousPath = $this->currentPath;
        $this->currentPath = $resolvedPath;

        try {
            return $this->executeTemplate($partialAst, $partialContext);
        } finally {
            $this->currentPath = $previousPath;
            array_pop($this->includeStack);
        }
    }

    /**
     * Execute {const:name} -- look up name in the constants layer of context.
     *
     * Constants are stored under the reserved '_const' key in the context data.
     * They are injected by platform contributors and cannot be overridden by
     * template-level {def:} assignments.
     *
     * If the constant is not found, returns empty string (silent -- no exception).
     */
    private function executeConst(ConstNode $node, Context $context): string
    {
        $value = $context->get(['_const', $node->name]);

        if (null === $value) {
            return '';
        }

        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) || is_float($value) => (string) $value,
            is_string($value) => $value,
            $value instanceof Stringable => (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => '',
        };
    }

    /**
     * Execute widget node -- delegates to WidgetRegistry.
     *
     * Graceful degradation: returns '' when no registry, widget not
     * found, or the renderer returned null. Stringifies the renderer's
     * Stringable result at the concat boundary.
     */
    private function executeWidget(WidgetNode $node, Context $context): string
    {
        $result = $this->resolveWidgetRaw($node, $context);

        return null === $result ? '' : (string) $result;
    }

    /**
     * execute translation node. Delegates to Symfony
     * Translator with the resolved param values wrapped as
     * `%name%` keys (the catalog convention).
     *
     * Graceful degradation: when no translator is wired (Domain unit
     * tests, DTMPL-only consumers), returns the key as-is so the
     * template still renders.
     *
     * **A `locale` in the render data selects the catalogue.** Without
     * it `trans()` uses the translator's ambient locale, which is the REQUEST
     * locale -- correct for SSR, but wrong for anything rendered out of band. An
     * email composed in a message handler has no request: every recipient would
     * get the system default regardless of the language resolved for them. So a
     * render that knows who it is for says so, and one that does not keeps the
     * ambient behaviour unchanged.
     */
    private function executeTranslate(TranslateNode $node, Context $context): string
    {
        if (null === $this->translator) {
            return $node->key;
        }

        $params = [];
        foreach ($node->params as $name => $rawValue) {
            $resolved = $rawValue instanceof VariableSource
                ? $this->resolveSource($rawValue, $context)
                : $rawValue;
            // Symfony's catalog convention wraps placeholders in `%...%`.
            // Template author writes `count=3`; catalog message contains
            // `%count%`; we bridge here so neither side needs to think
            // about the other's syntax.
            $params['%' . $name . '%'] = is_scalar($resolved) || $resolved instanceof Stringable
                ? (string) $resolved
                : '';
        }

        return $this->translator->trans($node->key, $params, $node->domain, self::localeOf($context));
    }

    /**
     * Invoke a widget renderer and return its raw Stringable result
     * (or null) without stringification. Used by `resolveSource` so
     * `{def:foo=widget:...}` assignments preserve the object form;
     * `executeWidget` wraps this to cast to string for the
     * concatenation path.
     */
    private function resolveWidgetRaw(WidgetNode $node, Context $context): ?Stringable
    {
        if (null === $this->widgets || !$this->widgets->has($node->namespace, $node->widgetId)) {
            return null;
        }

        $params = $node->params;
        if (!$this->widgets->isExactMatch($node->namespace, $node->widgetId) && null !== $node->widgetId) {
            // Namespace-only fallback: pass the widgetId as '_id' so catch-all
            // renderers (e.g., MediaWidgetRenderer keyed 'media') can identify
            // the target object from {widget:media:uuid} tags.
            $params['_id'] = $node->widgetId;
        }

        $renderer = $this->widgets->get($node->namespace, $node->widgetId);

        $result = $renderer($context->getAllData(), $params);

        // A widget that returns a WidgetView is a view-model: it named a
        // theme partial + data instead of building HTML in PHP. Render that
        // partial here through the active theme's loader (the same machinery
        // {include} uses) and hand back a WidgetResult carrying BOTH the
        // rendered HTML *and* the view data. The concat path
        // (executeWidget) + `{var:x}` stringify the HTML (unchanged); a
        // `{def:x=widget:...}` bind additionally exposes the data so `{loop:x:i}`
        // and `{var:x.field}` reach into it -- what makes a data-widget usable.
        if ($result instanceof WidgetView) {
            return new WidgetResult($this->renderWidgetView($result, $context), $result->data);
        }

        return $result;
    }

    /**
     * Render a {@see WidgetView} -- load + compile its theme partial and
     * execute it with the view's data overlaid on the caller context (so
     * the partial keeps access to platform constants while the widget's
     * explicit data wins). Mirrors {@see executeInclude}: loader-resolved
     * path, circular-include guard, currentPath bookkeeping.
     */
    private function renderWidgetView(WidgetView $view, Context $context): string
    {
        if (null === $this->loader || null === $this->compiler) {
            throw new RuntimeException('Rendering a WidgetView requires a TemplateLoader and TemplateCompiler. Use DtmplEngine::withLoader().');
        }

        $resolvedPath = $this->loader->resolve($view->template, $this->currentPath);

        if (in_array($resolvedPath, $this->includeStack, true)) {
            throw new CircularIncludeException([...$this->includeStack, $resolvedPath]);
        }

        $source = $this->loader->load($view->template, $this->currentPath);
        $ast = $this->compiler->compile($source);
        $childContext = $context->createChild($view->data);

        $this->includeStack[] = $this->currentPath;
        $previousPath = $this->currentPath;
        $this->currentPath = $resolvedPath;

        try {
            return $this->executeTemplate($ast, $childContext);
        } finally {
            $this->currentPath = $previousPath;
            array_pop($this->includeStack);
        }
    }

    /**
     * Execute slot node -- outputs fill content, default body nodes, or the default string.
     *
     * Priority:
     *  1. Caller-supplied fill content (__slots map).
     *  2. Default body nodes parsed between {slot:name} and {endslot}.
     *  3. Inline $default string from {slot:name default=`...`}.
     */
    private function executeSlot(SlotNode $node, Context $context): string
    {
        $fillMap = $context->get(['__slots'], []);

        if (is_array($fillMap) && array_key_exists($node->name, $fillMap)) {
            return (string) $fillMap[$node->name];
        }

        // Block default: execute body nodes from {slot:name}...{endslot}
        if ([] !== $node->defaultBody) {
            return $this->renderChildren($node->defaultBody, $context);
        }

        return $node->default ?? '';
    }

    /**
     * Apply filter to value.
     */
    private function applyFilter(FilterNode $filter, mixed $value, Context $context): mixed
    {
        // Resolve filter arguments from context if needed
        $arguments = array_map(function ($arg) {
            // If argument is a variable reference, resolve it
            // For now, just use literal values
            return $arg;
        }, $filter->arguments);

        return $this->filters->apply($filter->name, $value, array_values($arguments));
    }

    /**
     * Convert value to string for output.
     */
    private function valueToString(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Fallback to JSON
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
