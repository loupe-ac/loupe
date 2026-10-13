<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261013223009;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261013223009.php';

final class RunStoppedMarkerMigrationTest extends KernelTestCase
{
    use ActionScenario;

    #[DataProvider('templates')]
    public function test_a_binding_with_the_old_run_stopped_marker_gets_the_shipped_one_and_keeps_its_epic_branch(string $key): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-run-stopped-'.$key);
        if ('lifecycle' === $key) {
            $this->bindLifecycle($project);
        } else {
            $this->bindHandler()(new BindWorkflowTemplateCommand($project, $key, []));
        }
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = $this->row($connection, $projectId);
        $markers = $old['markers'];
        self::assertIsArray($markers);
        foreach ($markers as $index => $marker) {
            if (\is_array($marker) && 'run-stopped' === ($marker['key'] ?? null)) {
                $markers[$index]['when'] = ['card.run.stopped' => []];
            }
        }
        $old['markers'] = $markers;
        $old['epicBranch'] = 'feature/epic-{number}';
        $connection->executeStatement('UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId]);

        $migration = new Version20261013223009($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        self::assertEquals([...$this->service(ShippedTemplates::class)->source($key), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $projectId));
    }

    /** @return iterable<string, array{string}> */
    public static function templates(): iterable
    {
        yield 'lifecycle' => ['lifecycle'];
        yield 'simple' => ['simple'];
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
