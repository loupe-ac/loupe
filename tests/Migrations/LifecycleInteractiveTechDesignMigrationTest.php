<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261030170512;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261030170512.php';

final class LifecycleInteractiveTechDesignMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_copy_gets_the_shipped_definition_and_version_32_and_keeps_its_two_project_keys_and_another_template_stays(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-interactive-td');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-interactive-td-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings
            SET template_version = 31,
                definition = jsonb_set(jsonb_set(jsonb_set(jsonb_set(definition, '{version}', '31'), '{epicBranch}', '\"feature/epic-{number}\"'), '{approvalCoversConflictResolution}', 'false'), '{workTimeoutMinutes}', '5')
            WHERE project_id = ?",
            [$lifecycleId],
        );
        $simpleBefore = $this->definition($connection, $simpleId);
        $simpleVersion = $this->templateVersion($connection, $simpleId);

        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        $after = $this->definition($connection, $lifecycleId);
        self::assertSame(32, $this->templateVersion($connection, $lifecycleId));
        self::assertSame(32, $after['version']);
        self::assertSame(120, $after['workTimeoutMinutes']);
        self::assertSame('feature/epic-{number}', $after['epicBranch']);
        self::assertFalse($after['approvalCoversConflictResolution']);
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $after);
        $ruleIds = array_column($after['rules'], 'id');
        self::assertContains('tech-design-session', $ruleIds);
        self::assertContains('tech-design-handover', $ruleIds);
        self::assertEquals($simpleBefore, $this->definition($connection, $simpleId));
        self::assertSame($simpleVersion, $this->templateVersion($connection, $simpleId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261030170512($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function templateVersion(Connection $connection, string $projectId): int
    {
        return (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]);
    }

    /** @return array<mixed> */
    private function definition(Connection $connection, string $projectId): array
    {
        $definition = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($definition);
        $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
