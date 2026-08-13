<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Parser;

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
use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Lexer\Token;
use CoolMS\Dtmpl\Lexer\TokenType;

/**
 * Parser.
 *
 * Builds an Abstract Syntax Tree (AST) from tokens.
 */
final class Parser
{
    /** @var Token[] */
    private array $tokens;

    private int $position = 0;

    /**
     * Parse tokens into AST.
     *
     * @param Token[] $tokens
     */
    public function parse(array $tokens): TemplateNode
    {
        $this->tokens = $tokens;
        $this->position = 0;
        $children = [];
        while (!$this->isEof()) {
            $children[] = $this->parseNode();
        }

        return new TemplateNode($children);
    }

    /**
     * Parse a single node.
     */
    private function parseNode(): Node
    {
        $token = $this->current();
        // Text content
        if ($token->is(TokenType::Text)) {
            return $this->parseText();
        }
        // Tag
        if ($token->is(TokenType::OpenBrace)) {
            return $this->parseTag();
        }

        throw new SyntaxException("Unexpected token: {$token->type->value}", $token->line, $token->column);
    }

    /**
     * Parse text node.
     */
    private function parseText(): TextNode
    {
        $token = $this->consume(TokenType::Text);

        return new TextNode($token->value, $token->line, $token->column);
    }

    /**
     * Parse tag: {var:...}, {loop:...}, {if:...}.
     */
    private function parseTag(): Node
    {
        $this->consume(TokenType::OpenBrace);
        $tagToken = $this->current();

        return match (true) {
            $tagToken->is(TokenType::Define) => $this->parseDefine(),
            $tagToken->is(TokenType::Var) => $this->parseVariable(),
            $tagToken->is(TokenType::Loop) => $this->parseLoop(),
            $tagToken->is(TokenType::If) => $this->parseConditional(false),
            $tagToken->is(TokenType::Unless) => $this->parseConditional(true),
            $tagToken->is(TokenType::Include) => $this->parseInclude(),
            $tagToken->is(TokenType::Slot) => $this->parseSlot(),
            $tagToken->is(TokenType::Fill) => $this->parseFill(),
            $tagToken->is(TokenType::Widget) => $this->parseWidget(),
            $tagToken->is(TokenType::Const) => $this->parseConst(),
            $tagToken->is(TokenType::Translate) => $this->parseTranslate(),
            $tagToken->is(TokenType::Else) => throw new SyntaxException('`{else}` is only valid inside `{if:}` or `{unless:}` blocks', $tagToken->line, $tagToken->column),
            default => throw new SyntaxException("Unknown tag type: $tagToken->value", $tagToken->line, $tagToken->column),
        };
    }

    /**
     * Parse translation tag: `{t:` `key` `}`, `{t:` `key` `:` `domain` `}`,
     * `{t:` `key` `:` `domain` ` placeholder=value ...}`.
     *
     * Key and domain are required-quoted (backtick String tokens), so
     * dotted keys like `subscription.denied` parse unambiguously
     * without colliding with DTMPL's dotted-path syntax for variables.
     * Optional key=value params after the domain feed `%name%`
     * placeholders into Symfony Translator at runtime.
     *
     * A BACKTICKED value is a literal (`name=`Ada``); a bare identifier or
     * dotted path is a CONTEXT LOOKUP (`href=unsubscribeHref`, `name=user.first`)
     * -- which is the only way a runtime value can reach a translated sentence.
     * `parseValue()` flattens an identifier to its own name, so it cannot be used
     * here: it made `name=who` interpolate the literal text "who" and left
     * {@see Executor::executeTranslate}'s VariableSource branch unreachable.
     */
    private function parseTranslate(): TranslateNode
    {
        $token = $this->consume(TokenType::Translate);
        $this->consume(TokenType::Colon);
        $key = $this->consume(TokenType::String)->value;

        $domain = null;
        if ($this->current()->is(TokenType::Colon)) {
            $this->consume(TokenType::Colon);
            $domain = $this->consume(TokenType::String)->value;
        }

        $params = [];
        while (!$this->current()->is(TokenType::CloseBrace) && !$this->isEof()) {
            $paramKey = $this->consume(TokenType::Identifier)->value;
            $this->consume(TokenType::Equals);
            $params[$paramKey] = $this->current()->is(TokenType::Identifier)
                ? new VariableSource(VariableSourceType::Path, $this->parsePath())
                : $this->parseValue();
        }

        $this->consume(TokenType::CloseBrace);

        return new TranslateNode($key, $domain, $params, line: $token->line, column: $token->column);
    }

