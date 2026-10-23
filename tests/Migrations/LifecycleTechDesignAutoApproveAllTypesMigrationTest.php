<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261023200525;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261023200525.php';

final class LifecycleTechDesignAutoApproveAllTypesMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_loses_the_card_type_condition_of_the_tech_design_auto_approve_rule_and_keeps_its_project_settings(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-auto-approve-types');
        $this->bind($project, 'lifecycle');
        $other = $this->workflowProject('migration-auto-approve-types-other');
        $this->bind($other, 'simple');
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $otherId = ($other->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        $old = $this->row($connection, $projectId);
        $old['version'] = 18;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        foreach ($old['rules'] as $index => $rule) {
            if ('tech-design-auto-approve' === $rule['id']) {
                array_unshift($old['rules'][$index]['when']['all'], ['card.type' => ['types' => ['bug', 'tooling', 'docs']]]);
            }
        }
        $oldJson = json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        self::assertStringContainsString('tooling', json_encode($old['rules'], \JSON_THROW_ON_ERROR));
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = 18, definition = CAST(? AS JSONB) WHERE project_id = ?', [$oldJson, $projectId]);
        $otherBefore = $this->binding($connection, $otherId);

        $this->migrate($connection);

        $source = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$source, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $projectId));
        self::assertSame($source['version'], $this->binding($connection, $projectId)['template_version']);
        self::assertStringNotContainsString('tooling', (string) $connection->fetchOne('SELECT definition->>\'rules\' FROM workflow_bindings WHERE project_id = ?', [$projectId]));
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
        $migration = new Version20261023200525($connection, new NullLogger());
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
