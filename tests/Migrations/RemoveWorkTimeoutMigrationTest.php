<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Command\PauseCardCommand;
use App\Module\Board\Command\PauseCardHandler;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Board\Repository\CardEventRepository;
use App\Module\Board\View\CardHistoryEntry;
use App\Module\Workflow\Command\BindWorkflowTemplateCommand;
use App\Module\Workflow\Contract\Actor;
use App\Module\Workflow\Contract\PauseKind;
use App\Module\Workflow\Template\ShippedTemplates;
use App\Tests\Module\Workflow\Action\ActionScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261031042556;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261031042556.php';

final class RemoveWorkTimeoutMigrationTest extends KernelTestCase
{
    use ActionScenario;

    public function test_a_binding_that_holds_the_work_timeout_gets_the_shipped_copy_and_keeps_its_epic_branch(): void
    {
        self::bootKernel();
        $lifecycle = $this->workflowProject('migration-no-timeout-lifecycle');
        $this->bindLifecycle($lifecycle);
        $simple = $this->workflowProject('migration-no-timeout-simple');
        $this->bindHandler()(new BindWorkflowTemplateCommand($simple, 'simple', []));
        $lifecycleId = ($lifecycle->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $simpleId = ($simple->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $this->store($connection, $lifecycleId, [...$this->row($connection, $lifecycleId), 'version' => 32, 'workTimeoutMinutes' => 120, 'epicBranch' => 'feature/epic-{number}']);
        $this->store($connection, $simpleId, [...array_diff_key($this->row($connection, $simpleId), ['epicBranch' => true]), 'version' => 10, 'workTimeoutMinutes' => 120]);
        $shipped = self::getContainer()->get(ShippedTemplates::class);
        self::assertInstanceOf(ShippedTemplates::class, $shipped);

        $this->migrate($connection);
        $this->migrate($connection);

        self::assertEquals([...$shipped->source('lifecycle'), 'epicBranch' => 'feature/epic-{number}'], $this->row($connection, $lifecycleId));
        self::assertEquals(array_diff_key($shipped->source('simple'), ['epicBranch' => true]), $this->row($connection, $simpleId));
        self::assertArrayNotHasKey('workTimeoutMinutes', $this->row($connection, $lifecycleId));
        self::assertSame($shipped->source('lifecycle')['version'], (int) $connection->fetchOne('SELECT template_version FROM workflow_bindings WHERE project_id = ?', [$lifecycleId]));
    }

    public function test_an_active_work_timeout_pause_ends_and_is_kept_as_a_stopped_worker(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-no-timeout-pause');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'next');
        $pause = self::getContainer()->get(PauseCardHandler::class)(new PauseCardCommand($card, 'no-bridge-took-work', 'implement', PauseKind::WorkStopped))
            ?? throw new \LogicException('The card had no pause.');
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE card_pauses SET kind = 'work-timeout' WHERE id = ?", [(string) $pause->id]);

        $this->migrate($connection);
        $this->migrate($connection);

        $row = $connection->fetchAssociative('SELECT kind, released_at, release_reason FROM card_pauses WHERE id = ?', [(string) $pause->id]);
        self::assertIsArray($row);
        self::assertSame('work-stopped', $row['kind']);
        self::assertNotNull($row['released_at']);
        self::assertSame('work-timeout-removed', $row['release_reason']);

        $this->em()->clear();
        $events = self::getContainer()->get(CardEventRepository::class);
        self::assertInstanceOf(CardEventRepository::class, $events);
        $stored = $this->em()->find(Card::class, $card->id) ?? throw new \LogicException('The card is stored.');
        $released = array_values(array_filter($events->findForCard($stored), static fn (CardEvent $event): bool => CardEventKind::PauseReleased === $event->kind));
        self::assertCount(1, $released);
        self::assertSame(Actor::System, $released[0]->actorKind);
        self::assertEquals(['kind' => 'work-timeout', 'reason' => 'no-bridge-took-work', 'ruleId' => 'implement', 'releaseReason' => 'work-timeout-removed'], $released[0]->detail);
        $entry = CardHistoryEntry::of($released[0]);
        self::assertSame('board.card.history.cause.pause_released', $entry->cause?->getMessage());
    }

    public function test_a_released_work_timeout_pause_gets_no_new_history_event(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-no-timeout-released');
        $this->bindLifecycle($project);
        $card = $this->card($project, 'next');
        $pause = self::getContainer()->get(PauseCardHandler::class)(new PauseCardCommand($card, 'no-bridge-took-work', 'implement', PauseKind::WorkStopped))
            ?? throw new \LogicException('The card had no pause.');
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE card_pauses SET kind = 'work-timeout', released_at = NOW(), release_reason = 'released-by-person' WHERE id = ?", [(string) $pause->id]);
        $before = (int) $connection->fetchOne("SELECT COUNT(*) FROM board_card_events WHERE card_id = ? AND kind = 'pause-released'", [(string) $card->id]);

        $this->migrate($connection);

        self::assertSame($before, (int) $connection->fetchOne("SELECT COUNT(*) FROM board_card_events WHERE card_id = ? AND kind = 'pause-released'", [(string) $card->id]));
        self::assertSame('released-by-person', $connection->fetchOne('SELECT release_reason FROM card_pauses WHERE id = ?', [(string) $pause->id]));
    }

    public function test_a_binding_of_another_template_is_left_alone(): void
    {
        self::bootKernel();
        $project = $this->workflowProject('migration-no-timeout-other');
        $this->bindLifecycle($project);
        $projectId = ($project->id ?? throw new \LogicException('The project is not flushed.'))->toRfc4122();
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE workflow_bindings SET template_key = 'custom' WHERE project_id = ?", [$projectId]);
        $before = $this->row($connection, $projectId);

        $this->migrate($connection);

        self::assertEquals($before, $this->row($connection, $projectId));
    }

    /** @param array<string, mixed> $definition */
    private function store(Connection $connection, string $projectId, array $definition): void
    {
        $connection->executeStatement(
            'UPDATE workflow_bindings SET definition = CAST(? AS JSONB) WHERE project_id = ?',
            [json_encode($definition, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION), $projectId],
        );
    }

    private function migrate(Connection $connection): void
    {
        $migration = new Version20261031042556($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
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