    /**
     * Parse define: {def:name=source filters} -- assign without output.
     *
     * Source may be a context path, backtick literal, or widget:ns:id reference.
     */
    private function parseDefine(): DefineNode
    {
        $token = $this->consume(TokenType::Define);
        $this->consume(TokenType::Colon);
        // Parse variable path (someName)
        $path = $this->parsePath();

        // Assignment source
        $assignSource = null;
        if ($this->current()->is(TokenType::Equals)) {
            $this->consume(TokenType::Equals);
            $assignSource = $this->parseSource();
        }

        // Parse filters
        $filters = [];
        while (!$this->current()->is(TokenType::CloseBrace)) {
            if ($this->current()->is(TokenType::Identifier)) {
                $filters[] = $this->parseFilter();
            } else {
                $this->advance(); // Skip other tokens
            }
        }
        $this->consume(TokenType::CloseBrace);

        return new DefineNode($path, $filters, null, $assignSource, $token->line, $token->column);
    }

    /**
     * Parse variable: {var:user.name filter1 filter2} or {var:name=source filters}.
     *
     * When '=' follows the path, the RHS is a VariableSource (path, literal, or widget).
     * The resolved value is assigned into context AND returned as output.
     */
    private function parseVariable(): VariableNode
    {
        $token = $this->consume(TokenType::Var);
        $this->consume(TokenType::Colon);
        // Parse variable path (user.name.first)
        $path = $this->parsePath();

        // Assignment: {var:name=source}
        $assignSource = null;
        if ($this->current()->is(TokenType::Equals)) {
            $this->consume(TokenType::Equals);
            $assignSource = $this->parseSource();
        }

        // Parse filters
        $filters = [];
        $default = null;
        while (!$this->current()->is(TokenType::CloseBrace)) {
            // Check for default value: default=`value`
            if ($this->current()->is(TokenType::Identifier) && 'default' === $this->current()->value) {
                $this->advance();
                $this->consume(TokenType::Equals);
                $default = $this->parseValue();
                continue;
            }
            // Parse filter
            if ($this->current()->is(TokenType::Identifier)) {
                $filters[] = $this->parseFilter();
            } else {
                $this->advance(); // Skip other tokens
            }
        }
        $this->consume(TokenType::CloseBrace);

        return new VariableNode($path, $filters, $default, $assignSource, $token->line, $token->column);
    }

    /**
     * Parse loop: {loop:items} or {loop:items:item}.
     *
     * New syntax: {loop:path:alias} where alias is optional (defaults to "item")
     */
    private function parseLoop(): LoopNode
    {
        $token = $this->consume(TokenType::Loop);
        $this->consume(TokenType::Colon);
        // Parse the path (what we're iterating over)
        $path = $this->parsePath();
        $alias = 'item'; // Default alias
        // Check if there's a custom alias: {loop:items:product}
        if ($this->current()->is(TokenType::Colon)) {
            $this->consume(TokenType::Colon);
            $alias = $this->consume(TokenType::Identifier)->value;
        }
        // Parse loop options (odd, even, split)
        $odd = false;
        $even = false;
        $split = null;
        while (!$this->current()->is(TokenType::CloseBrace)) {
            if ($this->current()->is(TokenType::Identifier)) {
                $option = $this->current()->value;
                if ('odd' === $option) {
                    $odd = true;
                    $this->advance();
                } elseif ('even' === $option) {
                    $even = true;
                    $this->advance();
                } elseif ('split' === $option) {
                    $this->advance();
                    $this->consume(TokenType::Equals);
                    $split = $this->parseValue();
                } else {
                    $this->advance();
                }
            } else {
                $this->advance();
            }
        }
        $this->consume(TokenType::CloseBrace);
        // Parse loop body
        $body = [];
        while (!$this->isEof() && !$this->checkEndLoop()) {
            $body[] = $this->parseNode();
        }
        // Consume {endloop}
        $this->consume(TokenType::OpenBrace);
        $this->consume(TokenType::EndLoop);
        $this->consume(TokenType::CloseBrace);

        return new LoopNode($path, $body, $alias, $odd, $even, $split, $token->line, $token->column);
    }

