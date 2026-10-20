<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261020151431;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261020151431.php';

final class LifecycleDraftWaitsMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_with_the_old_reviewable_rule_gets_the_shipped_copy_and_keeps_its_project_settings_and_a_simple_binding_stays_unchanged(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-draft-waits');
        $this->bindLifecycle($project);
        $simple = $this->workflowProject('migration-draft-waits-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = $this->row($connection, $projectId);
        $rules = $old['rules'];
        self::assertIsArray($rules);
        $old['rules'] = array_map(static function (mixed $rule): mixed {
            if (!\is_array($rule) || 'reviewable' !== ($rule['id'] ?? null)) {
                return $rule;
            }
            $rule['when']['all'] = array_values(array_filter($rule['when']['all'], static fn (mixed $condition): bool => $condition !== ['not' => ['card.pr.draft_open' => []]]));

            return $rule;
        }, $rules);
        $old['version'] = 11;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);

        $simpleBefore = $this->binding($connection, $simpleId);

        $this->migrate($connection);

        $expected = [...$this->service(ShippedTemplates::class)->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false];
        self::assertEquals($expected, $this->row($connection, $projectId));
        self::assertSame($simpleBefore, $this->binding($connection, $simpleId));
        $migrated = $this->binding($connection, $projectId);

        $this->migrate($connection);

        self::assertSame($migrated, $this->binding($connection, $projectId));
        self::assertSame($simpleBefore, $this->binding($connection, $simpleId));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261020151431($connection, new NullLogger());
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
