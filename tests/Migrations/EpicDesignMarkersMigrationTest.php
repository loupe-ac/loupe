<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Workflow\Contract\CardsDueForMarkers;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Module\Workflow\Template\TemplateVersion;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261116165000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261116165000.php';

final class EpicDesignMarkersMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_lifecycle_binding_gets_the_shipped_copy_and_keeps_its_project_values(): void
    {
        self::bootKernel();
        $connection = $this->em()->getConnection();
        $project = $this->workflowProject('migration-epic-design');
        $this->bindLifecycle($project);
        $id = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection->executeStatement(
            <<<'SQL'
                UPDATE workflow_bindings
                SET template_version = 'stale',
                    definition = ((definition - 'markers') || '{"epicBranch": "feature/epic-{number}", "approvalCoversConflictResolution": false, "settingValues": {"required-checks": "all"}}'::jsonb)
                WHERE project_id = ?
                SQL,
            [$id],
        );

        $this->migrate();

        $shipped = $this->service(ShippedTemplates::class)->source('lifecycle');
        $row = $connection->fetchAssociative('SELECT definition, template_version FROM workflow_bindings WHERE project_id = ?', [$id]);
        self::assertIsArray($row);
        $definition = json_decode((string) $row['definition'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($definition);
        self::assertEquals([...$shipped, 'epicBranch' => 'feature/epic-{number}', 'approvalCoversConflictResolution' => false, 'settingValues' => ['required-checks' => 'all']], $definition);
        self::assertContains('waits-for-epic-design', array_column($definition['markers'], 'key'));
        self::assertContains('epic-design-changes-requested', array_column($definition['markers'], 'key'));
        self::assertSame(TemplateVersion::of($shipped), $row['template_version']);
    }

    public function test_an_open_card_is_due_and_a_closed_card_is_not(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-epic-design-due');
        $this->bindLifecycle($project);
        $open = $this->card($project, 'next');
        $closed = $this->card($project, 'done');
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE board_cards SET inbox_markers = '[]', workflow_markers_due_at = now() + interval '1 day' WHERE id = ?", [(string) $open->id]);
        $connection->executeStatement('UPDATE board_cards SET workflow_markers_due_at = NULL WHERE id = ?', [(string) $closed->id]);
        $due = $this->service(CardsDueForMarkers::class);
        self::assertNotContains((string) $open->id, $due->findDueCardIds(new \DateTimeImmutable(), 10_000));

        $this->migrate();

        self::assertContains((string) $open->id, $due->findDueCardIds(new \DateTimeImmutable(), 10_000));
        self::assertNull($connection->fetchOne('SELECT workflow_markers_due_at FROM board_cards WHERE id = ?', [(string) $closed->id]));
    }

    private function migrate(): void
    {
        $connection = $this->em()->getConnection();
        $migration = new Version20261116165000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
