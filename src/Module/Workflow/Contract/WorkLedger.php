<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Reads and ends the work that bridges do for cards. Bridge implements it. */
interface WorkLedger
{
    /**
     * The open and claimed work of the card, oldest first.
     *
     * @return list<WorkView>
     */
    public function live(Uuid $cardId): array;

    public function find(Uuid $requestId): ?WorkView;

    /**
     * Answers false when the work is unknown or already settled. The reason is a code that the module of the work reads.
     * With onlyIfOpen, it also answers false when a bridge claimed the work since the caller read it.
     */
    public function withdraw(Uuid $requestId, WithdrawKind $kind, ?string $reason = null, bool $onlyIfOpen = false): bool;

    /** The reason code when the open work has no bridge online that could run it, or null when a bridge could, or the work is not open. */
    public function noTakerReason(Uuid $requestId): ?string;

    /**
     * The newest run of the card that resumes or reruns an earlier run, and that a worker ran or runs.
     * A rule id or a work kind narrows the runs to those of that rule or kind.
     */
    public function latestContinuation(Uuid $cardId, ?\DateTimeImmutable $since, ?string $ruleId, ?string $workKind = null): ?RunView;

    public function run(Uuid $runId): ?RunView;

    /** Whether the run is an open worker run on the card, or shares a session with one. */
    public function isOpenWorkerOnCard(string $runId, Uuid $projectId, Uuid $cardId): bool;

    public function isHeld(Uuid $projectId, Uuid $cardId): bool;
}
