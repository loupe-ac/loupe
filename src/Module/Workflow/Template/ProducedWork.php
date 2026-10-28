<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** The kinds, roles and reasons that the request calls of a template can write on a work request. */
final readonly class ProducedWork
{
    /**
     * @param list<string> $kinds
     * @param list<string> $roles
     * @param list<string> $reasons
     * @param list<string> $outcomes the outcomes some move call sets
     */
    public function __construct(
        public array $kinds,
        public array $roles,
        public array $reasons,
        public array $outcomes = [],
    ) {
    }
}
