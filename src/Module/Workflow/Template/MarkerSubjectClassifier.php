<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Contract\MarkerSubjectType;
use App\Module\Workflow\Contract\NamesMarkerSubject;
use App\Module\Workflow\Expression\Expression;

/** Tells which kind of thing a marker condition is about. */
final readonly class MarkerSubjectClassifier
{
    /**
     * The one subject type that the leaves of the expression name, or null when they name none or two.
     * A named fact answers through its expression, so its leaves count.
     */
    public static function of(Expression $when): ?MarkerSubjectType
    {
        $types = [];
        foreach ($when->leaves() as $leaf) {
            if ($leaf->condition instanceof NamesMarkerSubject) {
                $types[$leaf->condition->subjectType()->value] = $leaf->condition->subjectType();
            }
        }

        return 1 === \count($types) ? array_first($types) : null;
    }
}
