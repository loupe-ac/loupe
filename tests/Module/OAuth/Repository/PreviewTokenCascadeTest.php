<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Repository;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\OAuth\EventListener\DeleteOAuthGrantsOnProjectDeleting;
use App\Module\OAuth\Service\OAuthGrantAccountPurger;
use App\Module\Project\Event\ProjectDeleting;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The raw DELETE statements that clean up grants must take the preview rows with them, through the foreign key. */
final class PreviewTokenCascadeTest extends KernelTestCase
{
    use PreviewTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario();
    }

    public function test_the_account_purger_removes_the_preview_rows_of_the_user_alone(): void
    {
        $this->mint('pr-12');
        $other = $this->scenario->createUser('preview-cascade-other@example.com');
        $otherProject = $this->scenario->createProject($other, 'Their site');
        $kept = $this->mint('pr-12', owner: $other, project: $otherProject);

        static::getContainer()->get(OAuthGrantAccountPurger::class)->purge($this->owner, new AccountDeletionCleanup());

        self::assertSame([$kept], $this->previewTokenIds());
    }

    public function test_the_project_deleting_listener_removes_the_preview_rows_of_the_project_alone(): void
    {
        $this->mint('pr-12');
        $otherProject = $this->scenario->createProject($this->owner, 'Second site');
        $kept = $this->mint('pr-12', project: $otherProject);

        static::getContainer()->get(DeleteOAuthGrantsOnProjectDeleting::class)(new ProjectDeleting($this->project));

        self::assertSame([$kept], $this->previewTokenIds());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_access_token'));
    }

    public function test_removing_the_project_row_removes_its_preview_rows(): void
    {
        $this->mint('pr-12');

        $this->connection->executeStatement('DELETE FROM projects WHERE id = :id', ['id' => (string) $this->project->id]);

        self::assertSame([], $this->previewTokenIds());
    }

    public function test_removing_the_user_row_removes_its_preview_rows(): void
    {
        $this->mint('pr-12');
        $this->connection->executeStatement('DELETE FROM projects WHERE owner_id = :id', ['id' => (string) $this->owner->id]);
        $this->connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => (string) $this->owner->id]);

        self::assertSame([], $this->previewTokenIds());
    }

    /** @return list<string> */
    private function previewTokenIds(): array
    {
        return array_map(trim(...), $this->connection->fetchFirstColumn('SELECT access_token_id FROM oauth_preview_tokens ORDER BY access_token_id'));
    }
}