    /**
     * Parse conditional: {if:condition}...{endif}.
     *
     * Supports equality comparison: {if:field.type=`fieldset`} or {if:a=b}.
     * When '=' follows the LHS path, the RHS is either a backtick string literal
     * or a variable path, and the condition becomes a ComparisonNode('eq').
     */
    private function parseConditional(bool $negate): ConditionalNode
    {
        $token = $this->consume($negate ? TokenType::Unless : TokenType::If);
        $this->consume(TokenType::Colon);
        // Parse condition (variable path)
        $path = $this->parsePath();
        $lhsNode = new VariableNode($path, [], null, null, $token->line, $token->column);

        // Comparison: `=`, `!=`, `>`, `<`, `>=`, `<=`. The operator
        // token is consumed here; the RHS form (string, number, bool,
        // path) is dispatched by `parseComparisonRhs`.
        $operator = match ($this->current()->type) {
            TokenType::Equals => 'eq',
            TokenType::Neq => 'ne',
            TokenType::Gt => 'gt',
            TokenType::Lt => 'lt',
            TokenType::Gte => 'ge',
            TokenType::Lte => 'le',
            default => null,
        };
        if (null !== $operator) {
            $this->advance();
            $rhs = $this->parseComparisonRhs();
            $condition = new ComparisonNode($lhsNode, $operator, $rhs, $token->line, $token->column);
        } else {
            $condition = $lhsNode;
        }

        $this->consume(TokenType::CloseBrace);
        // Parse then body
        $thenBody = [];
        $elseBody = [];
        while (!$this->isEof() && !$this->checkEndIf($negate) && !$this->checkElse()) {
            $thenBody[] = $this->parseNode();
        }
        // Optional {else} branch
        if ($this->checkElse()) {
            $this->consume(TokenType::OpenBrace);
            $this->consume(TokenType::Else);
            $this->consume(TokenType::CloseBrace);
            while (!$this->isEof() && !$this->checkEndIf($negate)) {
                $elseBody[] = $this->parseNode();
            }
        }
        // Consume {endif} or {endunless}
        $this->consume(TokenType::OpenBrace);
        $this->consume($negate ? TokenType::EndUnless : TokenType::EndIf);
        $this->consume(TokenType::CloseBrace);

        return new ConditionalNode($condition, $thenBody, $elseBody, $negate, $token->line, $token->column);
    }

    /**
     * Parse the RHS of a comparison: scalar literal or path.
     *
     * Accepts backtick string, number, boolean, or identifier path.
     * Strings stay PHP strings; numbers become int or float; booleans
     * become PHP bool; paths become a VariableNode resolved at runtime.
     */
    private function parseComparisonRhs(): string|int|float|bool|VariableNode
    {
        $token = $this->current();

        if ($token->is(TokenType::String)) {
            $this->advance();

            return $token->value;
        }
        if ($token->is(TokenType::Number)) {
            $this->advance();

            return str_contains($token->value, '.')
                ? (float) $token->value
                : (int) $token->value;
        }
        if ($token->is(TokenType::Boolean)) {
            $this->advance();

            return 'true' === $token->value;
        }
        if ($token->is(TokenType::Identifier)) {
            $rhsPath = $this->parsePath();

            return new VariableNode($rhsPath, [], null, null, $token->line, $token->column);
        }

        throw new SyntaxException('Expected comparison RHS: backtick string, number, boolean, or path identifier', $token->line, $token->column);
    }

