<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Discriminator for VariableSource.
 */
enum VariableSourceType
{
    case Path; // context path, e.g. page.title
    case Literal; // string/number/bool literal
    case Widget; // WidgetNode reference
}
