<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Bridge\Repository\WorkerRunFactRepository;
use App\Module\Bridge\Service\WorkerRunFactWriter;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Workflow\Contract\Actor;
use App\Tests\Module\Bridge\BridgeScenario;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261125144845;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261125144845.php';

final class LegacyWaitingRunFactsMigrationTest extends KernelTestCase
{
    use BridgeScenario;

    public function test_a_fact_and_a_card_event_stored_as_waiting_on_the_forge_become_unfinished_and_others_stay(): void
    {
        self::bootKernel();
        $em = $this->em();
        $project = $this->project($em, $this->user($em, 'legacy-waiting-facts@example.com'), 'Legacy waiting facts');
        $legacy = $this->seedRun($em, $project, state: WorkerRunState::Unfinished, hasResult: true);
        $kept = $this->seedRun($em, $project, state: WorkerRunState::Blocked, hasResult: true);
        $column = new BoardColumn(project: $project, label: 'Next', slug: 'next', position: 0);
        $card = new Card(project: $project, column: $column, title: 'Legacy waiting', body: '', number: 1);
        $legacyEvent = new CardEvent($card, $project, CardEventKind::RunFinished, Actor::Agent, detail: ['runId' => (string) $legacy->id, 'state' => 'unfinished'], runId: $legacy->id);
        $keptEvent = new CardEvent($card, $project, CardEventKind::RunFinished, Actor::Agent, detail: ['runId' => (string) $kept->id, 'state' => 'blocked'], runId: $kept->id);
        foreach ([$column, $card, $legacyEvent, $keptEvent] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $writer = self::getContainer()->get(WorkerRunFactWriter::class);
        self::assertInstanceOf(WorkerRunFactWriter::class, $writer);
        $writer->upsert([$legacy->id ?? throw new \LogicException('A flushed run has an id.'), $kept->id ?? throw new \LogicException('A flushed run has an id.')]);
        $connection = $em->getConnection();
        $connection->executeStatement("UPDATE bridge_worker_run_facts SET outcome = 'waiting-on-forge' WHERE run_id = ?", [(string) $legacy->id]);
        $connection->executeStatement(
            "UPDATE board_card_events SET detail = jsonb_set(detail, '{state}', '\"waiting-on-forge\"') WHERE id = ?",
            [(string) $legacyEvent->id],
        );

        $migration = new Version20261125144845($connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }

        $facts = self::getContainer()->get(WorkerRunFactRepository::class);
        self::assertInstanceOf(WorkerRunFactRepository::class, $facts);
        $read = $facts->findByRunIds($project, [$legacy->id, $kept->id]);
        self::assertSame(WorkerRunState::Unfinished, $read[(string) $legacy->id]->outcome);
        self::assertSame(WorkerRunState::Blocked, $read[(string) $kept->id]->outcome);
        $eventState = static fn (CardEvent $event): mixed => $connection->fetchOne("SELECT detail->>'state' FROM board_card_events WHERE id = ?", [(string) $event->id]);
        self::assertSame('unfinished', $eventState($legacyEvent));
        self::assertSame('blocked', $eventState($keptEvent));
    }
}
