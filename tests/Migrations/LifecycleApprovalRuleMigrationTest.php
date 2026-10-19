<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261019010412;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261019010412.php';

final class LifecycleApprovalRuleMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_keeps_its_epic_branch_and_its_approval_rule(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-approval-rule');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET template_version = 7, definition = jsonb_set(jsonb_set(definition - 'approvalCoversConflictResolution', '{epicBranch}', '\"feature/epic-{number}\"'), '{approvalCoversConflictResolution}', 'false') WHERE project_id = ?", [$projectId]);

        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->definition($connection, $projectId));
        self::assertSame($shipped['version'], $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }

    public function test_a_binding_with_no_epic_branch_and_no_rule_keeps_none(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-approval-rule-none');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET definition = definition - 'epicBranch' - 'approvalCoversConflictResolution' WHERE project_id = ?", [$projectId]);

        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        unset($shipped['epicBranch'], $shipped['approvalCoversConflictResolution']);
        self::assertEquals($shipped, $this->definition($connection, $projectId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261019010412($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, mixed> */
    private function definition(Connection $connection, string $projectId): array
    {
        $definition = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($definition);

        return json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
    }
}
