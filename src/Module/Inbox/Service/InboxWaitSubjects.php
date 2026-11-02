<?php

declare(strict_types=1);

namespace App\Module\Inbox\Service;

use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\WorkerRunRepository;
use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Repository\ForgePullRequestRepository;
use App\Module\Inbox\Entity\InboxCardWait;
use App\Module\Review\Entity\Document;
use App\Module\Review\Repository\DocumentRepository;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The subjects a page handler preloads. It remembers a subject that is gone, which the identity map cannot do.
 * It holds only what a preload put in it, so a worker and a write path always read fresh rows.
 */
final class InboxWaitSubjects implements ResetInterface
{
    /** @var array<string, ?WorkerRun> */
    private array $runsById = [];

    /** @var array<string, ?Document> */
    private array $documentsById = [];

    /** @var array<string, ?ForgePullRequest> */
    private array $pullRequestsById = [];

    public function __construct(
        private readonly WorkerRunRepository $workerRuns,
        private readonly DocumentRepository $documents,
        private readonly ForgePullRequestRepository $forgePullRequests,
    ) {
    }

    /** @param list<InboxCardWait> $waits */
    public function preload(array $waits): void
    {
        $runIds = [];
        $documentIds = [];
        $pullRequestIds = [];
        foreach ($waits as $wait) {
            if (null !== $wait->runId) {
                $runIds[$wait->runId->toRfc4122()] = null;
            }
            if (null !== $wait->documentId) {
                $documentIds[$wait->documentId->toRfc4122()] = null;
            }
            if (null !== $wait->pullRequestId) {
                $pullRequestIds[$wait->pullRequestId->toRfc4122()] = null;
            }
        }

        $this->runsById = [...$this->runsById, ...$runIds];
        foreach ([] === $runIds ? [] : $this->workerRuns->findByIds(array_keys($runIds)) as $run) {
            $this->runsById[(string) $run->id?->toRfc4122()] = $run;
        }
        $this->documentsById = [...$this->documentsById, ...$documentIds];
        foreach ([] === $documentIds ? [] : $this->documents->findBy(['id' => array_keys($documentIds)]) as $document) {
            $this->documentsById[(string) $document->id?->toRfc4122()] = $document;
        }
        $this->pullRequestsById = [...$this->pullRequestsById, ...$pullRequestIds];
        foreach ([] === $pullRequestIds ? [] : $this->forgePullRequests->findBy(['id' => array_keys($pullRequestIds)]) as $pullRequest) {
            $this->pullRequestsById[(string) $pullRequest->id?->toRfc4122()] = $pullRequest;
        }
    }

    public function run(Uuid $id): ?WorkerRun
    {
        $key = $id->toRfc4122();

        return \array_key_exists($key, $this->runsById) ? $this->runsById[$key] : $this->workerRuns->find($id);
    }

    public function document(Uuid $id): ?Document
    {
        $key = $id->toRfc4122();

        return \array_key_exists($key, $this->documentsById) ? $this->documentsById[$key] : $this->documents->find($id);
    }

    public function pullRequest(Uuid $id): ?ForgePullRequest
    {
        $key = $id->toRfc4122();

        return \array_key_exists($key, $this->pullRequestsById) ? $this->pullRequestsById[$key] : $this->forgePullRequests->find($id);
    }

    #[\Override]
    public function reset(): void
    {
        $this->runsById = [];
        $this->documentsById = [];
        $this->pullRequestsById = [];
    }
}
