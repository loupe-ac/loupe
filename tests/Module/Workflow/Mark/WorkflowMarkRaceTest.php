<?php

declare(strict_types=1);

namespace App\Tests\Module\Workflow\Mark;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * A check deletes the mark of its card before it reads the facts, and a writer marks the card through a trigger.
 * Each side runs on a real connection of its own, outside the test transaction, so the lock between them is real.
 * A probe table carries the trigger function of the card tables. The test commits, so tearDown removes its rows.
 */
final class WorkflowMarkRaceTest extends KernelTestCase
{
    private const string CLEAR = 'DELETE FROM workflow_card_changes WHERE card_id = :card';

    private const string PROBE = 'workflow_mark_race_probe';

    private Connection $check;
    private Connection $writer;
    private string $card;

    /** @var array{host: string, port: int, user: string, password: string, dbname: string} */
    private array $params;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $params = $connection->getParams();
        $this->params = ['host' => self::text($params, 'host'), 'port' => (int) self::text($params, 'port'), 'user' => self::text($params, 'user'), 'password' => self::text($params, 'password'), 'dbname' => self::text($params, 'dbname')];
        $this->check = DriverManager::getConnection([...$this->params, 'driver' => 'pdo_pgsql']);
        $this->writer = DriverManager::getConnection([...$this->params, 'driver' => 'pdo_pgsql']);
        $this->card = Uuid::v4()->toRfc4122();

        $this->dropProbe();
        $this->check->executeStatement(\sprintf('CREATE TABLE %s (id SERIAL PRIMARY KEY, card_id UUID NOT NULL)', self::PROBE));
        $this->check->executeStatement(\sprintf(
            "CREATE TRIGGER workflow_mark_%1\$s AFTER INSERT OR UPDATE OR DELETE ON %1\$s FOR EACH ROW EXECUTE FUNCTION workflow_mark_card('')",
            self::PROBE,
        ));
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ([$this->check ?? null, $this->writer ?? null] as $connection) {
            if (null !== $connection && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        if (isset($this->check)) {
            $this->dropProbe();
            $this->check->executeStatement('DELETE FROM workflow_card_changes WHERE card_id = ?', [$this->card]);
            $this->check->close();
            $this->writer->close();
        }

        parent::tearDown();
    }

    #[Test]
    public function a_write_that_meets_the_open_delete_of_the_check_waits_and_then_marks_the_card(): void
    {
        $this->check->executeStatement('INSERT INTO workflow_card_changes (card_id, changed_at) VALUES (?, ?)', [$this->card, '2020-01-01 00:00:00']);

        $this->check->beginTransaction();
        self::assertSame(1, $this->check->executeStatement(self::CLEAR, ['card' => $this->card]));

        $process = $this->startWriter();
        try {
            self::assertTrue($this->writerWaitsForALock(), 'The writer waits for the check, which holds the mark row.');
            self::assertSame(0, (int) $this->writer->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', self::PROBE)), 'The write has not committed while the check is open.');

            $this->check->commit();
            $process->wait();
        } finally {
            $process->isRunning() && $process->stop();
        }

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertSame(1, (int) $this->check->fetchOne(\sprintf('SELECT COUNT(*) FROM %s', self::PROBE)));
        self::assertSame([$this->card], $this->check->fetchFirstColumn('SELECT card_id FROM workflow_card_changes WHERE card_id = ?', [$this->card]), 'The card has one mark.');
        self::assertGreaterThan('2020-01-01 00:00:00', $this->check->fetchOne('SELECT changed_at FROM workflow_card_changes WHERE card_id = ?', [$this->card]), 'The mark is the one of the write.');
    }

    #[Test]
    public function a_mark_that_the_writer_has_not_committed_survives_the_delete_of_the_check(): void
    {
        $this->writer->beginTransaction();
        $this->writer->executeStatement(\sprintf('INSERT INTO %s (card_id) VALUES (?)', self::PROBE), [$this->card]);

        // The check cannot see the mark of the open write, so its delete neither waits nor removes it.
        $this->check->executeStatement('SET lock_timeout = \'2s\'');
        self::assertSame(0, $this->check->executeStatement(self::CLEAR, ['card' => $this->card]));

        $this->writer->commit();

        self::assertSame([$this->card], $this->check->fetchFirstColumn('SELECT card_id FROM workflow_card_changes WHERE card_id = ?', [$this->card]), 'The mark of the write stays.');
    }

    private function startWriter(): Process
    {
        $process = new Process([
            \PHP_BINARY,
            '-r',
            '$pdo = new PDO($argv[1], $argv[2], $argv[3], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); $pdo->prepare("INSERT INTO '.self::PROBE.' (card_id) VALUES (?)")->execute([$argv[4]]);',
            '--',
            \sprintf('pgsql:host=%s;port=%d;dbname=%s', $this->params['host'], $this->params['port'], $this->params['dbname']),
            $this->params['user'],
            $this->params['password'],
            $this->card,
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function writerWaitsForALock(): bool
    {
        $until = microtime(true) + 10;
        while (microtime(true) < $until) {
            $waiting = $this->writer->fetchOne(
                "SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock' AND query LIKE ?",
                ['%'.self::PROBE.'%'],
            );
            if ((int) $waiting > 0) {
                return true;
            }
            usleep(50_000);
        }

        return false;
    }

    /** @param array<string, mixed> $params */
    private static function text(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        return \is_string($value) || \is_int($value) ? (string) $value : throw new \LogicException('The test connection has no '.$key.'.');
    }

    private function dropProbe(): void
    {
        $this->check->executeStatement(\sprintf('DROP TABLE IF EXISTS %s', self::PROBE));
    }
}
