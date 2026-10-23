<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261021213209;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261021213209.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class CardMarkerColumnsMigrationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
    }

    public function test_up_copies_the_old_marker_columns_and_leaves_the_inbox_set_null(): void
    {
        $owner = new User(fullName: 'Riley', email: 'marker-columns-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'marker-columns-'.uniqid());
        $this->em->persist($project);
        $column = new BoardColumn(project: $project, label: 'Next', slug: 'next', position: 0, terminal: false);
        $this->em->persist($column);
        $marked = new Card(project: $project, column: $column, title: 'Marked', body: '', number: 1);
        $plain = new Card(project: $project, column: $column, title: 'Plain', body: '', number: 2);
        $this->em->persist($marked);
        $this->em->persist($plain);
        $this->em->flush();
        $this->em->clear();

        $this->migrate(down: true);
        self::assertSame(0, $this->newColumnCount());
        $this->connection->executeStatement(
            "UPDATE board_cards SET markers = :markers::jsonb, markers_due_at = '2026-10-21 10:00:00' WHERE id = :id",
            ['markers' => '[{"key":"paused","kind":"stuck","label":"marker.paused","remedy":null,"params":{},"since":"2026-10-20T09:00:00+00:00"}]', 'id' => (string) $marked->id],
        );

        $this->migrate();

        self::assertSame(3, $this->newColumnCount());
        $row = $this->connection->fetchAssociative('SELECT workflow_markers, workflow_markers_due_at, inbox_markers FROM board_cards WHERE id = :id', ['id' => (string) $marked->id]);
        self::assertIsArray($row);
        self::assertSame('paused', json_decode((string) $row['workflow_markers'], true, flags: \JSON_THROW_ON_ERROR)[0]['key']);
        self::assertSame('2026-10-21 10:00:00', $row['workflow_markers_due_at']);
        self::assertNull($row['inbox_markers']);

        $untouched = $this->connection->fetchAssociative('SELECT workflow_markers, workflow_markers_due_at, inbox_markers FROM board_cards WHERE id = :id', ['id' => (string) $plain->id]);
        self::assertSame(['workflow_markers' => null, 'workflow_markers_due_at' => null, 'inbox_markers' => null], $untouched);
    }

    public function test_up_keeps_the_old_marker_columns_for_the_old_image(): void
    {
        $this->migrate(down: true);
        $this->migrate();

        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'board_cards' AND column_name IN ('markers', 'markers_due_at')"));
    }

    private function newColumnCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'board_cards' AND column_name IN ('workflow_markers', 'workflow_markers_due_at', 'inbox_markers')");
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261021213209($this->connection, new NullLogger());
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
