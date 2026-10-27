<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\EventListener;

use App\Module\Bridge\Command\WithdrawWorkRequestHandler;
use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\EventListener\ReconcileWorkOnWorkHoldChanged;
use App\Module\Bridge\Repository\WorkRequestRepository;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Forge\Event\ForgeRepositoryCheckChanged;
use App\Module\Forge\Service\ForgeRepositories;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Workflow\Contract\WorkflowRuleStates;
use App\Module\Workflow\Contract\WorkHolds;
use App\Module\Workflow\Entity\WorkflowRuleState;
use App\Module\Workflow\Repository\WorkflowRuleStateRepository;
use App\Tests\Module\Bridge\BlockingRepositories;
use App\Tests\Module\Bridge\BridgeScenario;
use App\Tests\Module\Bridge\RecordingCardEvaluations;
use App\Tests\Module\Forge\QueuedHoldReconciliations;
use App\Tests\Support\DispatchedEvents;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ReconcileWorkOnWorkHoldChangedTest extends KernelTestCase
{
    use BlockingRepositories;
    use BridgeScenario;
    use QueuedHoldReconciliations;

    private const string NOW = '2026-10-01T12:30:00+00:00';

    private RecordingCardEvaluations $evaluations;

    #[\Override]
    protected function setUp(): void
    {
        $this->evaluations = new RecordingCardEvaluations();
    }

    public function test_a_blocking_summary_withdraws_the_open_card_requests_and_leaves_claimed_work_alone(): void
    {
        [$project, $open, $claimed, $other] = $this->heldProjectWithRequests();

        $this->deliver($project);

        $this->em()->clear();
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $open->id)?->state);
        self::assertSame(WorkRequestState::Claimed, $this->em()->find(WorkRequest::class, $claimed->id)?->state);
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $other->id)?->state, 'A request of another project stays open.');
        self::assertSame([], $this->evaluations->evaluated);
    }

    public function test_a_repeated_event_changes_nothing_more(): void
    {
        [$project, $open] = $this->heldProjectWithRequests();
        $changes = DispatchedEvents::of(self::getContainer(), WorkRequestChanged::class);
        $this->deliver($project);
        self::assertCount(1, $changes->events(), 'The first event withdraws the open request.');

        $this->deliver($project);

        self::assertCount(1, $changes->events());
        $this->em()->clear();
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $open->id)?->state);
        self::assertSame([], $this->evaluations->evaluated);
    }

    public function test_a_change_inside_a_held_project_with_no_open_request_leaves_the_hold(): void
    {
        self::bootKernel();
        $project = $this->heldProject('listener-inside-hold');
        $card = Uuid::v7();
        $this->stamp($project, $card);
        $this->repositoryWithGaps($project, ['deletion_allowed']);

        $this->deliver($project);

        $this->em()->clear();
        self::assertNotNull($this->stampOf($card), 'The hold goes on, so the mark stays.');
        self::assertSame([], $this->evaluations->evaluated);
    }

    public function test_removing_the_last_blocking_repository_ends_the_hold_and_rearms_the_held_rules(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'listener-removal@example.com'), 'Removal');
        $repository = $this->repositoryWithGaps($project);
        $heldCard = Uuid::v7();
        $this->stamp($project, $heldCard);
        $this->stamp($project, $heldCard, 'second-rule');
        $request = $this->seedWorkRequest($this->em(), $project, cardId: $heldCard, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00'));

        $this->service(ForgeRepositories::class)->release($project, 'github', $repository->externalId);
        self::assertSame([(string) $project->id], $this->queuedReconciliations());
        $this->deliverQueued();

        $this->em()->clear();
        self::assertNull($this->stampOf($heldCard));
        self::assertNull($this->stampOf($heldCard, 'second-rule'));
        self::assertSame([[(string) $heldCard]], $this->evaluations->evaluated);
        $restarted = $this->em()->find(WorkRequest::class, $request->id);
        self::assertSame(WorkRequestState::Open, $restarted?->state);
        self::assertSame(self::NOW, $restarted->reopenedAt?->format(\DateTimeInterface::ATOM));
    }

    public function test_the_hold_start_event_of_an_older_check_after_the_hold_end_of_a_newer_one_withdraws_nothing(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'listener-reorder@example.com'), 'Reorder');
        $repository = $this->repositoryWithGaps($project);
        $card = Uuid::v7();
        $open = $this->seedWorkRequest($this->em(), $project, cardId: $card);
        $this->stamp($project, $card);
        $this->service(ForgeRepositories::class)->release($project, 'github', $repository->externalId);

        // The newer check cleared the gap and its event came first. The event of the older check comes last.
        $this->deliver($project);
        $this->deliver($project);

        $this->em()->clear();
        self::assertSame(WorkRequestState::Open, $this->em()->find(WorkRequest::class, $open->id)?->state);
        self::assertNull($this->stampOf($card));
        self::assertSame([[(string) $card]], $this->evaluations->evaluated, 'The second event finds nothing left to do.');
    }

    public function test_an_empty_summary_with_no_held_rule_does_nothing(): void
    {
        self::bootKernel();
        $project = $this->project($this->em(), $this->user($this->em(), 'listener-empty@example.com'), 'Empty');
        $request = $this->seedWorkRequest($this->em(), $project, createdAt: new \DateTimeImmutable('2026-10-01 10:00:00'));

        $this->deliver($project);

        $this->em()->clear();
        self::assertNull($this->em()->find(WorkRequest::class, $request->id)?->reopenedAt, 'No hold ended, so no clock restarts.');
        self::assertSame([], $this->evaluations->evaluated);
    }

    public function test_the_container_wires_the_listener_to_the_event(): void
    {
        [$project, $open] = $this->heldProjectWithRequests();

        $this->service(EventDispatcherInterface::class)->dispatch(new ForgeRepositoryCheckChanged($project->id ?? throw new \LogicException()));

        $this->em()->clear();
        self::assertSame(WorkRequestState::Cancelled, $this->em()->find(WorkRequest::class, $open->id)?->state);
    }

    public function test_an_unknown_project_is_ignored(): void
    {
        self::bootKernel();

        $this->listener()(new ForgeRepositoryCheckChanged(Uuid::v7()));

        self::assertSame([], $this->evaluations->evaluated);
    }

    /** @return array{Project, WorkRequest, WorkRequest, WorkRequest} */
    private function heldProjectWithRequests(): array
    {
        self::bootKernel();
        $project = $this->heldProject('listener-start-'.uniqid());
        $cardId = Uuid::v7();
        $open = $this->seedWorkRequest($this->em(), $project, cardId: $cardId);
        $claimed = $this->seedWorkRequest($this->em(), $project, cardId: $cardId, kind: 'fix', state: WorkRequestState::Claimed);
        $otherProject = $this->project($this->em(), $project->owner, 'Other');
        $other = $this->seedWorkRequest($this->em(), $otherProject);

        return [$project, $open, $claimed, $other];
    }

    private function heldProject(string $slug): Project
    {
        $project = $this->project($this->em(), $this->user($this->em(), $slug.'@example.com'), $slug);
        $this->repositoryWithGaps($project);

        return $project;
    }

    private function stamp(Project $project, Uuid $cardId, string $ruleId = 'implement-on-entry'): void
    {
        $state = new WorkflowRuleState($cardId, $project, $ruleId);
        $state->heldByWorkHoldSince = new \DateTimeImmutable('2026-10-01 11:00:00');
        $this->em()->persist($state);
        $this->em()->flush();
    }

    private function stampOf(Uuid $cardId, string $ruleId = 'implement-on-entry'): ?\DateTimeImmutable
    {
        return $this->service(WorkflowRuleStateRepository::class)->findOneBy(['cardId' => $cardId, 'ruleId' => $ruleId])?->heldByWorkHoldSince;
    }

    private function deliver(Project $project): void
    {
        $this->em()->clear();
        $this->listener()(new ForgeRepositoryCheckChanged($project->id ?? throw new \LogicException('Persisted.')));
    }

    private function deliverQueued(): void
    {
        foreach ($this->queuedReconciliations() as $projectId) {
            $this->em()->clear();
            $this->listener()(new ForgeRepositoryCheckChanged(Uuid::fromString($projectId)));
        }
    }

    private function listener(): ReconcileWorkOnWorkHoldChanged
    {
        return new ReconcileWorkOnWorkHoldChanged(
            $this->service(ProjectRepository::class),
            $this->em(),
            $this->service(WorkHolds::class),
            $this->service(WorkRequestRepository::class),
            $this->service(WithdrawWorkRequestHandler::class),
            $this->service(WorkflowRuleStates::class),
            $this->evaluations,
            new MockClock(self::NOW),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
