<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Lexer;

/**
 * Token.
 *
 * Represents a single token in the template.
 */
final readonly class Token
{
    public function __construct(
        public TokenType $type,
        public string $value,
        public int $position,
        public int $line = 1,
        public int $column = 1,
    ) {
    }

    public function is(TokenType $type): bool
    {
        return $this->type === $type;
    }

    public function isAny(TokenType ...$types): bool
    {
        return in_array($this->type, $types, true);
    }

    public function __toString(): string
    {
        return sprintf(
            'Token(%s, "%s", pos=%d, line=%d, col=%d)',
            $this->type->name,
            $this->value,
            $this->position,
            $this->line,
            $this->column,
        );
    }
}
