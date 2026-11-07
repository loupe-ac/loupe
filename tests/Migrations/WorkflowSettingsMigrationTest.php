<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261107031500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261107031500.php';

final class WorkflowSettingsMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_the_epic_branch_of_each_copy_moves_into_the_setting_values(): void
    {
        self::bootKernel();
        $pattern = $this->workflowProject('migration-settings-pattern');
        $this->bindLifecycle($pattern);
        $off = $this->workflowProject('migration-settings-off');
        $this->bindLifecycle($off);
        $none = $this->workflowProject('migration-settings-none');
        $this->bindHandler()(new BindWorkflowTemplateCommand($none, 'simple', []));
        $ids = [];
        foreach (['pattern' => $pattern, 'off' => $off, 'none' => $none] as $name => $project) {
            $ids[$name] = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        }
        $connection = $this->em()->getConnection();
        $this->age($connection, $ids['pattern'], ['epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false]);
        $this->age($connection, $ids['off'], ['epicBranch' => null]);
        $this->age($connection, $ids['none'], []);

        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class);
        $lifecycle = $shipped->source('lifecycle');
        self::assertEquals([...$lifecycle, 'settingValues' => ['epic-branch-pattern' => 'feature/epic-{number}'], 'approvalCoversConflictResolution' => false], $this->row($connection, $ids['pattern']));
        self::assertEquals([...$lifecycle, 'settingValues' => ['epic-branches' => false]], $this->row($connection, $ids['off']));
        self::assertEquals($shipped->source('simple'), $this->row($connection, $ids['none']));
        self::assertSame((string) $lifecycle['version'], $this->binding($connection, $ids['pattern'])['template_version']);
        $migrated = array_map(fn (string $id): array => $this->binding($connection, $id), $ids);

        $this->migrate($connection);

        self::assertSame($migrated, array_map(fn (string $id): array => $this->binding($connection, $id), $ids));
    }

    /** @param array<string, mixed> $project the keys that the project owns */
    private function age(Connection $connection, string $projectId, array $project): void
    {
        $old = $this->row($connection, $projectId);
        unset($old['settings'], $old['requirements'], $old['settingValues']);
        $old = [...$old, ...$project];
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261107031500($connection, new NullLogger());
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
