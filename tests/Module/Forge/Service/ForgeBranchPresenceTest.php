<?php

declare(strict_types=1);

namespace App\Tests\Module\Forge\Service;

use App\Module\Account\Entity\User;
use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Messenger\CheckForgeBranch;
use App\Module\Forge\Service\ForgeBranchPresence;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\DoctrineDbalAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Runs on the database pool that production uses, so a stored answer commits and rolls back with its
 * transaction. The test bundle wraps each test in a transaction, which a savepoint here nests inside.
 */
final class ForgeBranchPresenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MockClock $clock;
    private ForgeBranchPresence $presence;
    private ForgeRepository $row;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->clock = new MockClock('2026-11-16 12:00:00');
        $this->presence = $this->presenceOn($this->connection);

        $owner = new User(fullName: 'Riley', email: 'presence-'.uniqid().'@example.com', password: 'hashed');
        $project = new Project($owner, 'presence-'.uniqid());
        $this->row = new ForgeRepository($project, 'github', (string) random_int(1, 1_000_000_000), 'acme/loupe', ForgeRepositorySource::Installation, '1');
        foreach ([$owner, $project, $this->row] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
    }

    public function test_an_unknown_branch_reads_null_and_is_not_tracked(): void
    {
        self::assertFalse($this->presence->tracks($this->row, 'epic/1'));
        self::assertNull($this->presence->read($this->row, 'epic/1'));
        self::assertTrue($this->presence->tracks($this->row, 'epic/1'));
        self::assertCount(1, $this->checks());
    }

    public function test_a_recorded_answer_reads_back_and_reports_a_change_once(): void
    {
        self::assertTrue($this->presence->record($this->row, 'epic/1', true, $this->clock->now()));
        self::assertFalse($this->presence->record($this->row, 'epic/1', true, $this->later(1)));
        self::assertTrue($this->presence->read($this->row, 'epic/1'));
        self::assertTrue($this->presence->record($this->row, 'epic/1', false, $this->later(2)));
        self::assertFalse($this->presence->read($this->row, 'epic/1'));
        self::assertSame([], $this->checks());
    }

    public function test_a_first_answer_of_absent_is_not_a_change(): void
    {
        self::assertFalse($this->presence->record($this->row, 'epic/1', false, $this->clock->now()));
    }

    public function test_a_check_that_started_before_a_push_cannot_overwrite_the_answer_of_the_push(): void
    {
        $started = $this->clock->now();
        $this->presence->record($this->row, 'epic/1', true, $this->later(5));

        self::assertFalse($this->presence->record($this->row, 'epic/1', false, $started));
        self::assertTrue($this->presence->read($this->row, 'epic/1'));
    }

    public function test_a_check_that_started_after_a_push_replaces_the_answer_of_the_push(): void
    {
        $this->presence->record($this->row, 'epic/1', true, $this->clock->now());

        self::assertTrue($this->presence->record($this->row, 'epic/1', false, $this->later(5)));
        self::assertFalse($this->presence->read($this->row, 'epic/1'));
    }

    public function test_an_access_change_voids_every_answer_seen_before_it(): void
    {
        $this->presence->record($this->row, 'epic/1', true, $this->clock->now());
        $this->presence->record($this->row, 'epic/2', true, $this->clock->now());
        $this->clock->sleep(5);

        $this->presence->markAccessChanged($this->row);

        self::assertNull($this->presence->read($this->row, 'epic/1'));
        self::assertNull($this->presence->read($this->row, 'epic/2'));
        self::assertCount(2, $this->checks());
    }

    public function test_a_check_that_started_before_an_access_change_writes_nothing_that_counts(): void
    {
        $started = $this->clock->now();
        $this->clock->sleep(5);
        $this->presence->markAccessChanged($this->row);

        self::assertFalse($this->presence->record($this->row, 'epic/1', true, $started));
        self::assertNull($this->presence->read($this->row, 'epic/1'));
    }

    public function test_an_answer_seen_after_an_access_change_counts(): void
    {
        $this->presence->markAccessChanged($this->row);
        $this->clock->sleep(5);

        self::assertTrue($this->presence->record($this->row, 'epic/1', true, $this->clock->now()));
        self::assertTrue($this->presence->read($this->row, 'epic/1'));
    }

    public function test_an_access_mark_rolls_back_with_a_failed_transaction(): void
    {
        $this->presence->record($this->row, 'epic/1', true, $this->clock->now());
        $this->clock->sleep(5);

        try {
            $this->em->wrapInTransaction(function (): never {
                $this->presence->markAccessChanged($this->row);
                throw new \RuntimeException('The access change failed.');
            });
        } catch (\RuntimeException) {
        }

        self::assertTrue($this->presence->read($this->row, 'epic/1'), 'No mark committed, so the answer still counts.');
    }

    public function test_two_evaluations_of_one_old_entry_queue_one_check(): void
    {
        $this->presence->record($this->row, 'epic/1', false, $this->clock->now());
        $this->clock->sleep(ForgeBranchPresence::ABSENT_REFRESH_SECONDS);

        $this->connection->beginTransaction();
        $this->presence->read($this->row, 'epic/1');
        $this->presence->read($this->row, 'epic/1');
        $this->connection->commit();

        self::assertCount(1, $this->checks());
    }

    public function test_a_rolled_back_evaluation_leaves_no_reservation(): void
    {
        $this->connection->beginTransaction();
        $this->presence->read($this->row, 'epic/1');
        $this->connection->rollBack();

        self::assertFalse($this->presence->tracks($this->row, 'epic/1'), 'The entry rolled back with the evaluation.');
        $this->presence->read($this->row, 'epic/1');
        self::assertCount(2, $this->checks(), 'Each evaluation queued its own check, because the first reservation rolled back.');
    }

    public function test_a_read_queues_nothing_while_another_transaction_holds_the_branch_lock(): void
    {
        $other = DriverManager::getConnection($this->connection->getParams());
        $other->beginTransaction();
        try {
            $other->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?))', ['forge_branch:'.$this->row->id.':'.sha1('epic/1')]);

            self::assertNull($this->presence->read($this->row, 'epic/1'));
            self::assertSame([], $this->checks());
        } finally {
            $other->rollBack();
            $other->close();
        }

        self::assertNull($this->presence->read($this->row, 'epic/1'));
        self::assertCount(1, $this->checks());
    }

    public function test_the_entries_of_two_rows_do_not_meet(): void
    {
        $other = new ForgeRepository($this->row->project, 'github', (string) random_int(1, 1_000_000_000), 'acme/other', ForgeRepositorySource::Installation, '1');
        $this->em->persist($other);
        $this->em->flush();

        $this->presence->record($this->row, 'epic/1', true, $this->clock->now());

        self::assertNull($this->presence->read($other, 'epic/1'));
    }

    private function presenceOn(Connection $connection): ForgeBranchPresence
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return new ForgeBranchPresence($connection, $bus, new DoctrineDbalAdapter($connection), $this->clock);
    }

    private function later(int $seconds): \DateTimeImmutable
    {
        return $this->clock->now()->modify('+'.$seconds.' seconds');
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
