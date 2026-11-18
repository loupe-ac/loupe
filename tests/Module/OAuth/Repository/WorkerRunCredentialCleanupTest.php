<?php

declare(strict_types=1);

namespace App\Tests\Module\OAuth\Repository;

use App\Module\Account\Deletion\AccountDeletionCleanup;
use App\Module\Account\Deletion\AccountPurger;
use App\Module\Account\Entity\User;
use App\Module\OAuth\Command\PurgeExpiredOAuthTokensCommand;
use App\Module\OAuth\Command\PurgeExpiredOAuthTokensHandler;
use App\Module\OAuth\EventListener\DeleteOAuthGrantsOnProjectDeleting;
use App\Module\OAuth\Repository\GrantRepository;
use App\Module\OAuth\Service\OAuthGrantAccountPurger;
use App\Module\Project\Entity\Project;
use App\Module\Project\Event\ProjectDeleting;
use App\Module\Project\Service\ProjectAccountPurger;
use App\Tests\Module\OAuth\PreviewTokenScenario;
use App\Tests\Support\AgentCredential;
use App\Tests\Support\WorkerRunToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/** The run records go when their expiry is old enough, their project goes, or their account goes. */
final class WorkerRunCredentialCleanupTest extends KernelTestCase
{
    use PreviewTokenScenario;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->setUpScenario('run-cleanup@example.com');
    }

    public function test_the_purge_task_path_keeps_a_record_for_thirty_days_after_its_expiry(): void
    {
        $live = $this->issue();
        $recent = $this->issue();
        $old = $this->issue();
        $this->expire($recent, '29 days');
        $this->expire($old, '31 days');

        $purge = static::getContainer()->get(PurgeExpiredOAuthTokensHandler::class);
        self::assertInstanceOf(PurgeExpiredOAuthTokensHandler::class, $purge);
        $deleted = $purge(new PurgeExpiredOAuthTokensCommand());

        self::assertEqualsCanonicalizing([$live, $recent], $this->recordIds());
        self::assertGreaterThanOrEqual(3, $deleted, 'the two expired access tokens and the old record');
    }

    public function test_an_expired_access_token_goes_with_the_hourly_purge_and_its_record_stays(): void
    {
        $id = $this->issue();
        $this->expire($id, '2 days');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_access_token WHERE identifier = :id', ['id' => $id]));

        $purge = static::getContainer()->get(PurgeExpiredOAuthTokensHandler::class);
        self::assertInstanceOf(PurgeExpiredOAuthTokensHandler::class, $purge);
        $purge(new PurgeExpiredOAuthTokensCommand());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM oauth2_access_token WHERE identifier = :id', ['id' => $id]));
        self::assertSame([$id], $this->recordIds());
    }

    public function test_the_project_deleting_listener_removes_the_records_of_the_project_alone(): void
    {
        $this->issue();
        $otherProject = $this->scenario->createProject($this->owner, 'Second site');
        $kept = $this->issue($otherProject);

        static::getContainer()->get(DeleteOAuthGrantsOnProjectDeleting::class)(new ProjectDeleting($this->project));

        self::assertSame([$kept], $this->recordIds());
    }

    public function test_the_account_purger_removes_the_records_of_the_user_alone(): void
    {
        $this->issue();
        $other = $this->scenario->createUser('run-cleanup-other@example.com');
        $otherProject = $this->scenario->createProject($other, 'Their site');
        $kept = $this->issue($otherProject, $other);

        static::getContainer()->get(OAuthGrantAccountPurger::class)->purge($this->owner, new AccountDeletionCleanup());

        self::assertSame([$kept], $this->recordIds());
    }

    public function test_the_account_purger_runs_after_the_project_purger(): void
    {
        $purger = static::getContainer()->get(OAuthGrantAccountPurger::class);
        $projectPurger = static::getContainer()->get(ProjectAccountPurger::class);
        self::assertInstanceOf(OAuthGrantAccountPurger::class, $purger);
        self::assertInstanceOf(ProjectAccountPurger::class, $projectPurger);

        self::assertGreaterThan($projectPurger->deletionOrder(), $purger->deletionOrder());
    }

    public function test_the_ordered_chain_removes_the_records_with_the_account(): void
    {
        $this->issue();

        $accountPurger = static::getContainer()->get(AccountPurger::class);
        self::assertInstanceOf(AccountPurger::class, $accountPurger);
        $accountPurger->purge($this->owner);

        self::assertSame([], $this->recordIds());
    }

    public function test_removing_the_project_or_the_user_row_removes_the_records(): void
    {
        $this->issue();
        $this->connection->executeStatement('DELETE FROM projects WHERE id = :id', ['id' => (string) $this->project->id]);
        self::assertSame([], $this->recordIds());

        $project = $this->scenario->createProject($this->owner, 'Third site');
        $this->issue($project);
        $this->connection->executeStatement('DELETE FROM projects WHERE owner_id = :id', ['id' => (string) $this->owner->id]);
        $this->connection->executeStatement('DELETE FROM users WHERE id = :id', ['id' => (string) $this->owner->id]);
        self::assertSame([], $this->recordIds());
    }

    public function test_the_connected_apps_list_shows_no_run_token(): void
    {
        $this->issue();
        $grants = static::getContainer()->get(GrantRepository::class);
        self::assertInstanceOf(GrantRepository::class, $grants);

        self::assertSame([], $grants->findLiveGrantsForUser($this->owner->id ?? throw new \LogicException('no id')));

        AgentCredential::tokenFor(static::getContainer(), $this->owner, 'mcp', $this->project);
        self::assertCount(1, $grants->findLiveGrantsForUser($this->owner->id ?? throw new \LogicException('no id')));
    }

    /** @return non-empty-string the access token id */
    private function issue(?Project $project = null, ?User $owner = null): string
    {
        $jwt = WorkerRunToken::issue(static::getContainer(), $owner ?? $this->owner, $project ?? $this->project, runKey: Uuid::v7())->token;

        return self::tokenIdOf($jwt);
    }

    private function expire(string $accessTokenId, string $ago): void
    {
        $this->connection->executeStatement(
            'UPDATE oauth_worker_run_credentials SET expires_at = NOW() - CAST(:ago AS interval) WHERE access_token_id = :id',
            ['ago' => $ago, 'id' => $accessTokenId],
        );
        $this->connection->executeStatement(
            'UPDATE oauth2_access_token SET expiry = NOW() - CAST(:ago AS interval) WHERE identifier = :id',
            ['ago' => $ago, 'id' => $accessTokenId],
        );
    }

    /** @return list<string> */
    private function recordIds(): array
    {
        return array_values(array_map(trim(...), $this->connection->fetchFirstColumn('SELECT access_token_id FROM oauth_worker_run_credentials ORDER BY access_token_id')));
    }
}
