<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Repository;

use App\Module\OAuth\Repository\PreviewTokenRepository;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PreviewTokenRepositoryTest extends KernelTestCase
{
    use PreviewTokenScenario;

    private PreviewTokenRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario();
        $this->repository = static::getContainer()->get(PreviewTokenRepository::class);
    }

    public function test_the_first_use_is_recorded(): void
    {
        $id = $this->mint('pr-12');
        self::assertNull($this->lastUsedAt($id));

        $this->repository->recordUse($id);

        self::assertNotNull($this->lastUsedAt($id));
    }

    public function test_a_use_inside_the_window_writes_nothing(): void
    {
        $id = $this->mint('pr-12');
        $this->setLastUsedAt($id, '5 minutes');
        $before = $this->lastUsedAt($id);

        $this->repository->recordUse($id);

        self::assertSame($before, $this->lastUsedAt($id));
    }

    public function test_a_use_after_the_window_is_recorded(): void
    {
        $id = $this->mint('pr-12');
        $this->setLastUsedAt($id, '16 minutes');
        $before = $this->lastUsedAt($id);

        $this->repository->recordUse($id);

        self::assertNotSame($before, $this->lastUsedAt($id));
        self::assertEqualsWithDelta(time(), new \DateTimeImmutable((string) $this->lastUsedAt($id))->getTimestamp(), 5);
    }

    public function test_a_token_with_no_row_is_a_no_op(): void
    {
        $this->repository->recordUse('no-such-token');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth_preview_tokens'));
    }

    private function lastUsedAt(string $id): ?string
    {
        $value = $this->connection->fetchOne('SELECT last_used_at FROM oauth_preview_tokens WHERE access_token_id = :id', ['id' => $id]);

        return \is_string($value) ? $value : null;
    }

    private function setLastUsedAt(string $id, string $ago): void
    {
        $this->connection->executeStatement(
            'UPDATE oauth_preview_tokens SET last_used_at = :at WHERE access_token_id = :id',
            ['at' => new \DateTimeImmutable('-'.$ago)->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }
}
