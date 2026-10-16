<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Bridge\Entity\WorkerRunOutputChunk;
use App\Module\Bridge\Repository\WorkerRunOutputChunkRepository;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Bridge\Service\WorkerRunChangedPublisher;
use App\Module\Bridge\ValueObject\WorkerRunOutputLog;
use App\Module\Project\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Appends text to the live output log of a run. The text goes in only at the
 * offset where the log ends, so a repeat or a gap changes nothing and the
 * answer tells the bridge where the log ends. The log stops at
 * WorkerRunOutputLog::MAX_BYTES, cut at a character boundary. The run row is
 * locked, so two appends to one run never race.
 */
final readonly class AppendWorkerRunOutputHandler
{
    public function __construct(
        private ProjectRepository $projects,
        private WorkerRunRepository $workerRuns,
        private WorkerRunOutputChunkRepository $workerRunOutputChunks,
        private WorkerRunChangedPublisher $publisher,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(AppendWorkerRunOutputCommand $command): AppendWorkerRunOutputResult
    {
        $project = $this->projects->findOneByIdOrSlugForOwner($command->handle, $command->owner);
        if (null === $project) {
            return new AppendWorkerRunOutputResult(projectFound: false, runFound: false, length: 0, capped: false);
        }

        /** @var array{AppendWorkerRunOutputResult, ?Uuid} $outcome */
        $outcome = $this->em->wrapInTransaction(function () use ($command, $project): array {
            $run = $this->workerRuns->findOneByRunKey($project, $command->bridgeId, $command->runKey);
            if (null === $run) {
                return [new AppendWorkerRunOutputResult(projectFound: true, runFound: false, length: 0, capped: false), null];
            }

            $stored = $this->workerRunOutputChunks->storedLength($run);
            if ($this->workerRunOutputChunks->isCut($run)) {
                return [new AppendWorkerRunOutputResult(projectFound: true, runFound: true, length: $stored, capped: true), null];
            }

            $text = $command->text;
            if ($command->offset !== $stored || '' === $text) {
                return [new AppendWorkerRunOutputResult(projectFound: true, runFound: true, length: $stored, capped: false), null];
            }

            $cut = false;
            $room = WorkerRunOutputLog::MAX_BYTES - $stored;
            if (\strlen($text) > $room) {
                $text = mb_strcut($text, 0, $room, 'UTF-8');
                $cut = true;
            }

            $this->em->persist(new WorkerRunOutputChunk($run, $stored, \strlen($text), $text, $cut));
            $this->em->flush();

            $length = $stored + \strlen($text);

            return [
                new AppendWorkerRunOutputResult(projectFound: true, runFound: true, length: $length, capped: $cut),
                $run->id,
            ];
        });

        [$result, $runId] = $outcome;
        if (null !== $runId) {
            $this->publisher->outputAppended($project, $runId, $result->length);
        }

        return $result;
    }
}
