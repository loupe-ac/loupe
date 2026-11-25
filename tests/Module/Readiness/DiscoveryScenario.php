<?php

declare(strict_types=1);

namespace App\Tests\Module\Readiness;

use App\Module\Board\Entity\Card;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestContext;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Project\Entity\Project;
use App\Module\Readiness\Entity\DiscoveryRun;
use App\Module\Readiness\Entity\DiscoveryRunState;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\AgentCredential;
use Symfony\Component\Uid\Uuid;

/** Projects, discovery runs and the bridges and work requests of the database tests of discovery. */
trait DiscoveryScenario
{
    use WorkflowProjects;

    private int $cardNumber = 0;

    private function card(Project $project, string $column = 'backlog'): Card
    {
        $card = new Card($project, $this->column($project, $column), 'A card', '', ++$this->cardNumber);
        $card->type = 'tooling';
        $this->em()->persist($card);
        $this->em()->flush();

        return $card;
    }

    private function discoveryRun(Project $project, DiscoveryRunState $state = DiscoveryRunState::Requested, string $createdAt = '2026-10-02 12:00:00', ?Card $card = null): DiscoveryRun
    {
        $run = new DiscoveryRun($project, new \DateTimeImmutable($createdAt), $card);
        $run->state = $state;
        $this->em()->persist($run);
        $this->em()->flush();

        return $run;
    }

    /** @param list<string>|null $workKinds null makes a bridge that runs app prompts, and a list makes one that runs only those kinds */
    private function discoveryBridge(Project $project, ?array $workKinds = null, \DateTimeImmutable $lastSeenAt = new \DateTimeImmutable()): Bridge
    {
        $bridge = new Bridge(AgentCredential::managed($this->em(), $project->owner, $project->owner->id), Uuid::v4(), [(string) $project->id], 'b4e39aa7', $lastSeenAt);
        $bridge->capabilities = [Bridge::CAPABILITY_WORK_REQUESTS, Bridge::CAPABILITY_WAITS, ...(null === $workKinds ? [Bridge::CAPABILITY_APP_PROMPTS] : [])];
        $bridge->workKinds = $workKinds ?? [];
        $this->em()->persist($bridge);
        $this->em()->flush();

        return $bridge;
    }

    /** @return list<WorkRequest> the requests about the run, oldest first */
    private function discoveryRequests(DiscoveryRun $run): array
    {
        $repository = self::getContainer()->get(WorkRequestRepository::class);
        self::assertInstanceOf(WorkRequestRepository::class, $repository);

        return array_values($repository->findBy(
            ['subjectType' => DiscoveryRun::SUBJECT_TYPE, 'subjectId' => $run->id],
            ['createdAt' => 'ASC', 'id' => 'ASC'],
        ));
    }

