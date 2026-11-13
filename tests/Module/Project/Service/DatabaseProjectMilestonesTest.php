<?php

declare(strict_types=1);

namespace App\Tests\Module\Project\Service;

use App\Module\Account\Entity\User;
use App\Module\Project\Contract\Event\ProjectSetUp;
use App\Module\Project\Contract\ProjectMilestones;
use App\Module\Project\Entity\Project;
use App\Module\Project\Service\DatabaseProjectMilestones;
use App\Tests\Support\DispatchedEvents;
use App\Tests\Support\NotSetUp;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A second database session holds the project row, so the rows commit and tearDown deletes them.
 */
#[SkipDatabaseRollback]
final class DatabaseProjectMilestonesTest extends KernelTestCase
{
    private ?Connection $other = null;
    private User $owner;
    private Project $project;
    private ProjectMilestones $milestones;
    /** @var DispatchedEvents<ProjectSetUp> */
    private DispatchedEvents $setUp;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->owner = new User(fullName: 'Riley Chen', email: 'milestones-'.uniqid().'@example.com', password: 'hashed');
        $em->persist($this->owner);
        $this->project = new Project($this->owner, 'Milestones '.uniqid());
        $em->persist($this->project);
        $em->flush();
        NotSetUp::mark($em, $this->project);
        $em->clear();

