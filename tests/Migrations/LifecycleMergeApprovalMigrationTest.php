<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261107060100;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261107060100.php';

final class LifecycleMergeApprovalMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_copy_gets_the_held_merge_and_keeps_its_project_values(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-merge-approval');
        $this->bindLifecycle($project);
        $other = $this->workflowProject('migration-merge-approval-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($other, 'simple', []));
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $otherId = ($other->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        $old = $this->row($connection, $projectId);
        $old['settingValues'] = ['epic-branch-pattern' => 'feature/epic-{number}'];
        $old['approvalCoversConflictResolution'] = false;
        $old['markers'] = array_values(array_filter($old['markers'], static fn (array $marker): bool => 'waits-for-merge-approval-answer' !== $marker['key']));
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        $otherBefore = $this->binding($connection, $otherId);

        $this->migrate($connection);

        $source = $this->service(ShippedTemplates::class)->source('lifecycle');
        $migrated = $this->row($connection, $projectId);
        self::assertEquals([...$source, 'settingValues' => ['epic-branch-pattern' => 'feature/epic-{number}'], 'approvalCoversConflictResolution' => false], $migrated);
        self::assertContains('waits-for-merge-approval-answer', array_column($migrated['markers'], 'key'));
        self::assertSame($otherBefore, $this->binding($connection, $otherId));
        $stored = $this->binding($connection, $projectId);

        $this->migrate($connection);

        self::assertSame($stored, $this->binding($connection, $projectId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261107060100($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, mixed> */
    private function binding(Connection $connection, string $projectId): array
    {
        $binding = $connection->fetchAssociative('SELECT template_key, template_version, definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsArray($binding);

        return $binding;
    }

    /** @return array<string, mixed> */
    private function row(Connection $connection, string $projectId): array
    {
        $definition = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($definition);
        $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