    private function discoveryRequest(DiscoveryRun $run, string $kind = 'discovery'): WorkRequest
    {
        $request = new WorkRequest($run->project, DiscoveryRun::SUBJECT_TYPE, $run->id ?? throw new \LogicException('A stored run has an id.'), null, $kind, 'subject-discovery', $kind, new \DateTimeImmutable());
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    /** A live card request of a discovery rule, as the engine opened it, with the values a request about a run must not keep. */
    private function legacyCardRequest(Card $card, string $kind = 'discovery', WorkRequestState $state = WorkRequestState::Open): WorkRequest
    {
        $request = new WorkRequest($card->project, WorkSubject::CARD, $card->id ?? throw new \LogicException('A stored card has an id.'), $card->number, $kind, null, $kind, new \DateTimeImmutable('-1 day'));
        $request->state = $state;
        $request->prompt = 'Run discovery for card {cardNumber}.';
        $request->context = new WorkRequestContext(reason: 'old-reason');
        $request->resumeSessionId = Uuid::v7();
        $request->targetBridgeId = Uuid::v7();
        $this->em()->persist($request);
        $this->em()->flush();

        return $request;
    }

    private function legacyWorkerRun(Card $card, WorkRequest $request): WorkerRun
    {
        $worker = new WorkerRun(
            project: $card->project,
            bridgeId: Uuid::v7(),
            subjectType: WorkSubject::CARD,
            subjectId: $card->id ?? throw new \LogicException('A stored card has an id.'),
            cardNumber: $card->number,
            workKind: $request->kind,
            state: WorkerRunState::Running,
            workRequestId: $request->id,
            ruleId: $request->ruleId,
        );
        $this->em()->persist($worker);
        $this->em()->flush();
        $this->em()->getConnection()->insert('bridge_worker_run_usage', [
            'id' => Uuid::v7()->toRfc4122(), 'run_id' => (string) $worker->id, 'project_id' => (string) $card->project->id,
            'subject_type' => WorkSubject::CARD, 'subject_id' => (string) $card->id, 'model' => 'claude-haiku', 'source' => 'reported',
            'input_tokens' => 1, 'output_tokens' => 2, 'cache_read_tokens' => 3, 'cache_write_tokens' => 4,
        ]);

        return $worker;
    }

    /** The request, its worker run and the usage of that run hold what a request about the run holds. */
    private function assertMovedToRun(DiscoveryRun $run, WorkRequest $request, ?WorkerRun $worker = null): void
    {
        $runId = (string) $run->id;
        $row = $this->em()->getConnection()->fetchAssociative('SELECT * FROM work_requests WHERE id = ?', [(string) $request->id]);
        self::assertIsArray($row);
        self::assertSame(
            ['discovery', $runId, null, 'subject-discovery', null, null, null, null, null, null],
            [$row['subject_type'], $row['subject_id'], $row['card_number'], $row['capability'], $row['resume_session_id'], $row['target_bridge_id'], $row['model'], $row['effort'], $row['role'], $row['round_number']],
        );
        self::assertTrue($row['fail_when_no_taker']);
        self::assertSame(file_get_contents(\dirname(__DIR__, 3).'/config/workflows/app/prompts/'.$request->kind.'.md'), $row['prompt']);
        self::assertIsString($row['context']);
        self::assertSame(new WorkRequestContext()->toArray(), json_decode($row['context'], true));
        // The timeout of work about a run counts from here, and the card request waited with no limit.
        if ('open' === $row['state']) {
            self::assertIsString($row['reopened_at']);
            self::assertEqualsWithDelta(time(), new \DateTimeImmutable($row['reopened_at'])->getTimestamp(), 120);
        } else {
            self::assertNull($row['reopened_at']);
        }

        if (null === $worker) {
            return;
        }
        self::assertSame(
            ['subject_type' => 'discovery', 'subject_id' => $runId, 'card_number' => null],
            $this->em()->getConnection()->fetchAssociative('SELECT subject_type, subject_id, card_number FROM bridge_worker_runs WHERE id = ?', [(string) $worker->id]),
        );
        self::assertSame(
            ['subject_type' => 'discovery', 'subject_id' => $runId],
            $this->em()->getConnection()->fetchAssociative('SELECT subject_type, subject_id FROM bridge_worker_run_usage WHERE run_id = ?', [(string) $worker->id]),
        );
    }

    /** @return array{subject_type: string, state: string} */
    private function requestRow(WorkRequest $request): array
    {
        /** @var array{subject_type: string, state: string} $row */
        $row = $this->em()->getConnection()->fetchAssociative('SELECT subject_type, state FROM work_requests WHERE id = ?', [(string) $request->id]);

        return $row;
    }

    /** The row as the database holds it, whatever the entity manager holds. */
    private function storedRun(DiscoveryRun $run): DiscoveryRun
    {
        $id = $run->id ?? throw new \LogicException('A stored run has an id.');
        $this->em()->clear();

        return $this->em()->find(DiscoveryRun::class, $id) ?? throw new \LogicException('The run is stored.');
    }
}
