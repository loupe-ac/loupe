<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Messenger;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Event\ForgeBranchPresenceChanged;
use App\Module\Forge\Messenger\CheckForgeBranch;
use App\Module\Forge\Messenger\CheckForgeBranchHandler;
use App\Module\Forge\Repository\ForgeRepositoryRepository;
use App\Module\Forge\Service\BranchPresence;
use App\Module\Forge\Service\BranchReaders;
use App\Module\Forge\Service\ForgeBranchPresence;
use App\Module\Project\Entity\Project;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\StubBranchReader;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

final class CheckForgeBranchHandlerTest extends KernelTestCase
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

        $owner = new User(fullName: 'Riley', email: 'check-branch-'.uniqid().'@example.com', password: 'hashed');
        $this->project = new Project($owner, 'check-branch-'.uniqid());
        $this->row = new ForgeRepository($this->project, 'github', (string) random_int(1, 1_000_000_000), 'acme/loupe', ForgeRepositorySource::Installation, '1');
        foreach ([$owner, $this->project, $this->row] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $this->reader->makeReadable($this->row);
    }

    public function test_an_existing_branch_is_stored_and_announced(): void
    {
        $changed = DispatchedEvents::of(self::getContainer(), ForgeBranchPresenceChanged::class);

        $this->handle();

        self::assertTrue($this->presence->read($this->row, 'epic/1'));
        self::assertCount(1, $changed->events());
        self::assertSame(['github', 'acme/loupe', 'epic/1', true], [$changed->events()[0]->forge, $changed->events()[0]->repository, $changed->events()[0]->branch, $changed->events()[0]->exists]);
        self::assertTrue($this->project->requireId()->equals($changed->events()[0]->projectId));
    }

    public function test_a_repeated_answer_announces_nothing(): void
    {
        $this->handle();
        $changed = DispatchedEvents::of(self::getContainer(), ForgeBranchPresenceChanged::class);
        $this->clock->sleep(5);

        $this->handle();

        self::assertSame([], $changed->events());
    }

    public function test_a_404_is_stored_as_absent(): void
    {
        $this->presence->record($this->row, 'epic/1', true, $this->clock->now());
        $this->clock->sleep(5);
        $this->reader->answer = BranchPresence::Absent;

        $this->handle();

        self::assertFalse($this->presence->read($this->row, 'epic/1'));
    }

    public function test_an_unreadable_forge_keeps_the_prior_answer_and_announces_nothing(): void
    {
        $this->presence->record($this->row, 'epic/1', true, $this->clock->now());
        $this->clock->sleep(5);
        $this->reader->answer = BranchPresence::Unreadable;
        $changed = DispatchedEvents::of(self::getContainer(), ForgeBranchPresenceChanged::class);

        $this->handle();

        self::assertSame(1, $this->reader->asked);
        self::assertTrue($this->presence->read($this->row, 'epic/1'));
        self::assertSame([], $changed->events());
    }

    public function test_a_row_the_project_cannot_read_any_more_is_not_asked_and_writes_nothing(): void
    {
        $this->reader->readable = [];

        $this->handle();

        self::assertSame(0, $this->reader->asked);
        self::assertNull($this->presence->read($this->row, 'epic/1'));
    }

    public function test_a_message_of_another_project_writes_nothing(): void
    {
        ($this->handler())(new CheckForgeBranch((string) \Symfony\Component\Uid\Uuid::v7(), (string) $this->row->id, 'epic/1'));

        self::assertSame(0, $this->reader->asked);
    }

    public function test_a_missing_row_writes_nothing(): void
    {
        ($this->handler())(new CheckForgeBranch((string) $this->project->id, (string) \Symfony\Component\Uid\Uuid::v7(), 'epic/1'));
        ($this->handler())(new CheckForgeBranch((string) $this->project->id, 'not-a-uuid', 'epic/1'));

        self::assertSame(0, $this->reader->asked);
    }

    public function test_a_check_that_read_the_forge_before_a_push_cannot_undo_the_push(): void
    {
        $this->reader->answer = BranchPresence::Absent;
        $this->reader->onPresence = function (): void {
            $this->clock->sleep(2);
            $this->presence->record($this->row, 'epic/1', true, $this->clock->now());
        };

        $this->handle();

        self::assertTrue($this->presence->read($this->row, 'epic/1'));
    }

    private function handle(): void
    {
        ($this->handler())(new CheckForgeBranch((string) $this->project->id, (string) $this->row->id, 'epic/1'));
    }

    private function handler(): CheckForgeBranchHandler
    {
        $repositories = self::getContainer()->get(ForgeRepositoryRepository::class);
        $events = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(ForgeRepositoryRepository::class, $repositories);
        self::assertInstanceOf(\Psr\EventDispatcher\EventDispatcherInterface::class, $events);

        return new CheckForgeBranchHandler($repositories, new BranchReaders([$this->reader]), $this->presence, $events, $this->clock);
    }
}
