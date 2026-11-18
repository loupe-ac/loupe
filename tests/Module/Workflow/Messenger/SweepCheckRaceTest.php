<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Messenger;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPause;
use App\Module\Board\Repository\CardPauseRepository;
use App\Module\Workflow\Engine\Engine;
use App\Module\Workflow\Entity\WorkflowBinding;
use App\Module\Workflow\Entity\WorkflowSlotLink;
use App\Module\Workflow\Messenger\EvaluateCard;
use App\Module\Workflow\Messenger\EvaluateCardHandler;
use App\Module\Workflow\Repository\WorkflowCardChangeRepository;
use App\Tests\Module\Workflow\Action\ActionScenario;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * A second session commits a card change while a sweep check of the card runs. The rows commit,
 * because the second session cannot see rows that the test transaction holds, so tearDown deletes them.
 */
#[SkipDatabaseRollback]
final class SweepCheckRaceTest extends KernelTestCase
{
    use ActionScenario;

    /** Opens a work request on the card, which the marker reads through a query in every build of the facts. */
    public const string OPEN_REQUEST = "INSERT INTO work_requests (id, state, kind, rule_id, created_at, project_id, subject_type, subject_id, card_number)
        SELECT gen_random_uuid(), 'open', 'other', 'other', LOCALTIMESTAMP(0), c.project_id, 'card', c.id, c.number FROM board_cards c WHERE c.id = ?";

    private Connection $connection;

    private ?Connection $other = null;

    private Card $card;

    private Uuid $cardId;

    private TestHandler $records;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = $this->em()->getConnection();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $this->records = new TestHandler();
        $logger->pushHandler($this->records);

