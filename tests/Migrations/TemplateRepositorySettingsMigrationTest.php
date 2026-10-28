<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261027163900;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261027163900.php';

final class TemplateRepositorySettingsMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_binding_of_each_template_gets_the_repository_settings_and_keeps_its_project_settings(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-repository-settings-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-repository-settings-simple');
        $this->bindHandler()(new \App\Module\Workflow\Command\BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        foreach ([$lifecycleId => 24, $simpleId => 9] as $projectId => $version) {
            $old = $this->row($connection, $projectId);
            unset($old['repositorySettings']);
            $old['version'] = $version;
            $old['epicBranch'] = 'feature/epic-{number}';
            $old['approvalCoversConflictResolution'] = false;
            $connection->executeStatement('UPDATE workflow_bindings SET template_version = ?, definition = CAST(? AS JSONB) WHERE project_id = ?', [$version, json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        }

        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class);
        foreach (['lifecycle' => $lifecycleId, 'simple' => $simpleId] as $key => $projectId) {
            $source = $shipped->source($key);
            self::assertEquals([...$source, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $projectId));
            self::assertNotSame([], $this->row($connection, $projectId)['repositorySettings']);
            self::assertSame($source['version'], $this->binding($connection, $projectId)['template_version']);
        }
        $migrated = [$this->binding($connection, $lifecycleId), $this->binding($connection, $simpleId)];

        $this->migrate($connection);

        self::assertSame($migrated, [$this->binding($connection, $lifecycleId), $this->binding($connection, $simpleId)]);
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261027163900($connection, new NullLogger());
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
