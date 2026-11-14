<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261113150255;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261113150255.php';

/** Runs down() and up() inside the test's own transaction, which Postgres rolls back with the DDL. */
final class ProjectSetUpMigrationTest extends KernelTestCase
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

    public function test_up_sets_up_every_existing_project_and_writes_no_milestone(): void
    {
        $owner = new User(fullName: 'Riley', email: 'set-up-migration-'.uniqid().'@example.com', password: 'hashed');
        $this->em->persist($owner);
        $project = new Project($owner, 'existing');
        $this->em->persist($project);
        $this->em->flush();
        $this->em->clear();

        $this->migrate(down: true);
        // Guard: the column is gone, so a true value below can only come from up().
        self::assertSame(0, $this->columnCount());

        $this->migrate();

        self::assertTrue((bool) $this->connection->fetchOne('SELECT set_up FROM projects WHERE id = :id', ['id' => (string) $project->id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM project_milestones'));
        self::assertSame(1, $this->columnCount());
    }

    private function columnCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM information_schema.columns WHERE table_name = 'projects' AND column_name = 'set_up'");
    }

    private function migrate(bool $down = false): void
    {
        $migration = new Version20261113150255($this->connection, new NullLogger());
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
