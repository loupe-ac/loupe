<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261027225400;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261027225400.php';

final class LifecycleRepositoryMisfitMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_shipped_copy_and_keeps_its_project_settings(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-repository-misfit');
        $this->bindLifecycle($project);
        $other = $this->workflowProject('migration-repository-misfit-other');
        $this->bindHandler()(new BindWorkflowTemplateCommand($other, 'simple', []));
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $otherId = ($other->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        $old = $this->row($connection, $projectId);
        $old['version'] = 25;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        $old['markers'] = array_values(array_filter($old['markers'], static fn (array $marker): bool => 'repository-misfit' !== $marker['key']));
        $old['markers'][] = ['key' => 'removed-marker', 'kind' => 'needs-you', 'label' => 'marker.removed', 'when' => ['card.in_slot' => ['slot' => 'next']]];
        $old['inbox'] = array_values(array_diff($old['inbox'], ['repository-misfit']));
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = 25, definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        $otherBefore = $this->binding($connection, $otherId);

        $this->migrate($connection);

        $source = $this->service(ShippedTemplates::class)->source('lifecycle');
        $migrated = $this->row($connection, $projectId);
        self::assertEquals([...$source, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $migrated);
        self::assertContains('repository-misfit', array_column($migrated['markers'], 'key'));
        self::assertContains('repository-misfit', $migrated['inbox']);
        self::assertNotContains('removed-marker', array_column($migrated['markers'], 'key'), 'The copy is rewritten whole, so a marker that the template dropped goes too.');
        self::assertSame($source['version'], $this->binding($connection, $projectId)['template_version']);
        self::assertSame($otherBefore, $this->binding($connection, $otherId));
        $stored = $this->binding($connection, $projectId);

        $this->migrate($connection);

        self::assertSame($stored, $this->binding($connection, $projectId));
    }

    public function test_the_shipped_lifecycle_template_is_at_least_at_version_28(): void
    {
        self::bootKernel();

        self::assertGreaterThanOrEqual(28, $this->service(ShippedTemplates::class)->source('lifecycle')['version']);
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261027225400($connection, new NullLogger());
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
