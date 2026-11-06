<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261106030000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261106030000.php';

final class RecoveryRenameMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_stored_repair_request_its_open_prompt_and_the_binding_copy_take_the_recovery_names(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-recovery-rename');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'in-progress');
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        $prompt = "Use the loupe-stage-repair skill.\nCard {$card->number} in project loupe.";
        $open = new WorkRequest($project, WorkSubject::CARD, $cardId, $card->number, 'repair', null, 'work', new \DateTimeImmutable('2026-11-06 12:00:00'));
        $open->prompt = $prompt;
        $settled = new WorkRequest($project, WorkSubject::CARD, $cardId, $card->number, 'repair', null, 'work', new \DateTimeImmutable('2026-11-06 11:00:00'));
        $settled->prompt = $prompt;
        $settled->withdraw(WorkRequestState::Cancelled, new \DateTimeImmutable('2026-11-06 11:30:00'));
        $this->em()->persist($open);
        $this->em()->persist($settled);
        $this->em()->flush();
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $old = $this->definition($connection, $projectId);
        $old['onWorkFailed'] = ['retryOn' => ['failed'], 'retries' => 3, 'backoffMinutes' => [2, 3, 5], 'repair' => ['kind' => 'repair']];
        $old['epicBranch'] = 'feature/epic-{number}';
        $connection->executeStatement('UPDATE workflow_bindings SET template_version = 1, definition = CAST(? AS JSONB) WHERE project_id = ?', [json_encode($old, \JSON_THROW_ON_ERROR), $projectId]);

        $this->migrate($connection);

        $source = $this->service(ShippedTemplates::class)->source('lifecycle');
        self::assertEquals([...$source, 'epicBranch' => 'feature/epic-{number}'], $this->definition($connection, $projectId));
        self::assertSame(2, $connection->fetchOne("SELECT COUNT(*) FROM work_requests WHERE kind = 'recover' AND subject_id = ?", [$cardId->toRfc4122()]));
        self::assertSame("Use the loupe-stage-recover skill.\nCard {$card->number} in project loupe.", $connection->fetchOne('SELECT prompt FROM work_requests WHERE id = ?', [$open->id?->toRfc4122()]));
        self::assertSame($prompt, $connection->fetchOne('SELECT prompt FROM work_requests WHERE id = ?', [$settled->id?->toRfc4122()]));
        self::assertSame(0, $connection->fetchOne("SELECT COUNT(*) FROM work_requests WHERE kind = 'repair'"));
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261106030000($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return array<string, mixed> */
    private function definition(Connection $connection, string $projectId): array
    {
        $definition = $connection->fetchOne('SELECT definition FROM workflow_bindings WHERE project_id = ?', [$projectId]);
        self::assertIsString($definition);
        $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
