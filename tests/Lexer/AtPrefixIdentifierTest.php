<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Tests\Lexer;

use CoolMS\Dtmpl\Exception\SyntaxException;
use CoolMS\Dtmpl\Lexer\Lexer;
use CoolMS\Dtmpl\Lexer\Token;
use CoolMS\Dtmpl\Lexer\TokenType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `@alias` entity-reference syntax.
 *
 * `@` is recognised as the first character of an identifier only
 * inside tag mode; `scanText` and backticked strings keep it literal.
 * The schema builder strips the `@` and resolves the remainder via
 * `EntityAliasRegistry`.
 *
 * Coverage:
 *   • `{var:@user}` -- single identifier with `@` prefix
 *   • `{var:@identity_user.email}` -- `@` lives in the first path segment
 *   • `@` inside backtick strings stays literal (email addresses survive)
 *   • `@` in plain text stays literal (no false-positive tag start)
 *   • `{var:@@foo}` -- explicit error (committed-intent typo)
 *   • `{var:@}` -- bare `@` errors with a helpful message
 */
final class AtPrefixIdentifierTest extends TestCase
{
    private Lexer $lexer;

    #[Test]
    public function simpleAtIdentifierTokenisesAsSingleIdentifier(): void
    {
        $tokens = $this->lexer->tokenize('{var:@user}');
        $values = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['@user'], $values);
    }

    #[Test]
    public function atIdentifierThenDottedPathProducesTwoIdentifiers(): void
    {
        $tokens = $this->lexer->tokenize('{var:@identity_user.email}');
        $values = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['@identity_user', 'email'], $values);
        // The Dot between them is preserved as its own token.
        self::assertGreaterThanOrEqual(1, $this->countOfType($tokens, TokenType::Dot));
    }

    #[Test]
    public function atIdentifierInsideBacktickStringStaysLiteral(): void
    {
        $tokens = $this->lexer->tokenize('{var:greeting=`Hello user@example.com`}');
        // The string literal carries the whole content including `@`,
        // and there is exactly ONE String token (the `@user` did not
        // accidentally enter identifier mode).
        $stringTokens = array_values(array_filter(
            $tokens,
            static fn (Token $t) => TokenType::String === $t->type,
        ));
        self::assertCount(1, $stringTokens);
        self::assertSame('Hello user@example.com', $stringTokens[0]->value);
    }

    #[Test]
    public function atInPlainTextStaysLiteral(): void
    {
        // Outside any tag, `@user` is just text -- the lexer never enters
        // tag mode for it, so the whole input emerges as a single Text
        // token preserving the `@` byte-for-byte.
        $tokens = $this->lexer->tokenize('Email us at @user for help.');
        $text = '';
        foreach ($tokens as $t) {
            if (TokenType::Text === $t->type) {
                $text .= $t->value;
            }
        }
        self::assertSame('Email us at @user for help.', $text);
    }

    #[Test]
    public function doubleAtRaisesSyntaxError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/entity-alias prefix must appear once/');
        $this->lexer->tokenize('{var:@@foo}');
    }

    #[Test]
    public function bareAtRaisesSyntaxError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/Empty entity-alias after `@`/');
        $this->lexer->tokenize('{var:@}');
    }

    #[Test]
    public function legacyDottedPathStillParsesAsBefore(): void
    {
        // Regression guard -- non-`@` paths must lex identically to pre-
        // Phase-2-ext behaviour.
        $tokens = $this->lexer->tokenize('{var:user.email}');
        $values = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['user', 'email'], $values);
    }

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    /**
     * @param Token[] $tokens
     *
     * @return list<string>
     */
    private function valuesByType(array $tokens, TokenType $type): array
    {
        $out = [];
        foreach ($tokens as $t) {
            if ($t->type === $type) {
                $out[] = $t->value;
            }
        }

        return $out;
    }

    /**
     * @param Token[] $tokens
     */
    private function countOfType(array $tokens, TokenType $type): int
    {
        $n = 0;
        foreach ($tokens as $t) {
            if ($t->type === $type) {
                ++$n;
            }
        }

        return $n;
    }
}
