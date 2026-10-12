<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261012163510;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261012163510.php';

final class LifecycleAgentReviewOverrideMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_override_rules_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-review-override');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET definition = jsonb_set(definition, '{epicBranch}', '\"feature/epic-{number}\"') WHERE project_id = ?", [$projectId]);
        $connection->executeStatement("UPDATE workflow_bindings SET definition = replace(definition::text, 'agent_review.overridden', 'agent_review.passed')::jsonb WHERE project_id = ?", [$projectId]);
        self::assertStringNotContainsString('agent_review.overridden', $this->definitionText($connection, $projectId));

        $migration = new Version20261012163510($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertEquals([...$this->service(ShippedTemplates::class)->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], json_decode($this->definitionText($connection, $projectId), true, flags: \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('agent_review.overridden', $this->definitionText($connection, $projectId));
    }

    private function definitionText(Connection $connection, string $projectId): string
    {
        $definition = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($definition);

        return $definition;
    }
}
