<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The forge, the lower-case repository and the number that name one pull request. */
final readonly class PullRequestKey
{
    public function __construct(
        public string $forge,
        public string $repository,
        public int $number,
    ) {
    }
}
