<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\EventListener;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Event\ForgeBranchDeleted;
use App\Module\Forge\Event\ForgeBranchPresenceChanged;
use App\Module\Forge\Event\ForgeBranchPushed;
use App\Module\Forge\EventListener\RecordForgeBranchPresenceOnBranchEvents;
use App\Module\Forge\Messenger\CheckForgeBranch;
use App\Module\Forge\Messenger\CheckForgeBranchHandler;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\BranchPresence;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Forge\Service\ForgeBranchPresence;
use App\Module\Project\Entity\Project;
use App\Module\Project\Repository\ProjectRepository;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\StubBranchReader;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecordForgeBranchPresenceOnBranchEventsTest extends KernelTestCase
{
    private MockClock $clock;
    private ForgeBranchPresence $presence;
    private StubBranchReader $reader;
    private ForgeRepository $row;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->clock = new MockClock('2026-11-16 12:00:00');
        $connection = self::getContainer()->get(Connection::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $this->presence = new ForgeBranchPresence($connection, $bus, new ArrayAdapter(), $this->clock);
        $this->reader = new StubBranchReader();

        $owner = new User(fullName: 'Riley', email: 'branch-push-'.uniqid().'@example.com', password: 'hashed');
        $this->project = new Project($owner, 'branch-push-'.uniqid());
        $this->row = new ForgeRepository($this->project, 'github', (string) random_int(1, 1_000_000_000), 'acme/loupe', ForgeRepositorySource::Installation, '1');
        foreach ([$owner, $this->project, $this->row] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $this->reader->makeReadable($this->row);
    }

    public function test_a_push_to_a_branch_with_no_entry_writes_nothing_and_queues_no_check(): void
    {
        $this->push('epic/1');

        self::assertFalse($this->presence->tracks($this->row, 'epic/1'));
        self::assertSame([], $this->checks());
    }

    public function test_a_push_to_a_tracked_branch_writes_exists_queues_one_check_and_announces_the_change(): void
    {
        $this->presence->read($this->row, 'epic/1');
        $this->clock->sleep(5);
        $changed = DispatchedEvents::of(self::getContainer(), ForgeBranchPresenceChanged::class);
        $queued = \count($this->checks());

        $this->push('epic/1');

        self::assertTrue($this->presence->read($this->row, 'epic/1'));
        self::assertCount($queued + 1, $this->checks());
        self::assertCount(1, $changed->events());
        self::assertTrue($changed->events()[0]->exists);
    }

    public function test_a_deletion_of_a_tracked_branch_writes_absent(): void
    {
        $this->presence->read($this->row, 'epic/1');
        $this->presence->record($this->row, 'epic/1', true, $this->clock->now());
        $this->clock->sleep(5);
        $changed = DispatchedEvents::of(self::getContainer(), ForgeBranchPresenceChanged::class);

        $this->listener()->onDeleted(new ForgeBranchDeleted($this->project->requireId(), 'github', 'acme/loupe', 'epic/1'));

        self::assertFalse($this->presence->read($this->row, 'epic/1'));
        self::assertFalse($changed->events()[0]->exists);
    }

    public function test_a_push_from_a_repository_the_project_cannot_read_writes_nothing_and_queues_no_check(): void
    {
        $this->presence->read($this->row, 'epic/1');
        $this->reader->readable = [];
        $queued = \count($this->checks());

        $this->push('epic/1');

        self::assertNull($this->presence->read($this->row, 'epic/1'));
        self::assertCount($queued, $this->checks());
    }

    public function test_two_deliveries_in_the_wrong_order_end_on_the_answer_of_the_confirming_check(): void
    {
        $this->presence->read($this->row, 'epic/1');
        $this->clock->sleep(5);

        $this->listener()->onDeleted(new ForgeBranchDeleted($this->project->requireId(), 'github', 'acme/loupe', 'epic/1'));
        $this->clock->sleep(1);
        $this->push('epic/1');
        self::assertTrue($this->presence->read($this->row, 'epic/1'), 'The delivery that arrived last wins until a check runs.');

        $this->clock->sleep(1);
        $this->reader->answer = BranchPresence::Absent;
        $repositories = self::getContainer()->get(ForgeRepositoryRepository::class);
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(ForgeRepositoryRepository::class, $repositories);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);
        (new CheckForgeBranchHandler($repositories, new BranchReaders([$this->reader]), $this->presence, $events, $this->clock))(new CheckForgeBranch((string) $this->project->id, (string) $this->row->id, 'epic/1'));

        self::assertFalse($this->presence->read($this->row, 'epic/1'));
    }

    private function push(string $branch): void
    {
        $this->listener()->onPushed(new ForgeBranchPushed($this->project->requireId(), 'github', 'acme/loupe', $branch));
    }

    private function listener(): RecordForgeBranchPresenceOnBranchEvents
    {
        $projects = self::getContainer()->get(ProjectRepository::class);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(ProjectRepository::class, $projects);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        self::assertInstanceOf(EventDispatcherInterface::class, $events);

        return new RecordForgeBranchPresenceOnBranchEvents($projects, new BranchReaders([$this->reader]), $this->presence, $bus, $events, $this->clock);
    }

    /** @return list<CheckForgeBranch> */
    private function checks(): array
    {
        $transport = self::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $checks = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof CheckForgeBranch) {
                $checks[] = $message;
            }
        }

        return $checks;
    }
}