    /**
     * Parse include: {include:`path/to/partial`} or {include:`path`}...{endinclude}.
     */
    private function parseInclude(): IncludeNode
    {
        $token = $this->consume(TokenType::Include);
        $this->consume(TokenType::Colon);

        if (!$this->current()->is(TokenType::String)) {
            throw new SyntaxException('Include path must be a backtick string, e.g. {include:`partials/card`}', $this->current()->line, $this->current()->column);
        }
        $templatePath = $this->consume(TokenType::String)->value;

        // Parse optional key=value params before CloseBrace or fill/endinclude blocks
        $params = [];
        while (
            !$this->current()->is(TokenType::CloseBrace)
            && !$this->checkFill()
            && !$this->checkEndInclude()
            && !$this->isEof()
        ) {
            if ($this->current()->is(TokenType::Identifier)) {
                $key = $this->consume(TokenType::Identifier)->value;
                $this->consume(TokenType::Equals);
                $params[$key] = $this->parseValue();
            } else {
                break;
            }
        }

        $this->consume(TokenType::CloseBrace);

        // If not followed by fill blocks or {endinclude}, it's a simple include
        if (!$this->checkEndInclude() && !$this->checkFill()) {
            return new IncludeNode($templatePath, [], $params, $token->line, $token->column);
        }

        // Include with fills: parse {fill:name}...{endfill} blocks until {endinclude}
        $fills = [];
        while (!$this->isEof() && !$this->checkEndInclude()) {
            // Skip whitespace-only text between fill blocks
            if ($this->current()->is(TokenType::Text)) {
                if ('' === trim($this->current()->value)) {
                    $this->advance();
                    continue;
                }

                throw new SyntaxException('Only {fill:...}{endfill} blocks are allowed inside {include}...{endinclude}', $this->current()->line, $this->current()->column);
            }

            if ($this->current()->is(TokenType::OpenBrace)) {
                $fills[] = $this->parseIncludeFill();
            } else {
                throw new SyntaxException('Unexpected token inside {include}...{endinclude}', $this->current()->line, $this->current()->column);
            }
        }

        $this->consume(TokenType::OpenBrace);
        $this->consume(TokenType::EndInclude);
        $this->consume(TokenType::CloseBrace);

        return new IncludeNode($templatePath, $fills, $params, $token->line, $token->column);
    }

    /**
     * Parse a single {fill:name}...{endfill} block inside an include.
     */
    private function parseIncludeFill(): FillNode
    {
        $this->consume(TokenType::OpenBrace);
        $fillToken = $this->consume(TokenType::Fill);
        $this->consume(TokenType::Colon);
        $name = $this->consume(TokenType::Identifier)->value;
        $this->consume(TokenType::CloseBrace);

        $body = [];
        while (!$this->isEof() && !$this->checkEndFill()) {
            $body[] = $this->parseNode();
        }

        $this->consume(TokenType::OpenBrace);
        $this->consume(TokenType::EndFill);
        $this->consume(TokenType::CloseBrace);

        return new FillNode($name, $body, $fillToken->line, $fillToken->column);
    }

    /**
     * Parse slot declaration.
     *
     * Syntax variants:
     *   {slot:name}                       -- self-closing, empty default
     *   {slot:name default=`text`}        -- self-closing, inline string default
     *   {slot:name}...body...{endslot}    -- block default (endslot required when body present)
     *   {slot:name}{endslot}              -- explicit empty close (treated as self-closing)
     *
     * {endslot} is only required when there is actual body content between the opening
     * {slot:} tag and the close. Without body content, {endslot} is accepted but not required.
     */
    private function parseSlot(): SlotNode
    {
        $token = $this->consume(TokenType::Slot);
        $this->consume(TokenType::Colon);
        $name = $this->consume(TokenType::Identifier)->value;

        $default = null;
        while (!$this->current()->is(TokenType::CloseBrace)) {
            if ($this->current()->is(TokenType::Identifier) && 'default' === $this->current()->value) {
                $this->advance();
                $this->consume(TokenType::Equals);
                $default = (string) $this->parseValue();
                continue;
            }
            $this->advance();
        }

        $this->consume(TokenType::CloseBrace);

        // Case 1: explicit {slot:name}{endslot} -- consume endslot, treat as self-closing
        if ($this->checkEndSlot()) {
            $this->consume(TokenType::OpenBrace);
            $this->consume(TokenType::EndSlot);
            $this->consume(TokenType::CloseBrace);

            return new SlotNode($name, $default, [], $token->line, $token->column);
        }

        // Case 3: block default -- a matching {endslot} exists before any parent-closing tag
        if (!$this->isEof() && $this->hasEndSlotBeforeParentClose()) {
            $defaultBody = [];
            // @phpstan-ignore booleanNot.alwaysTrue, booleanNot.alwaysTrue, booleanAnd.alwaysTrue
            while (!$this->isEof() && !$this->checkEndSlot()) {
                $defaultBody[] = $this->parseNode();
            }
            // @phpstan-ignore deadCode.unreachable
            if ($this->checkEndSlot()) {
                $this->consume(TokenType::OpenBrace);
                $this->consume(TokenType::EndSlot);
                $this->consume(TokenType::CloseBrace);
            }

            return new SlotNode($name, $default, $defaultBody, $token->line, $token->column);
        }

        // Case 2: self-closing -- no matching {endslot} before a parent closer or EOF
        return new SlotNode($name, $default, [], $token->line, $token->column);
    }

