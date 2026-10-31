<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261030235512;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261030235512.php';

final class LifecycleEpicBranchExistsMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_epic_branch_exists_condition_and_keeps_its_epic_branch_and_approval_rule(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-epic-exists');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = $this->row($connection, $projectId);
        $old['version'] = 32;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        $old['rules'] = self::withoutTheCondition($old['rules']);
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB), template_version = 32 WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);
        self::assertNotEquals($this->service(ShippedTemplates::class)->source('lifecycle')['rules'], $old['rules']);

        $migration = new Version20261030235512($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $projectId));
        self::assertSame($shipped['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$projectId]));
    }

    /**
     * @return list<mixed>
     */
    private static function withoutTheCondition(mixed $rules): array
    {
        self::assertIsArray($rules);
        $stripped = [];
        foreach ($rules as $rule) {
            if (\is_array($rule) && 'child-unblocked' === ($rule['id'] ?? null)) {
                $all = $rule['when']['all'] ?? null;
                self::assertIsArray($all);
                $rule['when']['all'] = array_values(array_filter($all, static fn (mixed $condition): bool => !\is_array($condition) || !isset($condition['card.parent.epic_branch.exists'])));
                self::assertCount(\count($all) - 1, $rule['when']['all']);
            }
            $stripped[] = $rule;
        }

        return $stripped;
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
