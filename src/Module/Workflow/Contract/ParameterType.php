<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

enum ParameterType: string
{
    case String = 'string';
    case Int = 'int';
    /** A condition parameter alone. */
    case Bool = 'bool';
    case Slot = 'slot';
    /** A condition parameter alone: a string that names a value key the template declares in its `values` list. */
    case ValueKey = 'value-key';
    /** A string that names an outcome the template declares in its `outcomes` list. */
    case Outcome = 'outcome';
    /** An expression over conditions, such as the release of a pause. */
    case Expression = 'expression';
    /** A non-empty list of non-empty strings. */
    case List = 'list';
    /** A map with a tag and optionally a document status. It gives the parameters "document.tag" and "document.status". */
    case Document = 'document';
    /** A non-empty list of ask options, each with a label and the actions that run when the owner picks it. */
    case Options = 'options';
}
