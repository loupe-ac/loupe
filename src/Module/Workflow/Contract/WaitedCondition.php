<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** One condition of a wait that a worker opened: the condition, its parameters and the pull request it reads. */
final readonly class WaitedCondition
{
    /**
     * @param array<string, mixed> $params
     * @param ?Uuid                $pullRequestId the Forge pull request the condition reads, null when it reads none
     * @param ?string              $token         the token of that pull request, such as `pr:owner/repo#12`
     */
    public function __construct(
        public string $key,
        public array $params = [],
        public ?Uuid $pullRequestId = null,
        public ?string $token = null,
    ) {
    }
}
