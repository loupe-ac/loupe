<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Command;

use App\Module\OAuth\Command\RevokePreviewTokensCommand;
use App\Module\OAuth\Command\RevokePreviewTokensHandler;
use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A second database session holds the lock of one label, so a mint and a
 * revoke of that label must wait for it, and a revoke of another label not.
 */
final class PreviewTokenLabelLockTest extends KernelTestCase
{
    use PreviewTokenScenario;

    private ?Connection $other = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario();
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

        parent::tearDown();
    }

    public function test_a_revoke_by_label_waits_for_the_lock_of_its_label(): void
    {
        $other = $this->mint('pr-13');
        $this->holdLabelLock('pr-12');
        $handler = static::getContainer()->get(RevokePreviewTokensHandler::class);
        self::assertInstanceOf(RevokePreviewTokensHandler::class, $handler);

        self::assertSame(1, $handler(new RevokePreviewTokensCommand(owner: $this->owner, handle: (string) $this->project->id, label: 'pr-13')));
        self::assertTrue($this->isRevoked($other));

        try {
            $handler(new RevokePreviewTokensCommand(owner: $this->owner, handle: (string) $this->project->id, label: 'pr-12'));
            self::fail('The revoke did not wait for the lock another session holds.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }
    }

    public function test_a_mint_waits_for_the_lock_of_its_label(): void
    {
        $this->holdLabelLock('pr-12');

        $this->mint('pr-13');

        try {
            $this->mint('pr-12');
            self::fail('The mint did not wait for the lock another session holds.');
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString('lock timeout', $e->getMessage());
        }
    }

    private function holdLabelLock(string $label): void
    {
        $this->other = DriverManager::getConnection($this->connection->getParams());
        $this->other->beginTransaction();
        $this->other->executeStatement('SELECT pg_advisory_xact_lock(hashtext(?))', [PreviewTokenRepository::labelLockKey(
            $this->owner->id ?? throw new \LogicException('a persisted user always has an id.'),
            $this->project->id ?? throw new \LogicException('a persisted project always has an id.'),
            $label,
        )]);
        $this->connection->executeStatement("SET LOCAL lock_timeout = '300ms'");
    }
}
