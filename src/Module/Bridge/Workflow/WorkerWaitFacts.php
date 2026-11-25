<?php

declare(strict_types=1);

namespace App\Module\Bridge\Workflow;

use App\Module\Workflow\Contract\OpenWait;
use App\Module\Workflow\Contract\TimedFacts;

/**
 * The open wait of a worker on the card, or none, and the wait that ended with its bridge offline, or none.
 * The time limit of the open wait, and the moment the bridge of a stranded wait reads quiet, are the next times the facts change by the clock.
 */
final readonly class WorkerWaitFacts implements TimedFacts
{
    public function __construct(
        public ?OpenWait $wait,
        public ?StrandedWait $stranded = null,
    ) {
    }

    #[\Override]
    public function changesAt(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $times = array_filter([
            $this->wait?->limitAt,
            null !== $this->stranded && !$this->stranded->bridgeQuiet ? $this->stranded->quietAt : null,
        ], static fn (?\DateTimeImmutable $at): bool => null !== $at);

        return [] === $times ? null : min($times);
    }

    /** Whether a wait ended while the bridge of its session is offline, so only a person or the bridge coming back can resume it. */
    public function strandedOffline(): bool
    {
        return true === $this->stranded?->bridgeQuiet;
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
