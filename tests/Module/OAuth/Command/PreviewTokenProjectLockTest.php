<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A second session finishes a card under the project lock. The rows commit,
 * because a second session cannot see or lock rows that another transaction
 * holds, so tearDown deletes them.
 */
#[SkipDatabaseRollback]
final class PreviewTokenProjectLockTest extends KernelTestCase
{
    use PreviewTokenScenario;

    private ?Connection $other = null;
    private Uuid $cardId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario('preview-lock-'.uniqid().'@example.com');
        $this->cardId = $this->cardOf($this->project);
    }

    #[\Override]
    protected function tearDown(): void
    {
        if (null !== $this->other) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
        }
        $this->connection->executeStatement('RESET lock_timeout');
        $this->connection->executeStatement('DELETE FROM oauth2_access_token WHERE identifier IN (SELECT access_token_id FROM oauth_preview_tokens WHERE project_id = ?)', [(string) $this->project->id]);
        $this->connection->executeStatement('DELETE FROM board_cards WHERE project_id = ?', [(string) $this->project->id]);
        $this->connection->executeStatement('DELETE FROM board_columns WHERE project_id = ?', [(string) $this->project->id]);
        $this->connection->executeStatement('DELETE FROM projects WHERE id = ?', [(string) $this->project->id]);
        $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [(string) $this->owner->id]);

        parent::tearDown();
    }

    public function test_a_mint_waits_for_a_card_that_finishes_and_then_refuses_it(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $done = new BoardColumn($this->project, 'Done', 'done-lock', 9, terminal: true);
        $em->persist($done);
        $em->flush();

        $this->other = DriverManager::getConnection($this->connection->getParams());
        $this->other->beginTransaction();
        // A card move takes FOR UPDATE. The weaker lock lets the foreign key
        // check of the insert through, so only the mint's own lock can wait.
        $this->other->executeStatement('SELECT id FROM projects WHERE id = ? FOR NO KEY UPDATE', [(string) $this->project->id]);
        $this->other->executeStatement('UPDATE board_cards SET column_id = ? WHERE id = ?', [(string) $done->id, $this->cardId->toRfc4122()]);
        $this->connection->executeStatement("SET lock_timeout = '300ms'");

        try {
            $this->mint('pr-12', $this->cardId);
            self::fail('The mint did not wait for the project lock another session holds.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }

        $this->other->commit();
        $doctrine = static::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $doctrine->resetManager();

        try {
            $this->mint('pr-12', $this->cardId);
            self::fail('The mint took a token for a card that finished.');
        } catch (DomainErrors $e) {
            self::assertSame(['card' => 'oauth.preview_token.error.card_finished'], $e->errors);
        }
    }
}
