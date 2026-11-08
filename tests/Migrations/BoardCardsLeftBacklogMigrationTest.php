<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardEvent;
use App\Module\Board\Entity\CardEventKind;
use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\Actor;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261107160000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261107160000.php';

/** Runs up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class BoardCardsLeftBacklogMigrationTest extends KernelTestCase
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

    public function test_up_marks_a_card_outside_the_backlog_or_with_a_move_as_started(): void
    {
        $owner = new User(fullName: 'Riley', email: 'left-backlog-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'left-backlog-'.uniqid());
        $this->em->persist($project);
        $backlog = new BoardColumn(project: $project, label: 'Backlog', slug: 'backlog', position: 0, backlog: true);
        $next = new BoardColumn(project: $project, label: 'Next', slug: 'next', position: 1);
        $this->em->persist($backlog);
        $this->em->persist($next);

        $untouched = new Card(project: $project, column: $backlog, title: 'Untouched', body: '', number: 1);
        $outside = new Card(project: $project, column: $next, title: 'Outside', body: '', number: 2);
        $movedBack = new Card(project: $project, column: $backlog, title: 'Moved back', body: '', number: 3);
        foreach ([$untouched, $outside, $movedBack] as $card) {
            $this->em->persist($card);
        }
        $this->em->persist(new CardEvent($movedBack, $project, CardEventKind::Moved, Actor::Human));
        $this->em->flush();
        $this->em->clear();

        $this->connection->executeStatement('ALTER TABLE board_cards DROP left_backlog');
        $this->migrate();

        $flag = fn (Card $card): bool => (bool) $this->connection->fetchOne('SELECT left_backlog FROM board_cards WHERE id = :id', ['id' => (string) $card->id]);
        self::assertFalse($flag($untouched));
        self::assertTrue($flag($outside));
        self::assertTrue($flag($movedBack));
    }

    private function migrate(): void
    {
        $migration = new Version20261107160000($this->connection, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
