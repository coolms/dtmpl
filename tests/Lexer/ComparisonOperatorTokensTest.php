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
 * Lexer-level coverage for the comparison operator tokens.
 *
 * Single-char operators (`>` and `<`) emit `Gt`/`Lt` tokens.
 * Multi-char operators (`>=`, `<=`, `!=`) require character adjacency:
 * `> =` becomes two tokens (Gt then Equals), `! =` raises a targeted
 * lexer error because a bare `!` is not a valid DTMPL operator.
 * Whitespace around any operator is allowed once tag mode has past
 * the strict keyword/`:` adjacency check.
 */
final class ComparisonOperatorTokensTest extends TestCase
{
    private Lexer $lexer;

    #[Test]
    public function testNotEqualsTokenizesAsNeq(): void
    {
        $tokens = $this->lexer->tokenize('{if:a!=b}body{endif}');

        self::assertContains(TokenType::Neq, $this->typesOf($tokens));
    }

    #[Test]
    public function testGreaterThanTokenizesAsGt(): void
    {
        $tokens = $this->lexer->tokenize('{if:a>b}body{endif}');

        self::assertContains(TokenType::Gt, $this->typesOf($tokens));
    }

    #[Test]
    public function testLessThanTokenizesAsLt(): void
    {
        $tokens = $this->lexer->tokenize('{if:a<b}body{endif}');

        self::assertContains(TokenType::Lt, $this->typesOf($tokens));
    }

    #[Test]
    public function testGreaterOrEqualTokenizesAsGte(): void
    {
        $tokens = $this->lexer->tokenize('{if:a>=b}body{endif}');

        self::assertContains(TokenType::Gte, $this->typesOf($tokens));
    }

    #[Test]
    public function testLessOrEqualTokenizesAsLte(): void
    {
        $tokens = $this->lexer->tokenize('{if:a<=b}body{endif}');

        self::assertContains(TokenType::Lte, $this->typesOf($tokens));
    }

    #[Test]
    public function testStandaloneBangRaisesSyntaxError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/Unexpected `!`/');

        $this->lexer->tokenize('{if:a!b}body{endif}');
    }

    #[Test]
    public function testBangFollowedBySpaceRaisesSyntaxError(): void
    {
        $this->expectException(SyntaxException::class);
        $this->expectExceptionMessageMatches('/Unexpected `!`/');

        $this->lexer->tokenize('{if:a! =b}body{endif}');
    }

    #[Test]
    public function testGreaterWithSpaceBeforeEqualsEmitsTwoTokens(): void
    {
        $tokens = $this->lexer->tokenize('{if:a> =b}body{endif}');
        $types = $this->typesOf($tokens);

        $gtPos = array_search(TokenType::Gt, $types, true);
        $eqPos = array_search(TokenType::Equals, $types, true);

        self::assertIsInt($gtPos);
        self::assertIsInt($eqPos);
        self::assertLessThan($eqPos, $gtPos);
    }

    #[Test]
    public function testLessWithSpaceBeforeEqualsEmitsTwoTokens(): void
    {
        $tokens = $this->lexer->tokenize('{if:a< =b}body{endif}');
        $types = $this->typesOf($tokens);

        $ltPos = array_search(TokenType::Lt, $types, true);
        $eqPos = array_search(TokenType::Equals, $types, true);

        self::assertIsInt($ltPos);
        self::assertIsInt($eqPos);
        self::assertLessThan($eqPos, $ltPos);
    }

    #[Test]
    public function testWhitespaceAroundOperatorsTolerated(): void
    {
        $tokens = $this->lexer->tokenize('{if:a >= b}body{endif}');

        self::assertContains(TokenType::Gte, $this->typesOf($tokens));
    }

    protected function setUp(): void
    {
        $this->lexer = new Lexer();
    }

    /**
     * @param Token[] $tokens
     *
     * @return list<TokenType>
     */
    private function typesOf(array $tokens): array
    {
        return array_values(array_map(static fn (Token $t) => $t->type, $tokens));
    }
}
