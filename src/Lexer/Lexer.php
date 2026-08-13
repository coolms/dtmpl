<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Lexer;

use CoolMS\Dtmpl\Exception\SyntaxException;

/**
 * Lexer (Tokenizer).
 *
 * Converts template source code into a stream of tokens.
 * Supports dTMPL syntax: {var:name filter1 filter2}
 */
final class Lexer
{
    private const array KEYWORDS = [
        'var' => TokenType::Var,
        'loop' => TokenType::Loop,
        'endloop' => TokenType::EndLoop,
        'item' => TokenType::Item,
        'if' => TokenType::If,
        'endif' => TokenType::EndIf,
        'ifno' => TokenType::Unless,
        'unless' => TokenType::Unless,
        'endno' => TokenType::EndUnless,
        'endunless' => TokenType::EndUnless,
        'else' => TokenType::Else,
        'def' => TokenType::Define,
        'define' => TokenType::Define,
        'include' => TokenType::Include,
        'endinclude' => TokenType::EndInclude,
        'slot' => TokenType::Slot,
        'endslot' => TokenType::EndSlot,
        'fill' => TokenType::Fill,
        'endfill' => TokenType::EndFill,
        'widget' => TokenType::Widget,
        'const' => TokenType::Const,
        't' => TokenType::Translate,
    ];

    private const array BOOLEAN_LITERALS = [
        'true' => true,
        'false' => false,
    ];

    private string $source;
    private int $length;
    private int $position = 0;
    private int $line = 1;
    private int $column = 1;

    /** @var Token[] */
    private array $tokens = [];

    public function __construct(
        private readonly string $encoding = 'UTF-8',
    ) {
    }

    /**
     * Tokenize template source.
     *
     * @return Token[]
     */
    public function tokenize(string $source): array
    {
        $this->source = $source;
        $this->length = mb_strlen($source, $this->encoding);
        $this->position = 0;
        $this->line = 1;
        $this->column = 1;
        $this->tokens = [];

        while (!$this->isEof()) {
            // `{raw}...{endraw}` is a verbatim block: its interior is
            // emitted literally and never tokenized, so `<script>`/`<style>`
            // blocks, JSON, and DTMPL code samples survive intact. Checked
            // first so the `{raw}` marker never reaches the tag scanner.
            //
            // Otherwise `{` enters tag mode only when followed by a
            // registered keyword -- every other `{...` (whitespace, digits,
            // symbols, unrelated words) stays literal so code samples / JSON
            // / set notation in template content survive the lex pass.
            // `{{` is the escape sequence and is handled by scanText.
            if ($this->isRawBlockStart()) {
                $this->scanRawBlock();
            } elseif ($this->isTagStart()) {
                $this->scanTag();
            } else {
                $this->scanText();
            }
        }

        $this->tokens[] = new Token(TokenType::Eof, '', $this->position, $this->line, $this->column);

        return $this->tokens;
    }

    /**
     * True iff the cursor sits on a `{` followed (without an escape)
     * by a registered keyword identifier. This is the only entry point
     * to tag mode; the resemblance / literal cases are handled inside
     * scanText.
     */
    private function isTagStart(): bool
    {
        if ('{' !== $this->peek() || '{' === $this->peek(1)) {
            return false;
        }
        $candidate = $this->peekKeywordCandidate();

        return '' !== $candidate && KeywordRegistry::isKeyword($candidate);
    }

    /**
     * True iff the cursor sits on a literal `{raw}` (the exact form,
     * no arguments). Opens a verbatim block whose interior bypasses
     * tokenization -- see {@see scanRawBlock()}. Checked before
     * {@see isTagStart()} so the marker never reaches the tag scanner.
     *
     * The exact-form requirement (`}` directly after `raw`) keeps a
     * stray `{rawish...` or `{raw something}` out of verbatim mode -- those
     * fall through to the ordinary literal-text path.
     */
    private function isRawBlockStart(): bool
    {
        if ('{' !== $this->peek() || '{' === $this->peek(1)) {
            return false;
        }

        // `{raw}` -- `{`=0, `raw`=1..3, `}`=4.
        return 'raw' === $this->peekKeywordCandidate() && '}' === $this->peek(4);
    }

