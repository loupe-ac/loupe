<?php

declare(strict_types=1);

namespace App\Tests\Module\Inbox\EventListener;

use App\Mercure\LiveUpdates;
use App\Module\Bridge\Entity\Bridge;
use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Event\BridgeCommandSettled;
use App\Module\Bridge\Event\WorkerRunChanged;
use App\Module\Bridge\Event\WorkRequestChanged;
use App\Module\Bridge\ValueObject\BridgeCommandKind;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxAction;
use App\Module\Inbox\Entity\InboxActionKind;
use App\Module\Inbox\Install\InboxInstallFlags;
use App\Module\Inbox\Service\InboxChangedPublisher;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class RefreshInboxOnActionChangedTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Project $project;

    /** @var list<Update> */
    private array $pages = [];

    protected function setUp(): void
    {
        self::bootKernel();
        // Before anything builds the hub: the container hands the replacement only to what it builds afterwards.
        self::getContainer()->set('mercure.hub.default', new MockHub('http://mercure/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            if (str_contains($update->getData(), InboxChangedPublisher::PAGE_TYPE)) {
                $this->pages[] = $update;
            }

            return 'id';
        }));
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->switchFlag($em, InboxInstallFlags::FLAG_INBOX_ENABLED, true);
        $this->switchFlag($em, LiveUpdates::FLAG, true);
        $this->project = $this->project($em, $this->owner($em, 'refresh'), 'refresh');
        $em->flush();
    }

    public function test_a_changed_run_of_an_action_refreshes_the_inbox_page_of_its_project(): void
    {
        $stopped = $this->stoppedRun($this->project);
        $this->actionOn($stopped);

        $this->dispatchRunChanged($this->project, $stopped);

        self::assertSame(1, $this->publishedPages());
    }

    public function test_a_run_that_continues_the_stopped_run_refreshes_it_too(): void
    {
        $stopped = $this->stoppedRun($this->project);
        $this->actionOn($stopped);
        $continuing = $this->stoppedRun($this->project, continues: $stopped);

        $this->dispatchRunChanged($this->project, $continuing);

        self::assertSame(1, $this->publishedPages());
    }

    public function test_an_unrelated_run_refreshes_nothing(): void
    {
        $stopped = $this->stoppedRun($this->project);
        $this->actionOn($stopped);
        $unrelated = $this->stoppedRun($this->project);

        $this->dispatchRunChanged($this->project, $unrelated);
        self::assertSame(0, $this->publishedPages());

        // The guard: the same wiring does publish for a run of an action.
        $this->dispatchRunChanged($this->project, $stopped);
        self::assertSame(1, $this->publishedPages());
    }

    public function test_several_actions_of_one_project_publish_once_and_each_project_publishes_once(): void
    {
        $first = $this->stoppedRun($this->project);
        $second = $this->stoppedRun($this->project);
        $this->actionOn($first);
        $this->actionOn($second);
        $otherProject = $this->project($this->em, $this->owner($this->em, 'refresh-other'), 'refresh-other');
        $this->em->flush();
        $foreign = $this->stoppedRun($otherProject);
        $this->actionOn($foreign, $otherProject);

        $this->dispatchRunChanged($this->project, $first, $second, $foreign);

        self::assertSame(2, $this->publishedPages());
    }

    public function test_a_settled_command_refreshes_the_page_of_its_action(): void
    {
        $stopped = $this->stoppedRun($this->project);
        $command = $this->actionOn($stopped);

        $this->dispatch(new BridgeCommandSettled($this->projectId($this->project), Uuid::v4(), $this->idOf($stopped)));
        self::assertSame(0, $this->publishedPages());

        $this->dispatch(new BridgeCommandSettled($this->projectId($this->project), $command, $this->idOf($stopped)));
        self::assertSame(1, $this->publishedPages());
    }

    public function test_a_changed_work_request_refreshes_the_page_of_its_action(): void
    {
        $request = Uuid::v4();
        $this->sendAgentAction($request);

        $this->dispatch(new WorkRequestChanged($this->projectId($this->project), WorkSubject::CARD, Uuid::v4(), Uuid::v4(), WorkRequestState::Claimed));
        self::assertSame(0, $this->publishedPages());

        $this->dispatch(new WorkRequestChanged($this->projectId($this->project), WorkSubject::CARD, Uuid::v4(), $request, WorkRequestState::Claimed));
        self::assertSame(1, $this->publishedPages());
    }

    public function test_a_changed_run_that_a_work_request_started_refreshes_the_page_of_its_action(): void
    {
        $request = Uuid::v4();
        $this->sendAgentAction($request);
        $started = $this->stoppedRun($this->project, workRequestId: $request);
        $unrelated = $this->stoppedRun($this->project, workRequestId: Uuid::v4());

        $this->dispatchRunChanged($this->project, $unrelated);
        self::assertSame(0, $this->publishedPages());

        $this->dispatchRunChanged($this->project, $started);
        self::assertSame(1, $this->publishedPages());
    }

    private function sendAgentAction(Uuid $requestId): void
    {
        $action = new InboxAction($this->item($this->em, $this->project, random_int(1, 1_000_000)), InboxActionKind::SendAgent, $this->project->owner);
        $action->workRequestId = $requestId;
        $this->em->persist($action);
        $this->em->flush();
    }

    private function stoppedRun(Project $project, ?WorkerRun $continues = null, ?Uuid $workRequestId = null): WorkerRun
    {
        $bridge = new Bridge($project->owner, Uuid::v4(), [], 'b4e39aa7', new \DateTimeImmutable());
        $this->em->persist($bridge);
        $run = new WorkerRun(
            project: $project,
            bridgeId: $bridge->id,
            subjectType: WorkSubject::CARD,
            subjectId: Uuid::v4(),
            cardNumber: 1,
            workKind: 'implement',
            state: WorkerRunState::Blocked,
            runKey: Uuid::v7(),
            sessionId: Uuid::v4(),
            continuesRun: $continues,
            workRequestId: $workRequestId,
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    /** Records a resume action with a command on the run, and answers the command id. */
    private function actionOn(WorkerRun $run, ?Project $project = null): Uuid
    {
        $project ??= $this->project;
        $now = new \DateTimeImmutable();
        $command = new BridgeCommand($project->owner, $run->bridgeId ?? throw new \LogicException('Has a bridge.'), $project, $run, BridgeCommandKind::ResumeRun, $project->owner, $now, $now->modify('+15 minutes'));
        $command->state = BridgeCommandState::Done;
        $this->em->persist($command);
        $this->em->flush();
        $action = new InboxAction($this->item($this->em, $project, random_int(1, 1_000_000)), InboxActionKind::ResumeRun, $project->owner);
        $action->bridgeCommandId = $command->id;
        $this->em->persist($action);
        $this->em->flush();

        return $command->id ?? throw new \LogicException('Stored.');
    }

    private function dispatchRunChanged(Project $project, WorkerRun ...$runs): void
    {
        $this->dispatch(new WorkerRunChanged($this->projectId($project), [], array_values(array_map(fn (WorkerRun $run): string => (string) $this->idOf($run), $runs))));
    }

    private function dispatch(object $event): void
    {
        $this->em->clear();
        $this->dispatcher()->dispatch($event);
    }

    /** Publishes are queued until the response is sent, so the test sends it. */
    private function publishedPages(): int
    {
        self::assertNotNull(self::$kernel);
        $this->dispatcher()->dispatch(new TerminateEvent(self::$kernel, Request::create('/'), new Response()), KernelEvents::TERMINATE);

        $count = \count($this->pages);
        $this->pages = [];

        return $count;
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function projectId(Project $project): Uuid
    {
        return $project->id ?? throw new \LogicException('Stored.');
    }

    private function idOf(WorkerRun $run): Uuid
    {
        return $run->id ?? throw new \LogicException('Stored.');
    }
}
