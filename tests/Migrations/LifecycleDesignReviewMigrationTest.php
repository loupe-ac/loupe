<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261016205445;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261016205445.php';

final class LifecycleDesignReviewMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_without_the_design_review_rules_gets_the_shipped_copy_and_keeps_its_epic_branch_and_a_simple_binding_stays_unchanged(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-design-review');
        $this->bindLifecycle($project);
        $simple = $this->workflowProject('migration-design-review-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = $this->row($connection, $projectId);
        $rules = $old['rules'];
        self::assertIsArray($rules);
        $added = ['product-design-review', 'product-design-review-fix', 'product-design-review-publish', 'tech-design-review', 'tech-design-review-fix', 'tech-design-review-publish'];
        $old['rules'] = array_values(array_filter($rules, static fn (mixed $rule): bool => !\is_array($rule) || !\in_array($rule['id'] ?? null, $added, true)));
        self::assertCount(\count($rules) - 6, $old['rules']);
        $old['markers'] = array_values(array_filter($old['markers'], static fn (mixed $marker): bool => !\is_array($marker) || 'design-review-running' !== ($marker['key'] ?? null)));
        $old['epicBranch'] = 'feature/epic-{number}';
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);

        $simpleBefore = $this->binding($connection, $simpleId);

        $migration = new Version20261016205445($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertEquals([...$this->service(ShippedTemplates::class)->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $projectId));
        self::assertSame($simpleBefore, $this->binding($connection, $simpleId));
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