    /**
     * Scan a `{raw}...{endraw}` verbatim block. Everything between the
     * markers is emitted as a single literal {@see TokenType::Text}
     * token -- the interior is never tokenized, so braces, DTMPL keyword
     * lookalikes, `<script>`/`<style>` blocks, and DTMPL code samples
     * all survive untouched. The FIRST `{endraw}` terminates; an
     * unterminated block is a hard error.
     *
     * Reached only via {@see isRawBlockStart()}, which guarantees the
     * cursor sits on a literal `{raw}`.
     */
    private function scanRawBlock(): void
    {
        // Consume the opening `{raw}` (5 chars: `{ r a w }`).
        for ($i = 0; $i < 5; ++$i) {
            $this->advance();
        }

        $start = $this->position;
        $startLine = $this->line;
        $startColumn = $this->column;
        $text = '';

        while (!$this->isEof()) {
            // Terminator: a literal `{endraw}` -- `{`=cursor, `endraw`=1..6,
            // `}`=7. A near-miss like `{endrawer}` or `{endraw ` (no `}`)
            // is not a terminator and stays part of the verbatim body.
            if ('{' === $this->peek()
                && 'endraw' === $this->peekKeywordCandidate()
                && '}' === $this->peek(7)
            ) {
                if ('' !== $text) {
                    $this->tokens[] = new Token(TokenType::Text, $text, $start, $startLine, $startColumn);
                }
                // Consume `{endraw}` (8 chars).
                for ($i = 0; $i < 8; ++$i) {
                    $this->advance();
                }

                return;
            }

            $text .= $this->advance();
        }

        throw new SyntaxException('Unclosed `{raw}` block -- expected a matching `{endraw}`.', $startLine, $startColumn);
    }

    /**
     * Look-ahead `[a-zA-Z]+` immediately after the `{` at the cursor.
     * Returns '' when the next char isn't a letter (e.g. `{`, `{ `,
     * `{42`, `{/`). No state change.
     */
    private function peekKeywordCandidate(): string
    {
        $candidate = '';
        $offset = 1;
        while (true) {
            $char = $this->peek($offset);
            if ('' === $char || !$this->isAlpha($char)) {
                break;
            }
            $candidate .= $char;
            ++$offset;
        }

        return $candidate;
    }

    /**
     * Scan text content (outside of tags). Owns the brace-escape and
     * literal-brace cases so the dispatch loop only has to ask "is the
     * cursor on a real tag?".
     *
     * Behaviour at each `{`:
     *   • `{{` -- escape, emit a single literal `{`
     *   • `{` + registered-keyword → break so the dispatcher calls
     *     scanTag (already determined by isTagStart, but re-checked
     *     here defensively for the run-on case after a literal `{`)
     *   • `{` + word that resembles a keyword (1-edit / anagram) →
     *     SyntaxException with "Did you mean ..." hint. A near-miss is
     *     committed intent to write a tag, so a silent literal would
     *     mask the typo
     *   • `{` + anything else → literal `{`, no error (code samples,
     *     JSON, set notation, prose all survive)
     *
     * `}` outside tag mode is always literal -- the closing brace
     * carries no meaning without a preceding open-tag, so prose like
     * "the } character" never produces an error.
     */
    private function scanText(): void
    {
        $start = $this->position;
        $startLine = $this->line;
        $startColumn = $this->column;
        $text = '';

        while (!$this->isEof()) {
            $ch = $this->peek();

            // {{ escape
            if ('{' === $ch && '{' === $this->peek(1)) {
                $text .= $this->advance();
                $this->advance();
                continue;
            }

            // }} escape
            if ('}' === $ch && '}' === $this->peek(1)) {
                $text .= $this->advance();
                $this->advance();
                continue;
            }

            if ('{' === $ch) {
                // A `{raw}` verbatim block ends the current text run --
                // hand back to the dispatcher, which calls scanRawBlock().
                // (Checked here too, not just in tokenize(), because a
                // text run can swallow right up to a `{raw}` mid-stream.)
                if ($this->isRawBlockStart()) {
                    break;
                }
                $candidate = $this->peekKeywordCandidate();
                if ('' !== $candidate && KeywordRegistry::isKeyword($candidate)) {
                    break;
                }
                if ('' !== $candidate) {
                    $hint = KeywordRegistry::findResemblance($candidate);
                    if (null !== $hint) {
                        throw new SyntaxException(sprintf('Unknown DTMPL keyword `%s`. Did you mean `%s`?', $candidate, $hint), $this->line, $this->column);
                    }
                }
                // Far from any keyword -- `{` is literal text.
                $text .= $this->advance();
                continue;
            }

            // Standalone `}` -- literal, never error.
            $text .= $this->advance();
        }

        if ('' !== $text) {
            $this->tokens[] = new Token(TokenType::Text, $text, $start, $startLine, $startColumn);
        }
    }

