<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** A marker condition whose evidence names the thing it is about. The marker pass reads the type to find the candidates. */
interface NamesMarkerSubject
{
    public function subjectType(): MarkerSubjectType;
}
