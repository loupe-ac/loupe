<?php

declare(strict_types=1);

namespace App\Module\Inbox\View;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;

/** What a rule did on a card before it reached its work limit: its work, its pull request, its runs and why they did not end the loop. */
final readonly class WorkLimitStory
{
    /**
     * @param list<string>                $findings      the titles of the findings of the newest failed agent review of the pull request
     * @param list<WorkLimitRound>        $rounds        the counted rounds of the rule, newest first
     * @param int                         $unknownRounds how many counted rounds Loupe has no record of, because they began before it stored round numbers
     * @param list<WorkLimitRoundFinding> $history       the findings that each round answered, newest round first
     * @param list<WorkerRun>             $runs          the runs of those rounds, newest first
     * @param list<int>                   $openChildren  the numbers of the children of the card that are not done
     */
    public function __construct(
        public string $workKind,
        public ?string $reason,
        public int $limit,
        public ?int $pullRequestNumber,
        public ?string $pullRequestUrl,
        public ?string $reviewUrl,
        public bool $reviewFailed,
        public array $findings,
        public array $rounds,
        public int $unknownRounds,
        public array $history,
        public array $runs,
        public array $openChildren,
        public ?string $epicBranch,
    ) {
    }

    /** The work in words, such as "fix" for the kind fix. */
    public function work(): string
    {
        return str_replace('-', ' ', $this->workKind);
    }

    /** The newest run of the rule ended blocked or waiting for a person, so a retry alone repeats it. */
    public function lastRunStopped(): bool
    {
        return \in_array(($this->runs[0] ?? null)?->state, [WorkerRunState::Blocked, WorkerRunState::WaitingForPerson], true);
    }

    /** The last run of the newest round that ended waiting for a person, or null. A stop that a later run of its round went on from is never quoted. */
    public function lastStop(): ?WorkerRun
    {
        return array_find($this->rounds, static fn (WorkLimitRound $round): bool => WorkLimitRoundOutcome::WaitingForYou === $round->outcome)?->lastRun();
    }

    /** The page where the owner approves the pull request on GitHub, which overrides a failed agent review, or null. */
    public function approveUrl(): ?string
    {
        return $this->reviewFailed && null !== $this->pullRequestUrl ? $this->pullRequestUrl.'/files' : null;
    }

    /** An epic whose agent review failed while some of its children are open: the review asks for the work of those children. */
    public function waitsForChildren(): bool
    {
        return $this->reviewFailed && [] !== $this->openChildren;
    }
}