    /**
     * Scan a template tag: {var:name filter1 filter2}.
     *
     * Strict-whitespace rule (F.lex-strict): no whitespace between
     * `{`-keyword, keyword-`:` and `:`-firstArg. The keyword separators
     * must follow each other directly so `{var :name}` and `{var: x}`
     * raise a clear error instead of silently parsing. After the first
     * argument identifier whitespace is fine -- filter chains and arg
     * lists rely on it (`{var:name uppercase trim}`).
     */
    private function scanTag(): void
    {
        // Opening brace
        $this->addToken(TokenType::OpenBrace, $this->advance());

        // No whitespace between `{` and the keyword. Reached only via
        // `isTagStart()` so the next char is guaranteed to be a letter
        // -- kept as a defensive assert in case of refactor drift.
        if ($this->isWhitespace($this->peek())) {
            throw new SyntaxException('Unexpected whitespace after `{`', $this->line, $this->column);
        }

        $identifier = $this->scanIdentifier();
        if (isset(self::KEYWORDS[$identifier])) {
            $this->tokens[count($this->tokens) - 1] = new Token(
                self::KEYWORDS[$identifier],
                $identifier,
                $this->tokens[count($this->tokens) - 1]->position,
                $this->tokens[count($this->tokens) - 1]->line,
                $this->tokens[count($this->tokens) - 1]->column,
            );
        }

        // No whitespace between keyword and its `:` separator. Trailing
        // whitespace before `}` (e.g. `{endif }`) is fine -- only the
        // keyword/`:` adjacency is enforced. Peek through any spaces;
        // if they lead to `:`, that's the offending separation.
        if ($this->isWhitespace($this->peek())) {
            $offset = 0;
            while (true) {
                $next = $this->peek($offset);
                if ('' === $next || !$this->isWhitespace($next)) {
                    break;
                }
                ++$offset;
            }
            if (':' === $this->peek($offset)) {
                throw new SyntaxException(sprintf('Unexpected whitespace between keyword `%s` and `:`', $identifier), $this->line, $this->column);
            }
        }

        // Optional `:` separator + strict no-whitespace before the
        // first argument.
        if (':' === $this->peek()) {
            $this->addToken(TokenType::Colon, $this->advance());
            if ($this->isWhitespace($this->peek())) {
                throw new SyntaxException('Unexpected whitespace after `:`', $this->line, $this->column);
            }
        }

        // Scan tag contents until closing brace
        $depth = 1;
        while (!$this->isEof() && $depth > 0) {
            $this->skipWhitespace();

            $char = $this->peek();

            if ('{' === $char) {
                $this->addToken(TokenType::OpenBrace, $this->advance());
                ++$depth;
            } elseif ('}' === $char) {
                --$depth;
                $this->addToken(TokenType::CloseBrace, $this->advance());
            } elseif (':' === $char) {
                $this->addToken(TokenType::Colon, $this->advance());
            } elseif ('[' === $char) {
                $this->addToken(TokenType::OpenBracket, $this->advance());
            } elseif (']' === $char) {
                $this->addToken(TokenType::CloseBracket, $this->advance());
            } elseif ('|' === $char) {
                $this->addToken(TokenType::Pipe, $this->advance());
            } elseif (',' === $char) {
                $this->addToken(TokenType::Comma, $this->advance());
            } elseif ('.' === $char) {
                $this->addToken(TokenType::Dot, $this->advance());
            } elseif ('!' === $char) {
                // `!` is only valid as the first char of `!=`. A bare
                // `!` is not a DTMPL operator and gets a targeted error.
                if ('=' !== $this->peek(1)) {
                    throw new SyntaxException('Unexpected `!` -- expected `!=` (use `!=` for not-equal comparison).', $this->line, $this->column);
                }
                $this->advance();
                $this->advance();
                $this->addToken(TokenType::Neq, '!=');
            } elseif ('>' === $char) {
                if ('=' === $this->peek(1)) {
                    $this->advance();
                    $this->advance();
                    $this->addToken(TokenType::Gte, '>=');
                } else {
                    $this->advance();
                    $this->addToken(TokenType::Gt, '>');
                }
            } elseif ('<' === $char) {
                if ('=' === $this->peek(1)) {
                    $this->advance();
                    $this->advance();
                    $this->addToken(TokenType::Lte, '<=');
                } else {
                    $this->advance();
                    $this->addToken(TokenType::Lt, '<');
                }
            } elseif ('=' === $char) {
                $this->addToken(TokenType::Equals, $this->advance());
            } elseif ('`' === $char) {
                $this->scanString();
            } elseif ($this->isDigit($char)) {
                // Widget ID segments may be UUIDs that start with a
                // digit (e.g., `019d3dbc-...`); scan as an identifier
                // so the dash-accepting path applies, otherwise fall
                // through to numeric scanning.
                if ($this->isScanningWidgetIdSegment()) {
                    $this->scanIdentifier();
                } else {
                    $this->scanNumber();
                }
            } elseif ($this->isAlpha($char) || '_' === $char || '@' === $char) {
                // `@` opens an entity-alias identifier (a later extension). Lives in tag mode only; `scanText` continues
                // to treat `@` as literal so email addresses and prose
                // outside braces are unaffected.
                $this->scanIdentifier();
            } else {
                throw new SyntaxException("Unexpected character '$char' at position $this->position", $this->line, $this->column);
            }
        }

        if ($depth > 0) {
            throw new SyntaxException('Unclosed tag: missing closing brace', $this->line, $this->column);
        }
    }

