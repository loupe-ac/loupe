<?php

declare(strict_types=1);

namespace App\Tests\Module\Review\Repository;

use App\Module\Project\Repository\ProjectRepository;
use App\Module\Review\Repository\DocumentRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The workflow engine writes an outbox row before it approves a document, and an
 * outbox insert takes a key-share lock on the project row. Two engine passes on
 * two cards of one project each hold that lock. A verdict that then took a
 * FOR UPDATE lock on the row would wait for the other pass, and the other pass
 * would wait for it. These tests run the lock modes on a parent and child table
 * pair with two real sessions. The pair stands in for projects and outbox rows,
 * because a test transaction keeps the real rows out of sight of a second session.
 */
final class VerdictLockOrderTest extends KernelTestCase
{
    private const string PARENT = 'verdict_lock_probe_parent';
    private const string CHILD = 'verdict_lock_probe_child';

    private Connection $first;

    private Connection $second;

    private Connection $setup;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $params = $em->getConnection()->getParams();
        $this->setup = DriverManager::getConnection($params);
        $this->first = DriverManager::getConnection($params);
        $this->second = DriverManager::getConnection($params);

        $this->dropProbe();
        $this->setup->executeStatement('CREATE TABLE '.self::PARENT.' (id INT PRIMARY KEY)');
        $this->setup->executeStatement('CREATE TABLE '.self::CHILD.' (id SERIAL PRIMARY KEY, parent_id INT NOT NULL REFERENCES '.self::PARENT.' (id))');
        $this->setup->executeStatement('INSERT INTO '.self::PARENT.' VALUES (1)');
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ([$this->first, $this->second] as $connection) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
        $this->dropProbe();
        $this->setup->close();

        parent::tearDown();
    }

    public function test_a_for_update_lock_waits_for_the_key_share_of_another_pass(): void
    {
        $this->bothPassesWriteTheirOutboxRow();

        $this->first->executeStatement("SET LOCAL lock_timeout = '300ms'");
        try {
            $this->first->executeQuery($this->lockSql('FOR UPDATE'));
            self::fail('FOR UPDATE did not wait for the key share of the other pass.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }
    }

    public function test_the_verdict_lock_does_not_wait_for_the_key_share_of_another_pass(): void
    {
        $this->bothPassesWriteTheirOutboxRow();

        $this->first->executeStatement("SET LOCAL lock_timeout = '300ms'");
        $this->first->executeQuery($this->lockSql(DocumentRepository::VERDICT_LOCK));
        $this->second->executeStatement("SET LOCAL lock_timeout = '300ms'");
        $this->second->executeQuery($this->lockSql('FOR KEY SHARE'));
        self::assertSame(1, (int) $this->second->fetchOne('SELECT 1'));
    }

    public function test_two_verdict_locks_queue_behind_each_other_without_a_cycle(): void
    {
        $this->bothPassesWriteTheirOutboxRow();
        $this->first->executeQuery($this->lockSql(DocumentRepository::VERDICT_LOCK));

        $this->second->executeStatement("SET LOCAL lock_timeout = '300ms'");
        try {
            $this->second->executeQuery($this->lockSql(DocumentRepository::VERDICT_LOCK));
            self::fail('The second verdict lock must wait for the first.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }

        $this->second->rollBack();
        $this->first->commit();
        $this->second->beginTransaction();
        $this->second->executeQuery($this->lockSql(DocumentRepository::VERDICT_LOCK));
        self::assertTrue($this->second->isTransactionActive());
    }

    public function test_a_for_update_lock_upgrade_inside_a_verdict_waits_for_the_key_share_of_a_pass(): void
    {
        $this->bothPassesWriteTheirOutboxRow();
        $this->second->executeQuery($this->lockSql(DocumentRepository::VERDICT_LOCK));

        $this->second->executeStatement("SET LOCAL lock_timeout = '300ms'");
        $this->assertLockWaits($this->second, 'FOR UPDATE', 'The upgrade waits for the key share of the pass, which waits for the verdict lock.');
    }

    public function test_the_card_lock_inside_a_verdict_does_not_wait_for_the_key_share_of_a_pass(): void
    {
        $this->bothPassesWriteTheirOutboxRow();
        $this->second->executeQuery($this->lockSql(DocumentRepository::VERDICT_LOCK));

        $this->second->executeStatement("SET LOCAL lock_timeout = '300ms'");
        $this->second->executeQuery($this->lockSql(ProjectRepository::CARD_LOCK));
        self::assertTrue($this->second->isTransactionActive());
    }

    private function assertLockWaits(Connection $connection, string $mode, string $message): void
    {
        try {
            $connection->executeQuery($this->lockSql($mode));
            self::fail($message);
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage(), $message);
        }
    }

    private function bothPassesWriteTheirOutboxRow(): void
    {
        foreach ([$this->first, $this->second] as $connection) {
            $connection->beginTransaction();
            $connection->executeStatement('INSERT INTO '.self::CHILD.' (parent_id) VALUES (1)');
        }
    }

    private function lockSql(string $mode): string
    {
        return 'SELECT id FROM '.self::PARENT.' WHERE id = 1 '.$mode;
    }

    private function dropProbe(): void
    {
        $this->setup->executeStatement('DROP TABLE IF EXISTS '.self::CHILD);
        $this->setup->executeStatement('DROP TABLE IF EXISTS '.self::PARENT);
    }
}
