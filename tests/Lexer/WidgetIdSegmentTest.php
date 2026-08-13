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
 * Widget ID segments must accept dashes so UUID-form IDs work, e.g.,
 * `{widget:media:019d3dbc-45e8-7e01-aaad-8aa3db96e95c}`. See
 * MediaWidgetRenderer docblock for the canonical consumer.
 *
 * Dash acceptance is scoped to widget id segments only -- general
 * identifiers, paths, and arithmetic-shaped expressions outside
 * widget tags continue to treat `-` as a non-identifier character.
 */
final class WidgetIdSegmentTest extends TestCase
{
    private Lexer $lexer;

    #[Test]
    public function uuidWithDashesTokenisesAsSingleIdentifier(): void
    {
        $uuid = '019d3dbc-45e8-7e01-aaad-8aa3db96e95c';
        $tokens = $this->lexer->tokenize(sprintf('{widget:media:%s}', $uuid));
        $idents = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['media', $uuid], $idents);
    }

    #[Test]
    public function uuidWithDashesAndTrailingParamLexes(): void
    {
        $uuid = '019d3dbc-45e8-7e01-aaad-8aa3db96e95c';
        $tokens = $this->lexer->tokenize(sprintf('{widget:media:%s size=small}', $uuid));
        $idents = $this->valuesByType($tokens, TokenType::Identifier);
        // namespace, uuid, param key, param value (identifier-shaped)
        self::assertSame(['media', $uuid, 'size', 'small'], $idents);
    }

    #[Test]
    public function plainAlphaUuidStillAcceptsDashes(): void
    {
        // The canonical MediaWidgetRenderer docblock example.
        $tokens = $this->lexer->tokenize('{widget:media:a3f9-uuid size=medium}');
        $idents = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['media', 'a3f9-uuid', 'size', 'medium'], $idents);
    }

    #[Test]
    public function widgetWithoutDashesStillLexesUnchanged(): void
    {
        $tokens = $this->lexer->tokenize('{widget:nav:breadcrumbs}');
        $idents = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['nav', 'breadcrumbs'], $idents);
    }

    #[Test]
    public function widgetNamespaceOnlyStillLexesUnchanged(): void
    {
        $tokens = $this->lexer->tokenize('{widget:breadcrumbs}');
        $idents = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['breadcrumbs'], $idents);
    }

    #[Test]
    public function dashedIdInWidgetSourceAssignment(): void
    {
        // `{var:foo=widget:media:UUID}` -- the lexer sees `widget` as a
        // plain Identifier here (keyword tokenisation only fires at
        // tag-start), so the dash-acceptance check must match both
        // tokenisations of `widget`.
        $tokens = $this->lexer->tokenize('{var:foo=widget:media:a3f9-uuid}');
        $idents = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['foo', 'widget', 'media', 'a3f9-uuid'], $idents);
    }

    #[Test]
    public function deeplyChainedWidgetSegmentsAcceptDashes(): void
    {
        $tokens = $this->lexer->tokenize('{widget:ns:a-b:c-d}');
        $idents = $this->valuesByType($tokens, TokenType::Identifier);
        self::assertSame(['ns', 'a-b', 'c-d'], $idents);
    }

    #[Test]
    public function dashOutsideWidgetContextIsNotAcceptedInIdentifier(): void
    {
        // `{var:user-name}` -- `user` lexes as Identifier and the `-`
        // would then be an unexpected char in tag mode (subtraction is
        // not a DTMPL operator). The lexer throws on the bare `-`.
        $this->expectException(SyntaxException::class);
        $this->lexer->tokenize('{var:user-name}');
    }

    #[Test]
    public function digitLedTokenOutsideWidgetContextIsNumber(): void
    {
        // `{var:5}` -- `5` is a number literal, not an identifier. The
        // dash-acceptance state only activates in widget id segments,
        // so this path is unchanged.
        $tokens = $this->lexer->tokenize('{var:5}');
        $hasNumber = false;
        foreach ($tokens as $t) {
            if (TokenType::Number === $t->type && '5' === $t->value) {
                $hasNumber = true;
                break;
            }
        }
        self::assertTrue($hasNumber, 'Expected a Number token for `5` outside widget context');
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
}