    /**
     * Scan ahead to determine if a matching {endslot} exists before any parent-closing tag.
     *
     * Tracks nested {slot:...}/{endslot} pairs so that only the slot's OWN closing tag
     * is detected (not the closing tag of a nested slot).
     *
     * Returns false when EOF or a parent-level closer is encountered first:
     *   {endfill}, {endinclude}, {endloop}, {endif}, {endunless}
     */
    private function hasEndSlotBeforeParentClose(): bool
    {
        $depth = 0;
        $offset = 0;

        while (true) {
            $t = $this->peek($offset);
            if (null === $t || $t->is(TokenType::Eof)) {
                return false;
            }
            if (!$t->is(TokenType::OpenBrace)) {
                ++$offset;
                continue;
            }
            $next = $this->peek($offset + 1);
            if (null === $next) {
                return false;
            }
            if ($next->is(TokenType::EndSlot)) {
                if (0 === $depth) {
                    return true;
                }
                --$depth;
                $offset += 3; // skip past {endslot}
                continue;
            }
            if ($next->is(TokenType::Slot)) {
                ++$depth;
                $offset += 2;
                continue;
            }
            if ($next->is(TokenType::EndFill)
                || $next->is(TokenType::EndInclude)
                || $next->is(TokenType::EndLoop)
                || $next->is(TokenType::EndIf)
                || $next->is(TokenType::EndUnless)
            ) {
                return false;
            }
            $offset += 2;
        }
    }

    /**
     * Parse {const:name} tag.
     *
     * Syntax: {const:identifier}
     * The identifier is the constant name -- resolved at runtime from the
     * constants context injected by the platform.
     */
    private function parseConst(): ConstNode
    {
        $token = $this->current(); // the 'const' keyword token
        $this->consume(TokenType::Const);
        $this->consume(TokenType::Colon);

        $name = $this->consume(TokenType::Identifier)->value;
        $this->consume(TokenType::CloseBrace);

        return new ConstNode($name, line: $token->line, column: $token->column);
    }

    /**
     * Top-level {fill:...} is a syntax error -- fills may only appear inside {include}...{endinclude}.
     */
    private function parseFill(): never
    {
        $token = $this->current();

        throw new SyntaxException('{fill:...} can only appear inside {include:...}{endinclude}', $token->line, $token->column);
    }

    /**
     * Parse assignment source: context path | literal | widget:ns:id.
     *
     * Called after '=' in {var:name=...} or {def:name=...}.
     *   widget:form:login  -- VariableSourceType::Widget, WidgetNode
     *   `literal`          -- VariableSourceType::Literal, scalar
     *   page.title         -- VariableSourceType::Path, string[]
     */
    private function parseSource(): VariableSource
    {
        // Widget reference: widget keyword followed by :ns[:id].
        // Inside a tag body the lexer emits "widget" as Identifier
        // (keyword tokenization only fires at tag-start), so accept
        // both the Widget token and the literal `widget` identifier.
        if ($this->isWidgetSourceStart()) {
            $widget = $this->parseWidgetSource();

            return new VariableSource(VariableSourceType::Widget, $widget);
        }

        // Array literal: [`a`, `b`] or [key: `v`] or [[nested]]
        if ($this->current()->is(TokenType::OpenBracket)) {
            $array = $this->parseArray();

            return new VariableSource(VariableSourceType::Literal, $array);
        }

        // Backtick string / number / boolean literal
        if ($this->current()->type->isLiteral()) {
            $literal = $this->parseValue();

            return new VariableSource(VariableSourceType::Literal, $literal);
        }

        // Context path: identifier[.identifier]*
        if ($this->current()->is(TokenType::Identifier)) {
            $path = $this->parsePath();

            return new VariableSource(VariableSourceType::Path, $path);
        }

        throw new SyntaxException('Expected assignment source: widget:ns:id, `literal`, or path.identifier', $this->current()->line, $this->current()->column);
    }