        $milestones = self::getContainer()->get(DatabaseProjectMilestones::class);
        self::assertInstanceOf(ProjectMilestones::class, $milestones);
        $this->milestones = $milestones;
        $this->setUp = DispatchedEvents::of(self::getContainer(), ProjectSetUp::class);
        $this->other = DriverManager::getConnection($em->getConnection()->getParams());
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->other) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
        }
        $connection = $this->em()->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $connection->executeStatement('RESET lock_timeout');
        $connection->executeStatement('DELETE FROM projects WHERE id = ?', [(string) $this->project->id]);
        $connection->executeStatement('DELETE FROM users WHERE id = ?', [(string) $this->owner->id]);

        parent::tearDown();
    }

    public function test_one_milestone_is_stored_and_leaves_the_project_not_set_up(): void
    {
        $this->milestones->reach($this->projectId(), ProjectMilestones::INIT_FINISHED);

        self::assertSame([ProjectMilestones::INIT_FINISHED], $this->keys());
        self::assertFalse($this->isSetUp());
        self::assertSame([], $this->setUp->events());
    }

    public function test_the_second_milestone_sets_the_project_up_and_dispatches_after_the_commit(): void
    {
        $this->milestones->reach($this->projectId(), ProjectMilestones::INIT_FINISHED);
        $this->milestones->reach($this->projectId(), ProjectMilestones::BRIDGE_CONNECTED);

        self::assertTrue($this->isSetUp());
        self::assertCount(1, $this->setUp->events());
        self::assertTrue($this->projectId()->equals($this->setUp->events()[0]->projectId));
        self::assertSame([0], $this->setUp->transactionDepths());
    }

    public function test_a_project_loaded_before_the_milestone_reads_the_flag_after_it(): void
    {
        $em = $this->em();
        $loaded = $em->find(Project::class, $this->projectId());
        self::assertInstanceOf(Project::class, $loaded);
        self::assertFalse($loaded->setUp);

        $this->milestones->reach($this->projectId(), ProjectMilestones::INIT_FINISHED);
        $this->milestones->reach($this->projectId(), ProjectMilestones::BRIDGE_CONNECTED);

        self::assertTrue($loaded->setUp);
    }

    public function test_a_milestone_reached_again_changes_nothing_and_dispatches_nothing(): void
    {
        $this->milestones->reach($this->projectId(), ProjectMilestones::INIT_FINISHED);
        $this->milestones->reach($this->projectId(), ProjectMilestones::BRIDGE_CONNECTED);
        $this->milestones->reach($this->projectId(), ProjectMilestones::BRIDGE_CONNECTED);

        self::assertCount(2, $this->keys());
        self::assertCount(1, $this->setUp->events());
    }

    public function test_the_check_repairs_a_project_that_holds_both_milestones_with_the_flag_off(): void
    {
        $connection = $this->em()->getConnection();
        foreach ([ProjectMilestones::INIT_FINISHED, ProjectMilestones::BRIDGE_CONNECTED] as $key) {
            $connection->executeStatement('INSERT INTO project_milestones (id, project_id, "key", reached_at) VALUES (?, ?, ?, NOW())', [(string) Uuid::v7(), (string) $this->project->id, $key]);
        }
        self::assertFalse($this->isSetUp());

        $this->milestones->reach($this->projectId(), ProjectMilestones::BRIDGE_CONNECTED);

        self::assertTrue($this->isSetUp());
        self::assertCount(1, $this->setUp->events());
    }

    public function test_a_project_that_is_gone_is_skipped(): void
    {
        $this->milestones->reach(Uuid::v7(), ProjectMilestones::INIT_FINISHED);

        self::assertSame([], $this->setUp->events());
    }

    public function test_deleting_the_project_deletes_its_milestones(): void
    {
        $this->milestones->reach($this->projectId(), ProjectMilestones::INIT_FINISHED);
        $connection = $this->em()->getConnection();

        $connection->executeStatement('DELETE FROM projects WHERE id = ?', [(string) $this->project->id]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM project_milestones WHERE project_id = ?', [(string) $this->project->id]));
    }

    public function test_reach_waits_for_the_write_lock_and_then_sets_the_project_up(): void
    {
        $other = $this->other();
        $other->beginTransaction();
        $other->executeStatement('SELECT 1 FROM projects WHERE id = ? FOR NO KEY UPDATE', [(string) $this->project->id]);
        $this->em()->getConnection()->executeStatement("SET lock_timeout = '300ms'");

        // The first milestone alone matches no row of the set-up update, so only the lock can make it wait.
        try {
            $this->milestones->reach($this->projectId(), ProjectMilestones::INIT_FINISHED);
            self::fail('The milestone did not wait for the write lock another session holds.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }
        self::assertSame([], $this->keys());

        $other->commit();
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();
        $this->milestones->reach($this->projectId(), ProjectMilestones::INIT_FINISHED);
        self::assertFalse($this->isSetUp());
        $this->milestones->reach($this->projectId(), ProjectMilestones::BRIDGE_CONNECTED);

        self::assertTrue($this->isSetUp());
        self::assertCount(1, $this->setUp->events());
    }

    public function test_reach_does_not_wait_for_a_key_share(): void
    {
        $other = $this->other();
        $other->beginTransaction();
        $other->executeStatement('SELECT 1 FROM projects WHERE id = ? FOR KEY SHARE', [(string) $this->project->id]);
        $this->em()->getConnection()->executeStatement("SET lock_timeout = '300ms'");

        $this->milestones->reach($this->projectId(), ProjectMilestones::INIT_FINISHED);
        $this->milestones->reach($this->projectId(), ProjectMilestones::BRIDGE_CONNECTED);

        self::assertTrue($this->isSetUp());
    }

    private function projectId(): Uuid
    {
        return $this->project->id ?? throw new \LogicException('A flushed project has an id.');
    }

    private function isSetUp(): bool
    {
        return (bool) $this->other()->fetchOne('SELECT set_up FROM projects WHERE id = ?', [(string) $this->project->id]);
    }

    /** @return list<string> */
    private function keys(): array
    {
        return array_values(array_map(strval(...), $this->other()->fetchFirstColumn('SELECT "key" FROM project_milestones WHERE project_id = ? ORDER BY "key"', [(string) $this->project->id])));
    }

    private function other(): Connection
    {
        return $this->other ?? throw new \LogicException('setUp opens the second session.');
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
