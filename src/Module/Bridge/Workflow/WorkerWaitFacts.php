<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Workflow\Contract\OpenWait;
use App\Module\Workflow\Contract\TimedFacts;

/** The open wait of a worker on the card, or none. The time limit of the wait is the next time the facts change by the clock. */
final readonly class WorkerWaitFacts implements TimedFacts
{
    public function __construct(
        public ?OpenWait $wait,
    ) {
    }

    #[\Override]
    public function changesAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $this->wait?->limitAt;
    }

    /** The conditions of the wait as one phrase, such as "checks passed on pr:owner/repo#12 or checks failed on pr:owner/repo#12". */
    public function conditionsText(): string
    {
        $labels = array_map(static function ($condition): string {
            $name = str_replace('_', ' ', substr(strrchr('.'.$condition->key, '.') ?: $condition->key, 1));

            return null === $condition->token ? $name : $name.' on '.$condition->token;
        }, $this->wait->conditions ?? []);

        return implode(' or ', $labels);
    }
}