    /**
     * Scan identifier (variable name, function name, etc.).
     *
     * Widget ID segments accept dashes to support UUID-form IDs
     * (e.g., `{widget:media:019d3dbc-45e8-7e01-aaad-8aa3db96e95c}`);
     * see MediaWidgetRenderer docblock. Dashes are accepted ONLY when
     * the just-emitted token chain shows we are positioned at a
     * `widget:ns[:id[:...]]` segment -- never for general identifiers,
     * so subtraction expressions and similar are unaffected.
     */
    private function scanIdentifier(): string
    {
        $start = $this->position;
        $startLine = $this->line;
        $startColumn = $this->column;
        $identifier = '';
        $allowDashes = $this->isScanningWidgetIdSegment();

        // `@` is valid as the FIRST character only -- entity-alias
        // identifiers can't carry an `@` mid-token, and `@@foo` is a
        // typo we want to surface rather than accept. Consume the
        // leading `@` here (if present), then validate the *next* char
        // immediately so we don't re-peek the same position later
        // (PHPStan would infer that the second peek must yield `@`
        // because the first one did, even though `advance()` mutated
        // state -- restructuring avoids the false positive without
        // touching `peek()`'s purity contract).
        $hadAtPrefix = '@' === $this->peek();
        if ($hadAtPrefix) {
            $identifier .= $this->advance();
            // Validate the char that follows the consumed `@` once.
            // PHPStan models `peek()` as a pure function and so infers
            // that subsequent peeks must return `'@'` because the first
            // one did; in reality `advance()` moved the cursor. Reading
            // the source string directly bypasses that inference
            // without marking `peek()` impure platform-wide.
            $nextChar = $this->position < $this->length
                ? mb_substr($this->source, $this->position, 1, $this->encoding)
                : '';
            if ('@' === $nextChar) {
                throw new SyntaxException('Unexpected `@` -- entity-alias prefix must appear once at the start of an identifier.', $this->line, $this->column);
            }
            if ('' === $nextChar || (!$this->isAlphaNumeric($nextChar) && '_' !== $nextChar)) {
                throw new SyntaxException('Empty entity-alias after `@` -- expected `@name`.', $this->line, $this->column);
            }
        }

        while (!$this->isEof() && ($this->isAlphaNumeric($this->peek()) || '_' === $this->peek() || ($allowDashes && '-' === $this->peek()))) {
            $identifier .= $this->advance();
        }

        // Check for boolean literals
        if (isset(self::BOOLEAN_LITERALS[$identifier])) {
            $this->addToken(TokenType::Boolean, $identifier, $start, $startLine, $startColumn);
        } else {
            $this->addToken(TokenType::Identifier, $identifier, $start, $startLine, $startColumn);
        }

        return $identifier;
    }

