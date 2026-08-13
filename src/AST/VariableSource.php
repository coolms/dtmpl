<?php

declare(strict_types=1);

namespace CoolMS\Dtmpl\AST;

/**
 * Value object describing the RHS of an assignment in {var:name=source} or {def:name=source}.
 *
 *   {var:title=page.title}       -- type=Path,    value=['page','title']
 *   {var:msg=`Hello`}            -- type=Literal, value='Hello'
 *   {def:form=widget:form:login} -- type=Widget,  value=WidgetNode
 */
final readonly class VariableSource
{
    public function __construct(
        public VariableSourceType $type,
        public mixed $value, // string[] path | scalar literal | WidgetNode
    ) {
    }
}