    /**
     * Parse a widget reference as a source (widget:ns:id or widget:ns).
     * Used in assignment context -- consumes the same `key=value`
     * parameter list as `parseWidget` but does NOT consume the
     * CloseBrace, so the surrounding `parseDefine` / `parseVariable`
     * can read trailing filters off the same tag.
     */
    private function parseWidgetSource(): WidgetNode
    {
        [$token, $namespace, $widgetId] = $this->parseWidgetNamespaceAndId();

        $params = [];
        while ($this->current()->is(TokenType::Identifier)
            && $this->peek(1)?->is(TokenType::Equals)
        ) {
            $key = $this->consume(TokenType::Identifier)->value;
            $this->consume(TokenType::Equals);
            $params[$key] = $this->parseValue();
        }

        return new WidgetNode($namespace, $widgetId, $params, line: $token->line, column: $token->column);
    }

    /**
     * Parse widget tag: {widget:ns:id} or {widget:ns}.
     *
     * {widget:form:login}              -- namespace=form, widgetId=login
     * {widget:nav:breadcrumbs}         -- namespace=nav,  widgetId=breadcrumbs
     * {widget:breadcrumbs}             -- namespace=breadcrumbs, widgetId=null
     * {widget:form:`a.dotted.id`}      -- namespace=form, widgetId=a.dotted.id (quoted)
     */
    private function parseWidget(): WidgetNode
    {
        [$token, $namespace, $widgetId] = $this->parseWidgetNamespaceAndId();

        // Parse optional key=value params -- reuses existing parseValue()
        $params = [];
        while (!$this->current()->is(TokenType::CloseBrace) && !$this->isEof()) {
            $key = $this->consume(TokenType::Identifier)->value;
            $this->consume(TokenType::Equals);
            $params[$key] = $this->parseValue();
        }

        $this->consume(TokenType::CloseBrace);

        return new WidgetNode($namespace, $widgetId, $params, line: $token->line, column: $token->column);
    }

    /**
     * Consumes "widget:namespace" and optionally ":widgetId" tokens.
     * Shared by parseWidgetSource() and parseWidget(). Accepts the
     * `widget` token in either keyword form (Widget) or identifier
     * form (`Identifier` with value `widget`), since the lexer only
     * emits the keyword type at tag-start.
     *
     * The widgetId has two forms:
     *   - bare segments joined by colons -- `form:navi:navi_node` (each
     *     segment is an Identifier, dashes allowed for UUID-form ids);
     *   - a single backtick string literal taken verbatim --
     *     {widget:form:`identity.verify_email_otp`}. The quoted form is the
     *     escape hatch for ids the bare-segment grammar can't express
     *     (notably dots, which lex as a DOT token); it mirrors how
     *     {include:`path`} and filter args accept arbitrary strings.
     *     A quoted id is a COMPLETE id -- it cannot be followed by more
     *     `:segment` parts.
     *
     * @return array{Token, string, string|null}
     */
    private function parseWidgetNamespaceAndId(): array
    {
        $token = $this->consumeWidgetKeyword();
        $this->consume(TokenType::Colon);
        $namespace = $this->consume(TokenType::Identifier)->value;

        // Quoted-id escape hatch: `:`backtick-literal`` is the whole id, verbatim.
        if ($this->peek(0)?->is(TokenType::Colon) && $this->peek(1)?->is(TokenType::String)) {
            $this->consume(TokenType::Colon);

            return [$token, $namespace, $this->consume(TokenType::String)->value];
        }

        $segments = [];
        while ($this->peek(0)?->is(TokenType::Colon) && $this->peek(1)?->is(TokenType::Identifier)) {
            $this->consume(TokenType::Colon);
            $segments[] = $this->consume(TokenType::Identifier)->value;
        }

        $widgetId = [] !== $segments ? implode(':', $segments) : null;

        return [$token, $namespace, $widgetId];
    }

