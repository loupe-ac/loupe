<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** The answer to the question whether a worker can wait for one condition, and whether it is true now. */
final readonly class WaitConditionCheck
{
    /**
     * @param ?WaitConditionRefusal $refusal          why the worker cannot wait for the condition, or null when it can
     * @param string                $message          the reason in plain English, for the worker to read
     * @param bool                  $isTrue           the condition is true now, and only meaningful with no refusal
     * @param ?Uuid                 $pullRequestId    the id of the bound Forge pull request, or null when the condition reads none
     * @param ?string               $pullRequestToken the token of the bound pull request, such as `pr:owner/repo#12`
     * @param array<string, mixed>  $params           the parameters after validation
     */
    public function __construct(
        public ?WaitConditionRefusal $refusal = null,
        public string $message = '',
        public bool $isTrue = false,
        public ?Uuid $pullRequestId = null,
        public ?string $pullRequestToken = null,
        public array $params = [],
    ) {
    }
}
