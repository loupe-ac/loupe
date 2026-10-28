<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Scheduler;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Scheduler\CheckForgeRepositoriesTask;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Workflow\WorkflowProjects;
use App\Tests\Support\ScheduledTasks;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class CheckForgeRepositoriesTaskTest extends KernelTestCase
{
    use WorkflowProjects;

    private InMemoryTransport $transport;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $this->transport = $transport;
    }

    public function test_the_task_runs_at_seven_minutes_past_each_hour_on_the_default_schedule(): void
    {
        self::assertSame(
            '7 * * * *',
            ScheduledTasks::cronExpressions(self::getContainer())[CheckForgeRepositoriesTask::class] ?? null,
        );
    }

    public function test_it_queues_an_hourly_check_for_each_repository_of_a_project_with_suggestions_only(): void
    {
        $bound = $this->workflowProject('check-task-bound');
        $this->bindLifecycle($bound);
        $first = $this->repository($bound, 'one');
        $second = $this->repository($bound, 'two');
        $unbound = $this->workflowProject('check-task-unbound');
        $this->repository($unbound, 'three');
        $before = \count($this->transport->getSent());

        $this->task()();

        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), \array_slice($this->transport->getSent(), $before));
        self::assertContainsOnlyInstancesOf(CheckForgeRepository::class, $messages);
        $queued = array_map(static fn (CheckForgeRepository $message): string => $message->repositoryId, $messages);
        self::assertEqualsCanonicalizing([(string) $first->id, (string) $second->id], $queued);
        foreach ($messages as $message) {
            self::assertSame(CheckTrigger::Hourly, $message->trigger);
        }
    }

    public function test_it_queues_a_repository_of_a_project_with_no_suggestions_while_its_stored_check_holds_items(): void
    {
        $project = $this->workflowProject('check-task-stale');
        $stale = $this->repository($project, 'stale');
        $empty = $this->repository($project, 'empty');
        $check = new ForgeRepositoryCheck($stale, $project);
        $check->items = [['key' => 'merge-method', 'mark' => 'blocking', 'state' => 'gap', 'gaps' => ['no_merge_method'], 'parameters' => []]];
        $this->em()->persist($check);
        $this->em()->flush();
        $before = \count($this->transport->getSent());

        $this->task()();

        $messages = array_map(static fn ($envelope) => $envelope->getMessage(), \array_slice($this->transport->getSent(), $before));
        self::assertContainsOnlyInstancesOf(CheckForgeRepository::class, $messages);
        $queued = array_map(static fn (CheckForgeRepository $message): string => $message->repositoryId, $messages);
        self::assertContains((string) $stale->id, $queued);
        self::assertNotContains((string) $empty->id, $queued);
    }

    public function test_the_message_routes_to_the_jobs_transport(): void
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(\Symfony\Component\Messenger\MessageBusInterface::class, $bus);
        $before = \count($this->transport->getSent());

        $bus->dispatch(new CheckForgeRepository('0199a0b8-0000-7000-8000-000000000000', CheckTrigger::PageOpen));

        $sent = \array_slice($this->transport->getSent(), $before);
        self::assertCount(1, $sent);
        self::assertInstanceOf(CheckForgeRepository::class, $sent[0]->getMessage());
    }

    private function task(): CheckForgeRepositoriesTask
    {
        $task = self::getContainer()->get(CheckForgeRepositoriesTask::class);
        self::assertInstanceOf(CheckForgeRepositoriesTask::class, $task);

        return $task;
    }

    private function repository(Project $project, string $id): ForgeRepository
    {
        $repository = new ForgeRepository($project, 'github', $id, 'acme/'.$id, ForgeRepositorySource::Hook);
        $this->em()->persist($repository);
        $this->em()->flush();

        return $repository;
    }
}
