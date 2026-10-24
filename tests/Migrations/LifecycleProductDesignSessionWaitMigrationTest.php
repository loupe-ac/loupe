<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261024120100;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261024120100.php';

final class LifecycleProductDesignSessionWaitMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_session_wait_of_the_product_design_session_rule_and_keeps_its_project_settings(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-session-wait');
        $this->bind($project, 'lifecycle');
        $other = $this->workflowProject('migration-session-wait-other');
        $this->bind($other, 'simple');
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $otherId = ($other->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();

        $old = $this->row($connection, $projectId);
        $old['version'] = 19;
        $old['epicBranch'] = 'feature/epic-{number}';
        $old['approvalCoversConflictResolution'] = false;
        foreach ($old['rules'] as $index => $rule) {
            if ('product-design-session' === $rule['id']) {
                $old['rules'][$index]['when'] = ['not' => ['card.document.linked' => ['tag' => 'product-design']]];
                unset($old['rules'][$index]['then']['request']['cancelWhen'], $old['rules'][$index]['then']['request']['cancelOnly']);
            }
        }
        $oldJson = json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
        self::assertStringNotContainsString('session_open', json_encode($this->sessionRule($old), \JSON_THROW_ON_ERROR));
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = 19, definition = CAST(? AS JSONB) WHERE project_id = ?', [$oldJson, $projectId]);
        $otherBefore = $this->binding($connection, $otherId);

        $this->migrate($connection);

        $source = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$source, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false], $this->row($connection, $projectId));
        self::assertSame($source['version'], $this->binding($connection, $projectId)['template_version']);
        self::assertSame('open', $this->sessionRule($this->row($connection, $projectId))['then']['request']['cancelOnly'] ?? null);
        self::assertSame($otherBefore, $this->binding($connection, $otherId));
        $migrated = $this->binding($connection, $projectId);

        $this->migrate($connection);

        self::assertSame($migrated, $this->binding($connection, $projectId));
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function sessionRule(array $definition): array
    {
        self::assertIsArray($definition['rules']);
        foreach ($definition['rules'] as $rule) {
            if (\is_array($rule) && 'product-design-session' === ($rule['id'] ?? null)) {
                return $rule;
            }
        }
        self::fail('The definition has no product-design-session rule.');
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
        $migration = new Version20261024120100($connection, new NullLogger());
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
