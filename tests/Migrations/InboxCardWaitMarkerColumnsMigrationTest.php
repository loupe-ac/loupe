<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Board\Entity\CardPause;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\ValueObject\WorkerRunState;
use App\Module\Bridge\ValueObject\WorkSubject;
use App\Module\Inbox\Entity\InboxCardWatch;
use App\Module\Inbox\Entity\InboxItem;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\PauseKind;
use App\Tests\Module\Inbox\InboxFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261022013012;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

require_once __DIR__.'/../../migrations/Version20261022013012.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class InboxCardWaitMarkerColumnsMigrationTest extends KernelTestCase
{
    use InboxFixtures;

    private EntityManagerInterface $em;
    private Connection $connection;
    private string $watchId;
    private Project $project;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();

        $project = $this->project($em, $this->owner($em, 'wait-columns'), 'wait-columns');
        $this->project = $project;
        $item = new InboxItem(project: $project, number: 1, kind: InboxItemKind::Wait, title: '#1 Ship it', blocking: true);
        $em->persist($item);
        $watch = new InboxCardWatch($item, Uuid::v7(), 1);
        $em->persist($watch);
        $em->flush();
        $this->watchId = (string) $watch->id;
    }

    /** @param array{string, string, string} $row trigger, type and the expected marker key */
    #[TestWith([['pull-request-ready', 'pull-request', 'waits-for-approval']])]
    #[TestWith([['pull-request-fix-stopped', 'pull-request', 'waits-for-approval']])]
    #[TestWith([['document-in-review', 'document', 'document-in-review']])]
    #[TestWith([['run-blocked', 'worker-run', 'run-needs-you']])]
    #[TestWith([['run-gave-up', 'worker-run', 'run-needs-you']])]
    #[TestWith([['run-waiting-for-person', 'worker-run', 'run-needs-you']])]
    #[TestWith([['card-paused', 'card-pause', 'paused']])]
    public function test_up_fills_the_marker_key_of_an_existing_wait_from_its_trigger(array $row): void
    {
        [$trigger, $type, $markerKey] = $row;
        $this->migrate(down: true);
        $id = $this->insertLegacy($trigger, $type);

        $this->migrate();

        $stored = $this->connection->fetchAssociative('SELECT marker_key, label, params, spell_started_at, "trigger" FROM inbox_card_waits WHERE id = :id', ['id' => $id]);
        self::assertIsArray($stored);
        self::assertSame($markerKey, $stored['marker_key']);
        self::assertSame('', $stored['label']);
        self::assertSame('{}', $stored['params']);
        self::assertNull($stored['spell_started_at']);
        self::assertSame($trigger, $stored['trigger']);
    }

    /** @param array{string, ?string, string, string} $row type, a state or kind of the thing, the expected trigger and reason */
    #[TestWith([['pull-request', null, 'pull-request-ready', 'waiting-for-review']])]
    #[TestWith([['document', null, 'document-in-review', 'waiting-for-review']])]
    #[TestWith([['worker-run', 'blocked', 'run-blocked', 'blocked']])]
    #[TestWith([['worker-run', 'gave-up', 'run-gave-up', 'gave-up']])]
    #[TestWith([['worker-run', 'waiting-for-person', 'run-waiting-for-person', 'waits-for-person']])]
    #[TestWith([['worker-run', 'missing', 'run-waiting-for-person', 'waits-for-person']])]
    #[TestWith([['card-pause', 'rule', 'card-paused', 'rule']])]
    #[TestWith([['card-pause', 'work-stopped', 'card-paused', 'work-stopped']])]
    #[TestWith([['card-pause', 'missing', 'card-paused', 'work-stopped']])]
    public function test_an_insert_that_leaves_out_the_trigger_and_the_reason_gets_both_from_the_wait(array $row): void
    {
        [$type, $thing, $trigger, $reason] = $row;
        $columns = ['type' => $type, 'marker_key' => 'any-marker'];
        if ('worker-run' === $type) {
            $columns['run_id'] = $this->storedRun(WorkerRunState::tryFrom((string) $thing));
        } elseif ('card-pause' === $type) {
            $columns['pause_id'] = $this->storedPause(PauseKind::tryFrom((string) $thing));
        }

        $stored = $this->insert($columns);

        self::assertSame($trigger, $stored['trigger']);
        self::assertSame($reason, $stored['reason']);
        self::assertSame('any-marker', $stored['marker_key']);
    }

    /** @param array{string, string, string} $row trigger, type and the expected marker key */
    #[TestWith([['pull-request-ready', 'pull-request', 'waits-for-approval']])]
    #[TestWith([['document-in-review', 'document', 'document-in-review']])]
    #[TestWith([['run-gave-up', 'worker-run', 'run-needs-you']])]
    #[TestWith([['card-paused', 'card-pause', 'paused']])]
    public function test_an_insert_of_the_previous_code_gets_its_marker_key_from_its_trigger(array $row): void
    {
        [$trigger, $type, $markerKey] = $row;

        $stored = $this->insert(['type' => $type, 'trigger' => $trigger, 'reason' => 'waiting-for-review']);

        self::assertSame($markerKey, $stored['marker_key']);
        self::assertSame($trigger, $stored['trigger']);
        self::assertSame('waiting-for-review', $stored['reason']);
    }

    public function test_an_insert_that_sets_the_trigger_and_the_reason_keeps_them(): void
    {
        $stored = $this->insert(['type' => 'document', 'marker_key' => 'document-in-review', 'trigger' => 'run-blocked', 'reason' => 'blocked']);

        self::assertSame('run-blocked', $stored['trigger']);
        self::assertSame('blocked', $stored['reason']);
    }

    public function test_a_card_wait_has_no_trigger_to_fill_so_its_insert_fails(): void
    {
        $this->expectException(NotNullConstraintViolationException::class);
        $this->insert(['type' => 'card', 'marker_key' => 'nothing-happened', 'spell_started_at' => '2026-10-20 09:00:00']);
    }

    public function test_down_drops_the_trigger_the_function_and_the_columns(): void
    {
        $this->migrate(down: true);

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_proc WHERE proname = 'inbox_card_waits_fill'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_trigger WHERE tgname = 'inbox_card_waits_fill'"));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inbox_card_waits' AND column_name IN ('marker_key', 'label', 'params', 'spell_started_at')"));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'inbox_card_waits' AND column_name IN ('trigger', 'reason')"));
    }

    /**
     * @param array<string, mixed> $columns
     *
     * @return array<string, mixed>
     */
    private function insert(array $columns): array
    {
        $columns = ['id' => (string) Uuid::v7(), 'watch_id' => $this->watchId, 'started_at' => '2026-10-20 09:00:00', ...$columns];
        $names = implode(', ', array_map(static fn (string $name): string => '"'.$name.'"', array_keys($columns)));
        $this->connection->executeStatement(\sprintf('INSERT INTO inbox_card_waits (%s) VALUES (%s)', $names, implode(', ', array_map(static fn (string $name): string => ':'.$name, array_keys($columns)))), $columns);

        $stored = $this->connection->fetchAssociative('SELECT * FROM inbox_card_waits WHERE id = :id', ['id' => $columns['id']]);
        self::assertIsArray($stored);

        return $stored;
    }

    private function insertLegacy(string $trigger, string $type): string
    {
        $id = (string) Uuid::v7();
        $this->connection->executeStatement(
            'INSERT INTO inbox_card_waits (id, watch_id, type, "trigger", reason, started_at) VALUES (:id, :watch, :type, :trigger, :reason, :started)',
            ['id' => $id, 'watch' => $this->watchId, 'type' => $type, 'trigger' => $trigger, 'reason' => 'waiting-for-review', 'started' => '2026-10-20 09:00:00'],
        );

        return $id;
    }

    private function storedRun(?WorkerRunState $state): string
    {
        if (null === $state) {
            return (string) Uuid::v7();
        }
        $run = new WorkerRun($this->project, Uuid::v7(), WorkSubject::CARD, Uuid::v7(), 1, 'plan', $state);
        $this->em->persist($run);
        $this->em->flush();

        return (string) $run->id;
    }

    private function storedPause(?PauseKind $kind): string
    {
        if (null === $kind) {
            return (string) Uuid::v7();
        }
        $card = $this->card($this->em, $this->project);
        $pause = new CardPause($card, $this->project, 'owner-review', 'review-rule', $kind, new \DateTimeImmutable());
        $this->em->persist($pause);
        $this->em->flush();

        return (string) $pause->id;
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261022013012($this->connection, new NullLogger());
        if ($down) {
            $migration->down(new Schema());
        } else {
            $migration->up(new Schema());
        }
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