    /**
     * True when the just-emitted token chain shows the cursor sits on
     * a widget ID segment -- either the namespace right after
     * `widget:`, or any subsequent `:segment` chained from it.
     *
     * Matches both tokenizations of `widget`: the Widget keyword (at
     * tag-start) and a plain Identifier with value `widget` (inside a
     * tag body, e.g., `{var:foo=widget:ns:id}`).
     *
     * The tail must end in Colon, and walking back through alternating
     * (Identifier, Colon) pairs must land on the `widget` token. Any
     * other token (Equals, OpenBracket, etc.) breaks the chain.
     */
    private function isScanningWidgetIdSegment(): bool
    {
        $count = count($this->tokens);
        if ($count < 2) {
            return false;
        }
        // Must directly follow a `:`.
        if (!$this->tokens[$count - 1]->is(TokenType::Colon)) {
            return false;
        }
        // Walk back through (Identifier, Colon) pairs to find the
        // `widget` anchor; if found, this is a widget id segment.
        $i = $count - 2;
        while ($i >= 0) {
            $token = $this->tokens[$i];
            if ($token->is(TokenType::Widget)
                || ($token->is(TokenType::Identifier) && 'widget' === $token->value)
            ) {
                return true;
            }
            if (!$token->is(TokenType::Identifier)) {
                return false;
            }
            --$i;
            if ($i < 0 || !$this->tokens[$i]->is(TokenType::Colon)) {
                return false;
            }
            --$i;
        }

        return false;
    }

    /**
     * Scan string literal: `string content`.
     */
    private function scanString(): void
    {
        $start = $this->position;
        $startLine = $this->line;
        $startColumn = $this->column;

        // Opening backtick
        $this->advance();

        $string = '';

        while (!$this->isEof() && '`' !== $this->peek()) {
            // Handle escape sequences
            if ('\\' === $this->peek() && '`' === $this->peek(1)) {
                $this->advance(); // Skip backslash
            }
            $string .= $this->advance(); // Add backtick
        }

        if ($this->isEof()) {
            throw new SyntaxException('Unterminated string literal', $startLine, $startColumn);
        }

        // Closing backtick
        $this->advance();

        $this->addToken(TokenType::String, $string, $start, $startLine, $startColumn);
    }

    /**
     * Scan number literal.
     */
    private function scanNumber(): void
    {
        $start = $this->position;
        $startLine = $this->line;
        $startColumn = $this->column;
        $number = '';

        while (!$this->isEof() && ($this->isDigit($this->peek()) || '.' === $this->peek())) {
            $number .= $this->advance();
        }

        $this->addToken(TokenType::Number, $number, $start, $startLine, $startColumn);
    }

    /**
     * Skip whitespace.
     */
    private function skipWhitespace(): void
    {
        while (!$this->isEof() && $this->isWhitespace($this->peek())) {
            $this->advance();
        }
    }

    /**
     * Check if the character is whitespace.
     */
    private function isWhitespace(string $char): bool
    {
        return in_array($char, [' ', "\t", "\n", "\r"], true);
    }

    /**
     * Check if the character is alphabetic.
     */
    private function isAlpha(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z');
    }

    /**
     * Check if character is a digit.
     */
    private function isDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    /**
     * Check if character is alphanumeric.
     */
    private function isAlphaNumeric(string $char): bool
    {
        return $this->isAlpha($char) || $this->isDigit($char);
    }

    /**
     * Peek at the character at the offset.
     */
    private function peek(int $offset = 0): string
    {
        $pos = $this->position + $offset;
        if ($pos >= $this->length) {
            return '';
        }

        return mb_substr($this->source, $pos, 1, $this->encoding);
    }

    /**
     * Advance position and return current character.
     */
    private function advance(): string
    {
        if ($this->isEof()) {
            return '';
        }

        $char = mb_substr($this->source, $this->position, 1, $this->encoding);
        ++$this->position;

        if ("\n" === $char) {
            ++$this->line;
            $this->column = 1;
        } else {
            ++$this->column;
        }

        return $char;
    }

    /**
     * Check if at the end of the source.
     */
    private function isEof(): bool
    {
        return $this->position >= $this->length;
    }

    /**
     * Add a token to the list.
     */
    private function addToken(
        TokenType $type,
        string $value,
        ?int $position = null,
        ?int $line = null,
        ?int $column = null,
    ): void {
        $this->tokens[] = new Token(
            $type,
            $value,
            $position ?? $this->position,
            $line ?? $this->line,
            $column ?? $this->column,
        );
    }
}
