<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261011221500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261011221500.php';

final class LifecycleReviewRetryMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_review_retry_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-review-retry');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET definition = jsonb_set(jsonb_set(definition, '{onWorkFailed,retryOn}', '[\"failed\",\"timeout\"]'), '{epicBranch}', '\"feature/epic-{number}\"') WHERE project_id = ?", [$projectId]);

        $migration = new Version20261011221500($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $definition = $this->definition($connection, $projectId);
        self::assertEquals([...$this->service(ShippedTemplates::class)->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $definition);
        self::assertContains('still-true', $definition['onWorkFailed']['retryOn']);
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
