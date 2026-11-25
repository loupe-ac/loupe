<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\Entity\WorkerRunStateChange;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261124120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261124120000.php';

final class LegacyWaitingRunsMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_run_and_its_state_row_stored_as_waiting_on_the_forge_become_unfinished_and_other_runs_stay(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'legacy-waiting@example.com'), 'Legacy waiting');
        $legacy = $this->seedRun($em, $project, state: WorkerRunState::Unfinished, hasResult: true);
        $kept = $this->seedRun($em, $project, state: WorkerRunState::Blocked, hasResult: true);
        $em->persist(new WorkerRunStateChange($legacy, WorkerRunState::Unfinished, new \DateTimeImmutable(), new \DateTimeImmutable()));
        $em->flush();
        $connection = $em->getConnection();
        $connection->executeStatement("UPDATE bridge_worker_runs SET state = 'waiting-on-forge' WHERE id = ?", [(string) $legacy->id]);
        $connection->executeStatement("UPDATE bridge_worker_run_states SET state = 'waiting-on-forge' WHERE run_id = ?", [(string) $legacy->id]);

        $migration = new Version20261124120000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertSame('unfinished', $connection->fetchOne('SELECT state FROM bridge_worker_runs WHERE id = ?', [(string) $legacy->id]));
        self::assertSame(['unfinished'], $connection->fetchFirstColumn('SELECT state FROM bridge_worker_run_states WHERE run_id = ?', [(string) $legacy->id]));
        self::assertSame('blocked', $connection->fetchOne('SELECT state FROM bridge_worker_runs WHERE id = ?', [(string) $kept->id]));
    }
}
