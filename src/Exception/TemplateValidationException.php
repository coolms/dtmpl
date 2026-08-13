<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Exception;

use RuntimeException;
use Throwable;

/**
 * Thrown by DtmplSyntaxValidator when the DTMPL Lexer or Parser
 * rejects the input. Distinct exception type so the API processor can
 * map it cleanly to HTTP 422 (Unprocessable Entity) -- generic
 * `RuntimeException` would collapse with internal-server failures and
 * hide the user-facing nature of the error.
 */
final class TemplateValidationException extends RuntimeException
{
    public static function syntaxError(string $message, ?Throwable $previous = null): self
    {
        return new self(sprintf('Template syntax error: %s', $message), 0, $previous);
    }
}
