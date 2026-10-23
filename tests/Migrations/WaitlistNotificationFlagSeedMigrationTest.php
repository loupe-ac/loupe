<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Module\Account\Entity\User;
use App\Module\Account\Service\WaitlistNotificationRecipients;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261022170900;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__.'/../../migrations/Version20261022170900.php';

final class WaitlistNotificationFlagSeedMigrationTest extends KernelTestCase
{
    private Connection $connection;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
        $this->connection = $em->getConnection();
        $this->connection->executeStatement('DELETE FROM feature_flag WHERE name = ?', [WaitlistNotificationRecipients::FLAG]);
    }

    public function test_the_list_holds_the_active_admins_in_sign_up_order(): void
    {
        $this->addUser('late@example.com', ['ROLE_ADMIN'], '2026-03-01');
        $this->addUser('early@example.com', ['ROLE_ADMIN'], '2026-01-01');
        $this->addUser('suspended@example.com', ['ROLE_ADMIN'], '2026-02-01', suspended: true);
        $this->addUser('member@example.com', [], '2026-02-02');

        $this->migrate();

        self::assertSame([['type' => 'string', 'value' => '"early@example.com, late@example.com"']], $this->rows());
    }

    public function test_the_agent_account_is_left_out(): void
    {
        $agent = $this->connection->fetchOne('SELECT email FROM users WHERE id = ?', [User::AGENT_ID]);
        self::assertIsString($agent);
        $this->connection->executeStatement("UPDATE users SET roles = '[\"ROLE_ADMIN\"]' WHERE id = ?", [User::AGENT_ID]);
        $this->addUser('owner@example.com', ['ROLE_ADMIN'], '2026-01-01');

        $this->migrate();

        self::assertSame([['type' => 'string', 'value' => '"owner@example.com"']], $this->rows());
    }

    public function test_an_instance_with_no_admin_gets_an_empty_list(): void
    {
        $this->migrate();

        self::assertSame([['type' => 'string', 'value' => '""']], $this->rows());
    }

    public function test_an_existing_row_is_left_alone(): void
    {
        $this->addUser('owner@example.com', ['ROLE_ADMIN'], '2026-01-01');
        $this->connection->executeStatement('INSERT INTO feature_flag (name, type, value, tags, options) VALUES (?, ?, ?, \'[]\', NULL)', [WaitlistNotificationRecipients::FLAG, 'string', '"kept@example.com"']);

        $this->migrate();

        self::assertSame([['type' => 'string', 'value' => '"kept@example.com"']], $this->rows());
    }

    public function test_the_down_migration_removes_the_row(): void
    {
        $this->migrate();
        self::assertCount(1, $this->rows());

        $migration = new Version20261022170900($this->connection, new NullLogger());
        $migration->down(new Schema());
        $this->execute($migration);

        self::assertSame([], $this->rows());
    }

    /**
     * @param non-empty-string $email
     * @param list<string>     $roles
     */
    private function addUser(string $email, array $roles, string $createdAt, bool $suspended = false): void
    {
        $user = new User(fullName: 'Test User', email: $email, password: 'x', createdAt: new \DateTimeImmutable($createdAt));
        $user->roles = $roles;
        $user->suspendedAt = $suspended ? new \DateTimeImmutable() : null;
        $this->em->persist($user);
        $this->em->flush();
    }

    private function migrate(): void
    {
        $migration = new Version20261022170900($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->execute($migration);
    }

    private function execute(Version20261022170900 $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $this->connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->connection->fetchAllAssociative('SELECT type, value::text AS value FROM feature_flag WHERE name = ?', [WaitlistNotificationRecipients::FLAG]);
    }
}
