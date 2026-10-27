<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** Changes the rule states of cards when a module learns that their rules must start again. The engine implements it. */
interface WorkflowRuleStates
{
    /** Marks every card of the project, so the rules that turned true while the automation was off fire no action. */
    public function baselineProject(Uuid $projectId): void;

    /**
     * Resets the budget of each rule of the cards, and drops a baseline mark written while they were held.
     *
     * @param non-empty-list<Uuid> $cardIds
     */
    public function rearmCards(array $cardIds, \DateTimeImmutable $now): void;

    /**
     * Clears the mark that a project work hold put on its rule states, so each held rule asks for its work again.
     * Answers the cards that had a mark, and none when no rule state held.
     *
     * @return list<Uuid>
     */
    public function releaseWorkHolds(Uuid $projectId, \DateTimeImmutable $now): array;
}
