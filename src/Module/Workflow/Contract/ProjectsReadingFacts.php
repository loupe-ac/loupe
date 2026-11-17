<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

interface ProjectsReadingFacts
{
    /**
     * The projects whose workflow has a rule, a marker or an action condition that reads the facts class.
     *
     * @param list<string> $projectIds RFC 4122 ids
     * @param class-string $factsClass
     *
     * @return list<string> a subset of $projectIds
     */
    public function among(array $projectIds, string $factsClass): array;
}
