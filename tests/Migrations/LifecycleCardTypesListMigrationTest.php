<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261022155004;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261022155004.php';

final class LifecycleCardTypesListMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_list_form_and_keeps_its_project_settings_and_a_simple_binding_stays_unchanged(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-card-types-list');
        $this->bind($project, 'lifecycle');
        $other = $this->workflowProject('migration-card-types-list-other');
        $this->bind($other, 'simple');
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $otherId = ($other->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        $old = $this->row($connection, $projectId);
        $old['version'] = 14;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        $oldJson = str_replace('{"types":["epic"]}', '{"type":"epic"}', json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION));
        self::assertStringContainsString('{"type":"epic"}', $oldJson);
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = 14, definition = CAST(? AS JSONB) WHERE project_id = ?', [$oldJson, $projectId]);
        $otherBefore = $this->binding($connection, $otherId);

        $this->migrate($connection);

        $source = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$source, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $projectId));
        self::assertSame($source['version'], $this->binding($connection, $projectId)['template_version']);
        self::assertStringNotContainsString('{"type": "epic"}', (string) $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]));
        self::assertSame($otherBefore, $this->binding($connection, $otherId));
        $migrated = $this->binding($connection, $projectId);

        $this->migrate($connection);

        self::assertSame($migrated, $this->binding($connection, $projectId));
    }

    private function bind(Project $project, string $key): void
    {
        if ('lifecycle' === $key) {
            $this->bindLifecycle($project);

            return;
        }
        $this->bindHandler()(new BindWorkflowTemplateCommand($project, $key, []));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261022155004($connection, new NullLogger());
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
