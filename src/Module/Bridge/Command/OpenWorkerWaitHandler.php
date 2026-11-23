<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkerWait;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Repository\WorkerWaitRepository;
use App\Module\Bridge\Service\CardColumnLookupInterface;
use App\Module\Workflow\Contract\WaitConditionCheck;
use App\Module\Workflow\Contract\WaitConditions;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Opens the wait that a worker asked for, or refuses it with a reason the worker can read.
 * A card holds one open wait, a chain of resumed runs holds a limited number of waits,
 * and a wait names only conditions that are not true yet.
 *
 * @phpstan-import-type ConditionEntry from WorkerWait
 */
final readonly class OpenWorkerWaitHandler
{
    private const int MAX_CHAIN_DEPTH = 10_000;

    public function __construct(
        private WorkerRunRepository $workerRuns,
        private WorkerWaitRepository $workerWaits,
        private CardColumnLookupInterface $cards,
        private WaitConditions $waitConditions,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(OpenWorkerWaitCommand $command): WorkerWait
    {
        $run = $this->workerRuns->findNewestWorkerOfSession($command->project, $command->sessionId)
            ?? throw new WorkerWaitRefused('Loupe has no bridge worker run for this session. Only a worker that a bridge runs can wait.');
        if (!$run->state->isOpen()) {
            throw new WorkerWaitRefused('The run of this session has ended, so it cannot start a wait.');
        }
        $runId = $run->id ?? throw new \LogicException('A stored run has an id.');
        $cardId = $run->cardId() ?? throw new WorkerWaitRefused('The run of this session is not about a card, so it cannot wait for the conditions of one.');
        if (null === $this->cards->columnOf($command->project, $cardId)) {
            throw new WorkerWaitRefused('The card of this run no longer exists.');
        }

        $settings = $this->waitConditions->settingsOf($command->project->requireId());
        if ($command->limitMinutes < 1) {
            throw new WorkerWaitRefused('limitMinutes must be at least 1.');
        }
        if ($command->limitMinutes > $settings->maxMinutes) {
            throw new WorkerWaitRefused(\sprintf('limitMinutes is over the longest wait of this workflow, which is %d minutes.', $settings->maxMinutes));
        }

        $chainRoot = self::chainRootOf($run);
        if ($this->workerWaits->countOfChain($chainRoot) >= $settings->maxPerRound) {
            throw new WorkerWaitRefused(\sprintf('The chain cap was reached: this run and the runs it resumes already held %d waits. Stop and report what you have.', $settings->maxPerRound));
        }
        if (null !== $this->workerWaits->findOpenOfCard($cardId)) {
            throw new WorkerWaitRefused('This card already has an open wait. A card holds one wait at a time.');
        }

        $entries = $this->entriesOf($cardId, $command);
        $now = $this->clock->now();
        $wait = new WorkerWait(
            project: $command->project,
            cardId: $cardId,
            runId: $runId,
            chainRootRunId: $chainRoot,
            bridgeId: $run->bridgeId ?? throw new WorkerWaitRefused('This run has no bridge, so Loupe cannot resume it.'),
            sessionId: $command->sessionId,
            conditions: $entries,
            openedAt: $now,
            limitAt: $now->modify(\sprintf('+%d minutes', $command->limitMinutes)),
        );

        try {
            $this->em->wrapInTransaction(function () use ($wait): void {
                $this->em->persist($wait);
                $this->em->flush();
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new WorkerWaitRefused('This card already has an open wait. A card holds one wait at a time.', previous: $e);
        }

        $this->auditor->record(
            'bridge.worker_wait_opened',
            AuditOutcome::Success,
            [
                'projectId' => $command->project->requireId()->toRfc4122(),
                'cardId' => $cardId->toRfc4122(),
                'runId' => $runId->toRfc4122(),
                'conditionKeys' => implode(',', array_column($entries, 'key')),
                'limitMinutes' => $command->limitMinutes,
            ],
            new AuditSubject('worker_wait', (string) $wait->id),
        );

        return $wait;
    }

    /**
     * Tests every condition on the card, and refuses all that cannot be waited for, or that hold already.
     *
     * @return non-empty-list<ConditionEntry>
     */
    private function entriesOf(Uuid $cardId, OpenWorkerWaitCommand $command): array
    {
        $refused = [];
        $holding = [];
        $entries = [];
        $seen = [];
        foreach ($command->conditions as $condition) {
            $check = $this->waitConditions->check($cardId, $condition->key, $condition->params, $condition->pullRequestUrl);
            if (null !== $check->refusal) {
                $refused[] = \sprintf('"%s": %s', $condition->key, $check->message);
                continue;
            }

            $entry = self::entryOf($condition, $check);
            $identity = json_encode([$entry['key'], $entry['params'], $entry['pullRequestId']], \JSON_THROW_ON_ERROR);
            if (isset($seen[$identity])) {
                $refused[] = \sprintf('"%s": the conditions name it twice%s.', $condition->key, null === $entry['token'] ? '' : ' for '.$entry['token']);
                continue;
            }
            $seen[$identity] = true;

            if ($check->isTrue) {
                $holding[] = null === $entry['token'] ? $entry['key'] : \sprintf('%s (%s)', $entry['key'], $entry['token']);
            }
            $entries[] = $entry;
        }

        if ([] !== $refused) {
            throw new WorkerWaitRefused(implode("\n", $refused));
        }
        if ([] !== $holding) {
            throw new WorkerWaitRefused(\sprintf('These conditions are true already: %s. Read the result now, and do not wait for it.', implode(', ', $holding)));
        }

        return $entries ?: throw new \LogicException('A wait names at least one condition.');
    }

    /** @return ConditionEntry */
    private static function entryOf(WaitConditionRequest $condition, WaitConditionCheck $check): array
    {
        return [
            'key' => $condition->key,
            'params' => $check->params,
            'pullRequestId' => $check->pullRequestId?->toRfc4122(),
            'token' => $check->pullRequestToken,
        ];
    }

    private static function chainRootOf(WorkerRun $run): Uuid
    {
        $current = $run;
        for ($depth = 0; null !== $current->continuesRun && $depth < self::MAX_CHAIN_DEPTH; ++$depth) {
            $current = $current->continuesRun;
        }

        return $current->id ?? throw new \LogicException('A stored run has an id.');
    }
}
