<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261103031358;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261103031358.php';

final class SkillPackagesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_binding_gets_the_skill_packages_of_its_template_and_keeps_its_project_settings(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-skill-packages');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-skill-packages-simple');
        $this->bindHandler()(new \App\Module\Workflow\Command\BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        foreach ([$lifecycleId => 'feature/epic-{number}', $simpleId => null] as $projectId => $epicBranch) {
            $old = $this->row($connection, $projectId);
            unset($old['skillPackages'], $old['needsProfile']);
            $old['version'] = 1;
            unset($old['epicBranch']);
            if (null !== $epicBranch) {
                $old['epicBranch'] = $epicBranch;
            }
            $old['approvalCoversConflictResolution'] = false;
            $connection->executeStatement('UPDATE workflow_bindings SET template_version = 1, definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        }

        $this->migrate($connection);

        $templates = $this->service(ShippedTemplates::class);
        $lifecycleCopy = $this->row($connection, $lifecycleId);
        $simpleCopy = $this->row($connection, $simpleId);
        self::assertEquals([...$templates->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $lifecycleCopy);
        self::assertSame(['loupe', 'loupe-lifecycle'], $lifecycleCopy['skillPackages']);
        self::assertTrue($lifecycleCopy['needsProfile']);
        self::assertSame(['loupe'], $simpleCopy['skillPackages']);
        self::assertFalse($simpleCopy['needsProfile']);
        self::assertArrayNotHasKey('epicBranch', $simpleCopy);
        self::assertSame((string) $templates->source('lifecycle')['version'], $this->binding($connection, $lifecycleId)['template_version']);
        $migrated = [$this->binding($connection, $lifecycleId), $this->binding($connection, $simpleId)];

        $this->migrate($connection);

        self::assertSame($migrated, [$this->binding($connection, $lifecycleId), $this->binding($connection, $simpleId)]);
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261103031358($connection, new NullLogger());
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
