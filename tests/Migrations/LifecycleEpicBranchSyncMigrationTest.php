<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261016134952;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261016134952.php';

final class LifecycleEpicBranchSyncMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_with_no_epic_branch_sync_rule_gets_the_shipped_copy_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-epic-sync');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = $this->row($connection, $projectId);
        $rules = $old['rules'];
        self::assertIsArray($rules);
        $old['rules'] = array_values(array_filter($rules, static fn (mixed $rule): bool => !\is_array($rule) || 'epic-branch-sync' !== ($rule['id'] ?? null)));
        self::assertCount(\count($rules) - 1, $old['rules']);
        $old['version'] = 2;
        $old['epicBranch'] = 'feature/epic-{number}';
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);

        $migration = new Version20261016134952($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertEquals([...$this->service(ShippedTemplates::class)->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $projectId));
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
