<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\ValueObject\WorkerRunKind;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261019091512;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261019091512.php';

final class WorkRequestRoleFillMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_every_request_and_worker_run_of_a_role_kind_takes_its_role_in_any_state(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'role-fill@example.com'), 'Role fill');
        $kinds = [
            'review' => 'code-review',
            'design-review' => 'design-review',
            'product-design-revise' => 'design-revise',
            'tech-design-revise' => 'design-revise',
            'fix' => 'fix',
            'implement' => null,
        ];
        $requests = [];
        $runs = [];
        foreach ($kinds as $kind => $expected) {
            foreach ([WorkRequestState::Open, WorkRequestState::Done] as $state) {
                $requests[] = [(string) $this->seedWorkRequest($em, $project, kind: $kind, state: $state)->id, $expected];
            }
            foreach ([WorkerRunState::Running, WorkerRunState::Succeeded] as $state) {
                $runs[] = [(string) $this->seedRun($em, $project, workKind: $kind, state: $state)->id, $expected];
            }
        }
        $interactive = (string) $this->seedRun($em, $project, workKind: 'fix', kind: WorkerRunKind::Interactive)->id;
        $unnamed = (string) $this->seedRun($em, $project, workKind: null)->id;
        $connection = $em->getConnection();

        for ($pass = 0; $pass < 2; ++$pass) {
            $migration = new Version20261019091512($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                if (str_starts_with($query->getStatement(), 'UPDATE')) {
                    $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
                }
            }
        }

        foreach ($requests as [$id, $expected]) {
            self::assertSame($expected, $connection->fetchOne('SELECT role FROM work_requests WHERE id = ?', [$id]));
        }
        foreach ($runs as [$id, $expected]) {
            self::assertSame($expected, $connection->fetchOne('SELECT role FROM bridge_worker_runs WHERE id = ?', [$id]));
        }
        self::assertNull($connection->fetchOne('SELECT role FROM bridge_worker_runs WHERE id = ?', [$interactive]));
        self::assertNull($connection->fetchOne('SELECT role FROM bridge_worker_runs WHERE id = ?', [$unnamed]));
    }
}