    /**
     * Parse variable path: user.name.first.
     *
     * @return string[]
     */
    private function parsePath(): array
    {
        $path = [];
        // First identifier
        if ($this->current()->is(TokenType::Identifier)) {
            $path[] = $this->consume(TokenType::Identifier)->value;
        }
        // Additional segments with dot notation
        while ($this->current()->is(TokenType::Dot)) {
            $this->consume(TokenType::Dot);
            if ($this->current()->is(TokenType::Identifier)) {
                $path[] = $this->consume(TokenType::Identifier)->value;
            }
        }

        return $path;
    }

    /**
     * Parse filter: php.strtoupper, currency:`USD`.
     */
    private function parseFilter(): FilterNode
    {
        $token = $this->consume(TokenType::Identifier);
        $filterName = $token->value;
        // Check for php. prefix
        if ($this->current()->is(TokenType::Dot)) {
            $this->consume(TokenType::Dot);
            $filterName .= '.' . $this->consume(TokenType::Identifier)->value;
        }
        // Parse arguments
        $arguments = [];
        if ($this->current()->is(TokenType::Colon)) {
            $this->consume(TokenType::Colon);
            // Parse argument list
            do {
                $arguments[] = $this->parseValue();
                if ($this->current()->is(TokenType::Comma)) {
                    $this->consume(TokenType::Comma);
                } else {
                    break;
                }
            } while (!$this->current()->is(TokenType::CloseBrace));
        }

        return new FilterNode($filterName, $arguments, $token->line, $token->column);
    }

    /**
     * Parse value (string, number, boolean).
     */
    private function parseValue(): mixed
    {
        $token = $this->current();
        // String literal
        if ($token->is(TokenType::String)) {
            $this->advance();

            return $token->value;
        }
        // Number literal
        if ($token->is(TokenType::Number)) {
            $this->advance();

            return str_contains($token->value, '.')
                ? (float) $token->value
                : (int) $token->value;
        }
        // Boolean literal
        if ($token->is(TokenType::Boolean)) {
            $this->advance();

            return 'true' === $token->value;
        }
        // Array literal: [value1, value2] or [key: value, key2: value2]
        if ($token->is(TokenType::OpenBracket)) {
            return $this->parseArray();
        }
        // Identifier (variable reference in array)
        if ($token->is(TokenType::Identifier)) {
            $identifier = $token->value;
            $this->advance();

            return $identifier;
        }

        throw new SyntaxException("Expected value (string, number, boolean, array, or identifier), got {$token->type->value}", $token->line, $token->column);
    }

    /**
     * Parse array: [value1, value2] or [key: value, key2: value2].
     *
     * @return array<int|string, mixed>
     */
    private function parseArray(): array
    {
        $this->consume(TokenType::OpenBracket);
        $array = [];
        while (!$this->current()->is(TokenType::CloseBracket) && !$this->isEof()) {
            // Peek ahead to determine if this is key:value or just value
            $isKeyValue = false;
            if (($this->current()->is(TokenType::String) || $this->current()->is(TokenType::Identifier))
                && $this->peek(1)?->is(TokenType::Colon)) {
                $isKeyValue = true;
            }
            if ($isKeyValue) {
                // Parse key: value
                $key = $this->current()->value;
                $this->advance();
                $this->consume(TokenType::Colon);
                $value = $this->parseValue();
                $array[$key] = $value;
            } else {
                // Parse just value
                $value = $this->parseValue();
                $array[] = $value;
            }
            // Check for comma
            if ($this->current()->is(TokenType::Comma)) {
                $this->advance();
                // Allow trailing comma
                // @phpstan-ignore if.alwaysFalse
                if ($this->current()->is(TokenType::CloseBracket)) {
                    break;
                }
            } elseif (!$this->current()->is(TokenType::CloseBracket)) {
                throw new SyntaxException("Expected ',' or ']' in array, got {$this->current()->type->value}", $this->current()->line, $this->current()->column);
            }
        }
        $this->consume(TokenType::CloseBracket);

        return $array;
    }

