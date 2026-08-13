<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\Runtime;

use CoolMS\Dtmpl\AST\TemplateNode;

/**
 * Allows the Executor to compile template source strings
 * without holding a direct reference to Lexer/Parser.
 * Implemented by TemplateEngine, which adds caching transparently.
 */
interface TemplateCompilerInterface
{
    public function compile(string $source): TemplateNode;
}
