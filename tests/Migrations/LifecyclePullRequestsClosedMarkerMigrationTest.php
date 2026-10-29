<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261029212421;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261029212421.php';

final class LifecyclePullRequestsClosedMarkerMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_without_the_marker_gets_the_shipped_copy_and_keeps_its_epic_branch_and_a_simple_binding_stays(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-closed-marker');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-closed-marker-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement(
            "UPDATE workflow_bindings SET definition = jsonb_set(jsonb_set(definition, '{markers}', (
                SELECT jsonb_agg(marker ORDER BY position)
                FROM jsonb_array_elements(definition->'markers') WITH ORDINALITY AS markers(marker, position)
                WHERE marker->>'key' <> 'pull-requests-closed'
            )), '{epicBranch}', '\"feature/epic-{number}\"') WHERE project_id = ?",
            [$lifecycleId],
        );
        self::assertNotContains('pull-requests-closed', $this->markerKeys($connection, $lifecycleId));
        $simpleBefore = $this->definition($connection, $simpleId);

        $this->migrate($connection);
        $this->migrate($connection);

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertContains('pull-requests-closed', $this->markerKeys($connection, $lifecycleId));
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}'], $this->definition($connection, $lifecycleId));
        self::assertEquals($simpleBefore, $this->definition($connection, $simpleId));
    }

    /** @return list<string> */
    private function markerKeys(Connection $connection, string $projectId): array
    {
        $keys = [];
        foreach ($this->definition($connection, $projectId)['markers'] as $marker) {
            $keys[] = $marker['key'];
        }

        return $keys;
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261029212421($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
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
