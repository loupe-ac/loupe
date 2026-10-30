<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\CardValueType;
use App\Module\Workflow\Template\TemplateParser;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261030163902;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261030163902.php';

final class LifecycleValuesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_copy_gets_the_pinned_values_and_version_and_keeps_every_other_key_and_another_template_stays(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-values');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-values-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings
            SET template_version = 30,
                definition = jsonb_set(jsonb_set(jsonb_set(definition - 'values', '{version}', '30'), '{epicBranch}', '\"feature/epic-{number}\"'), '{approvalCoversConflictResolution}', 'true')
            WHERE project_id = ?",
            [$lifecycleId],
        );
        $before = $this->definition($connection, $lifecycleId);
        self::assertArrayNotHasKey('values', $before);
        self::assertSame(30, $before['version']);
        $simpleBefore = $this->definition($connection, $simpleId);
        $simpleVersion = $this->templateVersion($connection, $simpleId);

        $this->up($connection);

        $after = $this->definition($connection, $lifecycleId);
        self::assertSame(31, $this->templateVersion($connection, $lifecycleId));
        self::assertSame(31, $after['version']);
        self::assertEquals([[
            'key' => 'tech-design-interactive',
            'type' => 'bool',
            'label' => 'workflow.value.tech_design_interactive.label',
            'caption' => 'workflow.value.tech_design_interactive.caption',
            'help' => 'workflow.value.tech_design_interactive.help',
            'history' => 'workflow.value.tech_design_interactive.history',
        ]], $after['values']);
        self::assertSame('feature/epic-{number}', $after['epicBranch']);
        self::assertTrue($after['approvalCoversConflictResolution']);
        unset($after['values'], $before['values']);
        $after['version'] = $before['version'];
        self::assertEquals($before, $after);
        self::assertEquals($simpleBefore, $this->definition($connection, $simpleId));
        self::assertSame($simpleVersion, $this->templateVersion($connection, $simpleId));

        $stored = $this->service(TemplateParser::class)->parseStored($this->definition($connection, $lifecycleId));
        self::assertCount(1, $stored->values);
        self::assertSame('tech-design-interactive', $stored->values[0]->key);
        self::assertSame(CardValueType::Bool, $stored->values[0]->type);
    }

    public function test_down_removes_the_values_and_restores_the_previous_version(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-values-down');
        $this->bindLifecycle($lifecycle);
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->up($connection);

        $migration = new Version20261030163902($connection, new NullLogger());
        $migration->down(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $definition = $this->definition($connection, $lifecycleId);
        self::assertArrayNotHasKey('values', $definition);
        self::assertSame(30, $definition['version']);
        self::assertSame(30, $this->templateVersion($connection, $lifecycleId));
    }

    private function up(Connection $connection): void
    {
        $migration = new Version20261030163902($connection, new NullLogger());
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
