<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** The kinds, roles and reasons that the request calls of a template can write on a work request, each request call with what it writes, and the outcomes its moves can set. */
final readonly class ProducedWork
{
    /**
     * @param list<string>          $kinds
     * @param list<string>          $roles
     * @param list<string>          $reasons
     * @param list<string>          $outcomes
     * @param list<ProducedRequest> $requests
     */
    public function __construct(
        public array $kinds,
        public array $roles,
        public array $reasons,
        public array $outcomes,
        public array $requests,
    ) {
    }
}
