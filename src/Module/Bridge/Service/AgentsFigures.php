<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

/** The busy workers and the worker limit of the bridges that follow a project. */
final readonly class AgentsFigures
{
    public function __construct(
        public int $busy,
        public int $limit,
    ) {
    }
}
