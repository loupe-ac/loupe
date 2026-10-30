<?php

declare(strict_types=1);

namespace App\Tests\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Command\UnarchiveExperimentCommand;
use App\Module\Bridge\Command\UnarchiveExperimentHandler;
use App\Module\Bridge\Entity\ExperimentArchive;
use App\Module\Project\Entity\Project;
use App\Tests\Module\Bridge\BridgeScenario;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A second session archives the experiment under the project lock. The rows
 * commit, because a second session cannot lock rows that another transaction
 * holds, so tearDown deletes them.
 */
#[SkipDatabaseRollback]
final class UnarchiveExperimentLockTest extends KernelTestCase
{
    use BridgeScenario;

    private ?Connection $other = null;
    private Connection $connection;
    private User $owner;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = $this->em();
        $this->connection = $em->getConnection();
        $this->owner = $this->user($em, 'unarchive-lock-'.uniqid().'@example.com');
        $this->project = $this->project($em, $this->owner, 'Unarchive lock');
        $em->persist(new ExperimentArchive($this->project, 'trial', new \DateTimeImmutable('2026-09-13 12:00:00'), []));
        $em->flush();
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
        $this->connection->executeStatement('RESET lock_timeout');
        $this->connection->executeStatement('DELETE FROM projects WHERE id = ?', [(string) $this->project->id]);
        $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [(string) $this->owner->id]);

        parent::tearDown();
    }

    public function test_an_unarchive_waits_for_the_project_lock_before_it_reads_the_archive(): void
    {
        $this->other = DriverManager::getConnection($this->connection->getParams());
        $this->other->beginTransaction();
        $this->other->executeStatement('SELECT id FROM projects WHERE id = ? FOR UPDATE', [(string) $this->project->id]);
        $this->connection->executeStatement("SET lock_timeout = '300ms'");
        $handler = self::getContainer()->get(UnarchiveExperimentHandler::class);
        self::assertInstanceOf(UnarchiveExperimentHandler::class, $handler);

        try {
            $handler(new UnarchiveExperimentCommand($this->project, 'trial'));
            self::fail('The unarchive did not wait for the project lock another session holds.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }
    }
}
