<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Exception;

use Throwable;

/**
 * Syntax error in template.
 */
class SyntaxException extends TemplateException
{
    public function __construct(
        string $message,
        public readonly int $row = 0,
        public readonly int $column = 0,
        ?Throwable $previous = null,
    ) {
        $fullMessage = $message;
        if ($row > 0) {
            $fullMessage .= sprintf(' (line %d, column %d)', $row, $column);
        }
        parent::__construct($fullMessage, 0, $previous);
    }
}