        $this->card = $this->pausedCard();
        $this->cardId = $this->card->id ?? throw new \LogicException('A flushed card has an id.');
        $this->service(WorkflowCardChangeRepository::class)->clear($this->cardId);
        $this->records->clear();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->other?->close();
        $card = $this->cardId->toRfc4122();
        $project = (string) $this->card->project->id;
        $owner = (string) $this->card->project->owner->id;
        $this->connection->executeStatement('SELECT pg_advisory_unlock_all()');
        $this->connection->executeStatement('DELETE FROM outbox_events WHERE project_id = ?', [$project]);
        $this->connection->executeStatement('DELETE FROM work_requests WHERE project_id = ?', [$project]);
        $this->connection->executeStatement('DELETE FROM board_cards WHERE project_id = ?', [$project]);
        $this->connection->executeStatement('DELETE FROM projects WHERE id = ?', [$project]);
        $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$owner]);
        $this->connection->executeStatement('DELETE FROM workflow_card_changes WHERE card_id = ?', [$card]);
        $this->connection->executeStatement('DELETE FROM workflow_project_changes WHERE project_id = ?', [$project]);

        parent::tearDown();
    }

    public function test_a_sweep_check_with_no_writer_releases_the_pause_and_reports_nothing(): void
    {
        $this->handler()(new EvaluateCard($this->cardId->toRfc4122(), fullSweep: true));

        // Guard: the pass ran and released the pause, so the quiet log below is the pass's own result.
        self::assertSame(0, $this->activePauses());
        self::assertSame([], $this->messages('workflow.change_missed'));
        self::assertSame([], $this->messages('workflow.change_after_late_mark'));
        self::assertSame([], $this->requeued());
    }

    public function test_a_writer_that_commits_during_a_sweep_check_requeues_the_check_and_raises_no_miss(): void
    {
        $this->writeTypeBeforePauseRelease();

        $this->handler()(new EvaluateCard($this->cardId->toRfc4122(), fullSweep: true));

        self::assertSame(1, (int) $this->otherConnection()->fetchOne("SELECT count(*) FROM work_requests WHERE subject_id = ? AND state = 'open'", [$this->cardId->toRfc4122()]));
        self::assertSame(1, (int) $this->otherConnection()->fetchOne('SELECT count(*) FROM workflow_card_changes WHERE card_id = ?', [$this->cardId->toRfc4122()]));
        self::assertSame([], $this->messages('workflow.change_missed'));
        self::assertCount(1, $this->messages('workflow.sweep_check_requeued'));
        $requeued = $this->requeued();
        self::assertCount(1, $requeued);
        self::assertSame($this->cardId->toRfc4122(), $requeued[0]->cardId);
        self::assertTrue($requeued[0]->fullSweep);
    }

    public function test_a_failed_sweep_check_releases_the_card_lock(): void
    {
        $this->writeTypeBeforePauseRelease();

        try {
            $this->service(Engine::class)->evaluate($this->cardId, new \DateTimeImmutable(), reportMiss: true);
            self::fail('The sweep check finished although a writer committed during it.');
        } catch (DeadlockException $e) {
            self::assertSame('40001', $e->getSQLState());
        }

        self::assertTrue($this->otherConnection()->fetchOne('SELECT pg_try_advisory_lock(hashtext(?))', ['workflow_card:'.$this->cardId->toRfc4122()]));
        $this->otherConnection()->executeStatement('SELECT pg_advisory_unlock(hashtext(?))', ['workflow_card:'.$this->cardId->toRfc4122()]);
    }

    public function test_a_sweep_check_that_waits_for_the_card_lock_reads_the_commit_of_the_session_that_held_it(): void
    {
        $holder = $this->lockHolder();

        $this->service(Engine::class)->evaluate($this->cardId, new \DateTimeImmutable(), reportMiss: true);

        self::assertSame(0, proc_close($holder), 'The second session held the card lock until the check waited for it.');
        // No rule of the template asks for the request, so a check that reads it cancels it.
        self::assertSame(
            ['cancelled'],
            $this->otherConnection()->fetchFirstColumn('SELECT state FROM work_requests WHERE subject_id = ?', [$this->cardId->toRfc4122()]),
            'The check read the request that the second session committed.',
        );
    }

    /** The release rule was true before the pause, so the sweep check releases the pause and changes no rule state. */
    private function pausedCard(): Card
    {
        $project = $this->workflowProject('sweep-race');
        $this->em()->persist(new WorkflowBinding($project, 'test', '1', [
            'key' => 'test',
            'version' => 1,
            'defaultType' => 'feature',
            'types' => [
                ['key' => 'feature', 'label' => 'board.card.type.feature', 'tone' => 'lime'],
                ['key' => 'bug', 'label' => 'board.card.type.bug', 'tone' => 'amber'],
                ['key' => 'epic', 'label' => 'board.card.type.epic', 'tone' => 'blue', 'capabilities' => ['children', 'lane']],
            ],
            'slots' => [['key' => 'one', 'label' => 'one']],
            'manualMoves' => [],
            'backoffMinutes' => [10],
            'rules' => [
                ['id' => 'unhold', 'when' => ['card.type' => ['types' => ['bug']]], 'then' => ['release' => ['reason' => 'on-hold']]],
                ['id' => 'hold', 'slot' => 'one', 'when' => ['card.type' => ['types' => ['bug']]], 'then' => ['pause' => ['reason' => 'on-hold', 'until' => ['card.type' => ['types' => ['epic']]]]]],
            ],
            'markers' => [
                ['key' => 'work-requested', 'kind' => 'working', 'label' => 'marker.work_requested', 'when' => ['card.run.work_open' => []]],
            ],
        ]));
        $this->em()->persist(new WorkflowSlotLink($project, 'one', $this->column($project, 'next')->id));
        $this->em()->flush();
        $card = $this->card($project, 'next');
        $cardId = $card->id ?? throw new \LogicException('A flushed card has an id.');
        // A pause ends its pass, so the first pass stores the states of the rules after it.
        $this->service(Engine::class)->evaluate($cardId, new \DateTimeImmutable());
        $card->type = 'bug';
        $this->em()->flush();

        $this->service(Engine::class)->evaluate($cardId, new \DateTimeImmutable());

        self::assertSame(1, $this->activePauses($card));
        self::assertSame('[]', $this->connection->fetchOne('SELECT workflow_markers FROM board_cards WHERE id = ?', [$cardId->toRfc4122()]));

        return $card;
    }

    /** The second session commits a request and its mark after the check read the mark, and before the check writes the release. */
    private function writeTypeBeforePauseRelease(): void
    {
        $other = $this->otherConnection();
        $cardId = $this->cardId->toRfc4122();
        $this->em()->getEventManager()->addEventListener(Events::preUpdate, new class($other, $cardId) {
            private bool $written = false;

            public function __construct(
                private readonly Connection $other,
                private readonly string $cardId,
            ) {
            }

            public function preUpdate(PreUpdateEventArgs $args): void
            {
                if ($this->written || !$args->getObject() instanceof CardPause) {
                    return;
                }
                $this->written = true;
                $this->other->executeStatement(SweepCheckRaceTest::OPEN_REQUEST, [$this->cardId]);
            }
        });
    }

    /**
     * A child process holds the card lock and commits a work request once a check waits for the lock.
     *
     * @return resource
     */
    private function lockHolder()
    {
        $params = $this->connection->getParams();
        $script = <<<'PHP'
            [$dsn, $user, $password, $card, $request] = array_slice($argv, 1);
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('BEGIN');
            $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['workflow_card:'.$card]);
            fwrite(STDOUT, "locked\n");
            $waiting = $pdo->prepare("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND NOT granted AND database = (SELECT oid FROM pg_database WHERE datname = current_database())");
            for ($try = 0; $try < 200; ++$try) {
                $waiting->execute();
                if ($waiting->fetchColumn() > 0) {
                    break;
                }
                usleep(25000);
            }
            $pdo->prepare($request)->execute([$card]);
            $pdo->exec('COMMIT');
            exit($try < 200 ? 0 : 1);
            PHP;
        $dsn = \sprintf('pgsql:host=%s;port=%d;dbname=%s', $params['host'] ?? 'localhost', $params['port'] ?? 5432, $params['dbname'] ?? '');
        $holder = proc_open([\PHP_BINARY, '-r', $script, '--', $dsn, $params['user'] ?? '', $params['password'] ?? '', $this->cardId->toRfc4122(), self::OPEN_REQUEST], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($holder);
        self::assertSame("locked\n", fgets($pipes[1]));

        return $holder;
    }

    private function otherConnection(): Connection
    {
        return $this->other ??= DriverManager::getConnection($this->connection->getParams());
    }

    private function handler(): EvaluateCardHandler
    {
        return $this->service(EvaluateCardHandler::class);
    }

    private function activePauses(?Card $card = null): int
    {
        return \count($this->service(CardPauseRepository::class)->findBy(['card' => $card ?? $this->card, 'releasedAt' => null]));
    }

    /** @return list<LogRecord> */
    private function messages(string $message): array
    {
        return array_values(array_filter($this->records->getRecords(), static fn (LogRecord $record): bool => $message === $record->message));
    }

    /** @return list<EvaluateCard> */
    private function requeued(): array
    {
        $transport = self::getContainer()->get('messenger.transport.card_sweep');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof EvaluateCard));
    }
}
