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

    /** The conditions of the wait as one phrase, such as "the checks of pr:owner/repo#12 to pass or the checks of pr:owner/repo#12 to fail". */
    public function conditionsText(): string
    {
        $labels = array_map(static function ($condition): string {
            $on = null === $condition->token ? '' : ' of '.$condition->token;

            return match ($condition->key) {
                'pr.checks_passed' => 'the checks'.$on.' to pass',
                'pr.checks_failed' => 'the checks'.$on.' to fail',
                default => str_replace('_', ' ', substr(strrchr('.'.$condition->key, '.') ?: $condition->key, 1)).(null === $condition->token ? '' : ' on '.$condition->token),
            };
        }, $this->wait->conditions ?? []);

        return implode(' or ', $labels);
    }
}
