<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Lexer;

/**
 * Token Type Enum.
 *
 * All possible token types in dTMPL templates.
 */
enum TokenType: string
{
    // Literal content
    case Text = 'TEXT';

    // Delimiters
    case OpenBrace = 'OPEN_BRACE'; // {
    case CloseBrace = 'CLOSE_BRACE'; // }
    case OpenBracket = 'OPEN_BRACKET'; // [
    case CloseBracket = 'CLOSE_BRACKET'; // ]
    case Colon = 'COLON'; // :
    case Pipe = 'PIPE'; // |
    case Comma = 'COMMA'; // ,
    case Dot = 'DOT'; // .
    case Equals = 'EQUALS'; // = (also equality in {if:})
    case Neq = 'NEQ'; // !=
    case Gt = 'GT';  // >
    case Lt = 'LT';  // <
    case Gte = 'GTE'; // >=
    case Lte = 'LTE'; // <=

    // Identifiers and literals
    case Identifier = 'IDENTIFIER'; // variable names, function names
    case String = 'STRING'; // `string literal`
    case Number = 'NUMBER'; // 123, 45.67
    case Boolean = 'BOOLEAN'; // true, false

    // Keywords/Tags
    case Var = 'VAR'; // var
    case Loop = 'LOOP'; // loop
    case EndLoop = 'ENDLOOP'; // endloop
    case Item = 'ITEM'; // item
    case If = 'IF'; // if
    case EndIf = 'ENDIF'; // endif
    case Unless = 'UNLESS'; // ifno/unless
    case EndUnless = 'ENDUNLESS'; // endno/endunless
    case Else = 'ELSE'; // else
    case Define = 'DEFINE'; // def
    case Include = 'INCLUDE'; // include
    case EndInclude = 'ENDINCLUDE'; // endinclude
    case Slot = 'SLOT'; // slot
    case EndSlot = 'ENDSLOT'; // endslot
    case Fill = 'FILL'; // fill
    case EndFill = 'ENDFILL'; // endfill
    case Widget = 'WIDGET'; // widget
    case Const = 'CONST';  // const
    case Translate = 'TRANSLATE'; // the {t:} translation directive

    // Special
    case Eof = 'EOF'; // End of file

    /**
     * Check if this is a tag keyword.
     */
    public function isTag(): bool
    {
        return match ($this) {
            self::Var, self::Loop, self::EndLoop, self::Item,
            self::If, self::EndIf, self::Unless, self::EndUnless, self::Else,
            self::Define,
            self::Include, self::EndInclude,
            self::Slot, self::EndSlot,
            self::Fill, self::EndFill,
            self::Widget,
            self::Const,
            self::Translate => true,
            default => false,
        };
    }

    /**
     * Check if this is a delimiter.
     */
    public function isDelimiter(): bool
    {
        return match ($this) {
            self::OpenBrace, self::CloseBrace, self::OpenBracket, self::CloseBracket,
            self::Colon, self::Pipe, self::Comma, self::Dot, self::Equals => true,
            default => false,
        };
    }

    /**
     * Check if this is a literal.
     */
    public function isLiteral(): bool
    {
        return match ($this) {
            self::String, self::Number, self::Boolean => true,
            default => false,
        };
    }
}
