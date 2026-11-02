<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261102020930;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261102020930.php';

final class LifecycleSessionPathMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_session_path_and_keeps_its_epic_branch_and_approval_rule(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-session-path');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = json_decode((string) $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($old);
        $old['version'] = 39;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        unset($old['manualMoves']);
        $connection->executeStatement(
            'UPDATE workflow_bindings SET definition = CAST(? AS JSONB), template_version = 39 WHERE project_id = ?',
            [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );

        $migration = new Version20261102020930($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $migrated = json_decode((string) $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]), true, 512, \JSON_THROW_ON_ERROR);
        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $migrated);
        self::assertSame($shipped['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
        self::assertContainsEquals(['from' => '@backlog', 'to' => 'implementation', 'by' => 'session'], $migrated['manualMoves']);
        self::assertContainsEquals(['from' => 'next', 'to' => 'implementation', 'by' => 'session'], $migrated['manualMoves']);
    }
}