    /**
     * Check if next tokens are {endloop}.
     */
    private function checkEndLoop(): bool
    {
        return true === $this->peek(0)?->is(TokenType::OpenBrace)
            && true === $this->peek(1)?->is(TokenType::EndLoop);
    }

    /**
     * Check if next tokens are {endif} or {endunless}.
     */
    private function checkEndIf(bool $negate): bool
    {
        $endType = $negate ? TokenType::EndUnless : TokenType::EndIf;

        return true === $this->peek(0)?->is(TokenType::OpenBrace)
            && true === $this->peek(1)?->is($endType);
    }

    /**
     * Check if next tokens are `{else}`.
     */
    private function checkElse(): bool
    {
        return true === $this->peek(0)?->is(TokenType::OpenBrace)
            && true === $this->peek(1)?->is(TokenType::Else);
    }

    /**
     * Check if next tokens are {endinclude}.
     */
    private function checkEndInclude(): bool
    {
        return true === $this->peek(0)?->is(TokenType::OpenBrace)
            && true === $this->peek(1)?->is(TokenType::EndInclude);
    }

    /**
     * Check if next tokens are {endfill}.
     */
    private function checkEndFill(): bool
    {
        return true === $this->peek(0)?->is(TokenType::OpenBrace)
            && true === $this->peek(1)?->is(TokenType::EndFill);
    }

    /**
     * Check if next tokens are {endslot}.
     */
    private function checkEndSlot(): bool
    {
        return true === $this->peek(0)?->is(TokenType::OpenBrace)
            && true === $this->peek(1)?->is(TokenType::EndSlot);
    }

    /**
     * Check if the next non-whitespace token sequence is {fill:...}.
     */
    private function checkFill(): bool
    {
        $offset = 0;
        while (true) {
            $t = $this->peek($offset);
            if (null === $t) {
                return false;
            }
            // Skip whitespace-only text tokens
            if ($t->is(TokenType::Text) && '' === trim($t->value)) {
                ++$offset;
                continue;
            }

            return $t->is(TokenType::OpenBrace)
                && true === $this->peek($offset + 1)?->is(TokenType::Fill);
        }
    }

    /**
     * Get current token.
     */
    private function current(): Token
    {
        return $this->tokens[$this->position] ?? $this->tokens[count($this->tokens) - 1];
    }

    /**
     * Peek at token at offset.
     */
    private function peek(int $offset): ?Token
    {
        $pos = $this->position + $offset;

        return $this->tokens[$pos] ?? null;
    }

    /**
     * Advance to next token.
     */
    private function advance(): Token
    {
        $token = $this->current();
        if (!$this->isEof()) {
            ++$this->position;
        }

        return $token;
    }

    /**
     * Consume expected token type.
     */
    private function consume(TokenType $expected): Token
    {
        $token = $this->current();
        if (!$token->is($expected)) {
            throw new SyntaxException("Expected $expected->value, got {$token->type->value}", $token->line, $token->column);
        }
        $this->advance();

        return $token;
    }

    /**
     * True when the current token starts a widget source -- either
     * the Widget keyword token (at tag-start) or the literal
     * `widget` Identifier (inside a tag body, e.g.,
     * `{def:foo=widget:ns:id}`).
     */
    private function isWidgetSourceStart(): bool
    {
        $token = $this->current();

        return $token->is(TokenType::Widget)
            || ($token->is(TokenType::Identifier) && 'widget' === $token->value);
    }

    /**
     * Consume the `widget` keyword in either keyword or identifier
     * tokenization, returning the consumed token.
     */
    private function consumeWidgetKeyword(): Token
    {
        $token = $this->current();
        if (!$this->isWidgetSourceStart()) {
            throw new SyntaxException("Expected `widget`, got {$token->type->value}", $token->line, $token->column);
        }
        $this->advance();

        return $token;
    }

    /**
     * Check if at end of tokens.
     */
    private function isEof(): bool
    {
        return $this->current()->is(TokenType::Eof);
    }
}
