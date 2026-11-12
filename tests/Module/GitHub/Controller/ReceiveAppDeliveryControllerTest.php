<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Controller;

use App\Module\Forge\Entity\ForgePullRequest;
use App\Module\Forge\Entity\ForgeRepositorySource;
use App\Module\Forge\Entity\PullRequestReview;
use App\Module\Forge\Event\ForgeBranchPushed;
use App\Module\Forge\EventListener\RateLimitForgeDeliveries;
use App\Module\GitHub\Entity\GitHubInstallation;
use App\Module\GitHub\Entity\GitHubRepositorySelection;
use App\Module\GitHub\Repository\GitHubInstallationRepository;
use App\Tests\Support\RecordedEvents;
use App\Tests\Support\RecordingAuditor;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\AuditOutcome;

/** The App route verifies with the App's own secret, and acts only for an installation Loupe knows. */
final class ReceiveAppDeliveryControllerTest extends WebTestCase
{
    use GitHubDeliveryScenario;

    private const string PATH = '/webhooks/forge/github';
    private const string SECRET = 'github_app_webhook_test';

    public function test_a_bad_signature_is_refused_and_audited(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $audit = RecordingAuditor::installedIn(static::getContainer());

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(601, 'acme/a', 1), 'not-the-secret');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(AuditOutcome::Refused, $audit->record('forge.delivery_rejected')->outcome);
        self::assertTrue($client->getRequest()->attributes->get(RateLimitForgeDeliveries::MARKER), 'The rate limiter reads the route default from the request.');
    }

    /** A repository hook that someone signed with the App secret still names no installation. */
    public function test_a_delivery_without_an_installation_is_dropped(): void
    {
        $client = static::createClient();

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(602, 'acme/b', 1), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(602));
    }

    public function test_a_delivery_for_an_unknown_installation_is_dropped(): void
    {
        $client = static::createClient();

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(603, 'acme/c', 1, ['installation' => ['id' => 9_000_603]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(603));
    }

    public function test_an_installation_that_reaches_all_repositories_claims_nothing_on_a_delivery(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('all');
        $this->installation(9_000_604, GitHubRepositorySelection::All);
        $this->linkedCard($project, 'acme/d', 3);
        $this->trackedPullRequest($project, 'acme/d', 3, 'aaa111');

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(604, 'acme/d', 3, ['installation' => ['id' => 9_000_604]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(604));
        self::assertSame([], $this->queuedRefreshes());
        self::assertSame([], $this->outboxSubjects($project));
    }

    public function test_an_installation_with_selected_repositories_never_claims_on_a_delivery(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('selected');
        $this->installation(9_000_605);
        $this->linkedCard($project, 'acme/e', 3);
        $this->trackedPullRequest($project, 'acme/e', 3, 'aaa111');

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(605, 'acme/e', 3, ['installation' => ['id' => 9_000_605]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(605));
        self::assertSame([], $this->queuedRefreshes());
        self::assertSame([], $this->outboxSubjects($project));
    }

    public function test_a_repository_the_installation_project_owns_is_announced(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('owned');
        $this->installation(9_000_606);
        $this->owned($project, 606, 'acme/f', ForgeRepositorySource::Installation, 9_000_606);
        $tracked = $this->trackedPullRequest($project, 'acme/f', 3, 'aaa111');

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(606, 'acme/f', 3, ['installation' => ['id' => 9_000_606]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([[$tracked, false]], $this->queuedRefreshes());
    }

    public function test_a_review_queues_a_refresh_that_carries_its_verdict(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->configureAppKey();
        $project = $this->project('app-review');
        $this->installation(9_000_607);
        $this->owned($project, 607, 'acme/v', ForgeRepositorySource::Installation, 9_000_607);
        $tracked = $this->trackedPullRequest($project, 'acme/v', 12, 'aaa111');

        $this->deliver($client, self::PATH, 'pull_request_review', [
            'action' => 'submitted',
            'installation' => ['id' => 9_000_607],
            'repository' => ['id' => 607, 'full_name' => 'acme/v'],
            'pull_request' => ['number' => 12],
            'review' => ['id' => 70_001, 'state' => 'changes_requested'],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([[$tracked, false]], $this->queuedRefreshes());
        self::assertSame([[PullRequestReview::ChangesRequested, '70001']], $this->queuedVerdicts());
    }

    public function test_an_installation_without_the_app_key_gets_the_bare_facts_and_no_verdict(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('no-key');
        $this->installation(9_000_608);
        $this->owned($project, 608, 'acme/k', ForgeRepositorySource::Installation, 9_000_608);
        $card = $this->linkedCard($project, 'acme/k', 12);
        $this->trackedPullRequest($project, 'acme/k', 12, 'aaa111');

        $this->deliver($client, self::PATH, 'pull_request_review', [
            'action' => 'submitted',
            'installation' => ['id' => 9_000_608],
            'repository' => ['id' => 608, 'full_name' => 'acme/k'],
            'pull_request' => ['number' => 12],
            'review' => ['id' => 70_002, 'state' => 'changes_requested'],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([(string) $card->id], $this->outboxSubjects($project));
        self::assertSame([[null, null]], $this->queuedVerdicts());
    }

    public function test_a_completed_check_suite_queues_a_refresh_of_each_tracked_pull_request_it_touches(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('refresh');
        $this->installation(9_000_620);
        $this->owned($project, 620, 'Acme/R', ForgeRepositorySource::Installation, 9_000_620);
        $byNumber = $this->trackedPullRequest($project, 'acme/r', 3, 'aaa111');
        $byHead = $this->trackedPullRequest($project, 'acme/r', 4, 'bbb222');
        $this->trackedPullRequest($project, 'acme/r', 5, 'ccc333');

        $this->deliver($client, self::PATH, 'check_suite', [
            'action' => 'completed',
            'installation' => ['id' => 9_000_620],
            'repository' => ['id' => 620, 'full_name' => 'Acme/R'],
            'check_suite' => ['head_sha' => 'bbb222', 'pull_requests' => [['number' => 3]]],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing([[$byNumber, false], [$byHead, false]], $this->queuedRefreshes());
    }

    public function test_a_push_to_a_base_queues_a_delayed_refresh_of_the_pull_requests_on_it(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('push');
        $this->installation(9_000_621);
        $this->owned($project, 621, 'acme/s', ForgeRepositorySource::Installation, 9_000_621);
        $onMain = $this->trackedPullRequest($project, 'acme/s', 1, 'aaa111');
        $this->trackedPullRequest($project, 'acme/s', 2, 'bbb222', 'release');

        $this->deliver($client, self::PATH, 'push', [
            'ref' => 'refs/heads/main',
            'installation' => ['id' => 9_000_621],
            'repository' => ['id' => 621, 'full_name' => 'acme/s'],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([[$onMain, true]], $this->queuedRefreshes());
    }

    public function test_a_push_of_a_branch_announces_it_and_a_tag_or_a_deleted_branch_announces_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('pushed');
        $this->installation(9_000_622);
        $this->owned($project, 622, 'acme/t', ForgeRepositorySource::Installation, 9_000_622);
        $announced = new RecordedEvents();
        $dispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(ForgeBranchPushed::class, $announced);
        $payload = ['installation' => ['id' => 9_000_622], 'repository' => ['id' => 622, 'full_name' => 'acme/t']];

        $this->deliver($client, self::PATH, 'push', ['ref' => 'refs/heads/epic/852'] + $payload, self::SECRET);
        $this->deliver($client, self::PATH, 'push', ['ref' => 'refs/tags/v1'] + $payload, self::SECRET);
        $this->deliver($client, self::PATH, 'push', ['ref' => 'refs/heads/epic/853', 'deleted' => true] + $payload, self::SECRET);

        $pushed = $announced->of(ForgeBranchPushed::class);
        self::assertCount(1, $pushed);
        self::assertSame(['github', 'acme/t', 'epic/852'], [$pushed[0]->forge, $pushed[0]->repository, $pushed[0]->branch]);
        self::assertEquals($project->id, $pushed[0]->projectId);
    }

    public function test_a_push_to_the_default_branch_queues_a_read_of_the_workflow_file(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('default-push');
        $other = $this->project('default-push-other');
        $this->installation(9_000_631);
        $this->owned($project, 631, 'acme/u', ForgeRepositorySource::Installation, 9_000_631);
        $this->owned($other, 631, 'acme/u', ForgeRepositorySource::Hook);
        $repositoryId = (string) $this->forgeRepositoryRepository()->findInstallationRow('github', '631')?->id;
        $payload = ['installation' => ['id' => 9_000_631], 'repository' => ['id' => 631, 'full_name' => 'acme/u', 'default_branch' => 'main']];

        $this->deliver($client, self::PATH, 'push', ['ref' => 'refs/heads/main'] + $payload, self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([$repositoryId], $this->queuedWorkflowReads());
    }

    public function test_a_push_to_another_branch_queues_no_read(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('other-push');
        $this->installation(9_000_632);
        $this->owned($project, 632, 'acme/v', ForgeRepositorySource::Installation, 9_000_632);
        $payload = ['installation' => ['id' => 9_000_632], 'repository' => ['id' => 632, 'full_name' => 'acme/v', 'default_branch' => 'main']];

        $this->deliver($client, self::PATH, 'push', ['ref' => 'refs/heads/epic/852'] + $payload, self::SECRET);
        $this->deliver($client, self::PATH, 'push', ['ref' => 'refs/tags/main'] + $payload, self::SECRET);
        $this->deliver($client, self::PATH, 'push', ['ref' => 'refs/heads/main', 'deleted' => true] + $payload, self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->queuedWorkflowReads());
    }

    public function test_a_push_with_no_default_branch_in_the_payload_queues_no_read(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('no-default');
        $this->installation(9_000_633);
        $this->owned($project, 633, 'acme/w', ForgeRepositorySource::Installation, 9_000_633);

        $this->deliver($client, self::PATH, 'push', ['ref' => 'refs/heads/main', 'installation' => ['id' => 9_000_633], 'repository' => ['id' => 633, 'full_name' => 'acme/w']], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->queuedWorkflowReads());
    }

    public function test_an_added_repository_is_claimed_by_nobody_and_queues_no_read(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('claim-read');
        $this->installation(9_000_634);
        $this->owned($project, 6343, 'acme/known', ForgeRepositorySource::Installation, 9_000_634);

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_634],
            'repository_selection' => 'all',
            'repositories_added' => [['id' => 6341, 'full_name' => 'acme/arriving'], ['id' => 6343, 'full_name' => 'acme/known']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(6341));
        self::assertSame([], $this->queuedWorkflowReads());
    }

    public function test_a_repository_another_installation_owns_is_dropped(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('installed');
        $holder = $this->project('holder');
        $this->installation(9_000_607, GitHubRepositorySelection::All);
        $this->owned($holder, 607, 'acme/g', ForgeRepositorySource::Installation, 1);
        $this->linkedCard($project, 'acme/g', 3);

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(607, 'acme/g', 3, ['installation' => ['id' => 9_000_607]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->outboxSubjects($project));
        self::assertEquals($holder->id, $this->installationOwnerOf(607)?->project->id);
    }

    public function test_repositories_removed_keep_their_row_as_no_access_and_added_ones_are_not_claimed(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('changed');
        $holder = $this->project('holder');
        $this->installation(9_000_608);
        $this->owned($project, 6082, 'acme/leaving', ForgeRepositorySource::Installation, 9_000_608);
        $this->owned($holder, 6083, 'acme/theirs', ForgeRepositorySource::Installation, 1);

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_608],
            'repository_selection' => 'all',
            'repositories_added' => [['id' => 6081, 'full_name' => 'acme/arriving'], ['id' => 6083, 'full_name' => 'acme/theirs']],
            'repositories_removed' => [['id' => 6082, 'full_name' => 'acme/leaving']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->installationOwnerOf(6081));
        self::assertNotNull($this->installationOwnerOf(6082)?->accessLostAt);
        self::assertEquals($holder->id, $this->installationOwnerOf(6083)?->project->id);
        self::assertNull($this->installationOwnerOf(6083)?->accessLostAt);
        self::assertSame(GitHubRepositorySelection::All, $this->storedInstallation(9_000_608)->repositorySelection);
    }

    public function test_a_suspended_installation_drops_deliveries_until_it_is_unsuspended(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('suspended');
        $this->installation(9_000_609, GitHubRepositorySelection::All);
        $this->owned($project, 609, 'acme/h', ForgeRepositorySource::Installation, 9_000_609);
        $tracked = $this->trackedPullRequest($project, 'acme/h', 3, 'aaa111');
        $installation = ['installation' => ['id' => 9_000_609]];

        $this->deliver($client, self::PATH, 'installation', ['action' => 'suspend', ...$installation], self::SECRET);
        self::assertNotNull($this->storedInstallation(9_000_609)->suspendedAt);
        $this->deliver($client, self::PATH, 'pull_request', $this->merged(609, 'acme/h', 3, $installation), self::SECRET);
        self::assertSame([], $this->queuedRefreshes());

        $this->deliver($client, self::PATH, 'installation', ['action' => 'unsuspend', ...$installation], self::SECRET);
        self::assertNull($this->storedInstallation(9_000_609)->suspendedAt);
        $this->deliver($client, self::PATH, 'pull_request', $this->merged(609, 'acme/h', 3, $installation), self::SECRET);
        self::assertSame([[$tracked, false]], $this->queuedRefreshes());
    }

    public function test_a_deleted_installation_keeps_its_rows_as_no_access(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('deleted');
        $this->installation(9_000_610);
        $this->owned($project, 610, 'acme/i', ForgeRepositorySource::Installation, 9_000_610);
        $this->owned($project, 6101, 'acme/unlisted', ForgeRepositorySource::Installation, 9_000_610);
        $this->owned($project, 6102, 'acme/hooked');

        $this->deliver($client, self::PATH, 'installation', [
            'action' => 'deleted',
            'installation' => ['id' => 9_000_610],
            'repositories' => [['id' => 610, 'full_name' => 'acme/i']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->storedInstallation(9_000_610)->removedAt);
        self::assertNotNull($this->rowOf($project, 610)?->accessLostAt);
        self::assertNotNull($this->rowOf($project, 6101)?->accessLostAt, 'The payload may omit the list, so the stored installation reference decides.');
        self::assertNull($this->rowOf($project, 6102)?->accessLostAt, 'A hook row has no installation to lose.');
    }

    public function test_a_suspended_installation_claims_no_added_repository(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('suspended-add');
        $installation = $this->installation(9_000_612);
        $installation->suspendedAt = new \DateTimeImmutable();
        $this->em()->flush();

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_612],
            'repositories_added' => [['id' => 612, 'full_name' => 'acme/k']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull($this->rowOf($project, 612));
    }

    public function test_a_delivery_under_a_new_path_repoints_the_project_links(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('renamed');
        $this->installation(9_000_613);
        $this->owned($project, 613, 'acme/old', ForgeRepositorySource::Installation, 9_000_613);
        $card = $this->linkedCard($project, 'acme/old', 1);

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(613, 'acme/new', 1, ['installation' => ['id' => 9_000_613]]), self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertSame(['acme/new'], $this->linkPaths($card));
    }

    public function test_a_created_event_for_an_unknown_installation_records_it_and_claims_nothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->deliver($client, self::PATH, 'installation', [
            'action' => 'created',
            'installation' => ['id' => 9_000_611, 'repository_selection' => 'selected', 'account' => ['id' => 4_001, 'login' => 'acme']],
            'repositories' => [['id' => 611, 'full_name' => 'acme/j']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        $stored = $this->storedInstallation(9_000_611);
        self::assertSame('acme', $stored->accountLogin);
        self::assertSame(4_001, $stored->accountId);
        self::assertSame(GitHubRepositorySelection::Selected, $stored->repositorySelection);
        self::assertNull($this->installationOwnerOf(611));
    }

    public function test_an_event_other_than_created_does_not_record_an_unknown_installation(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $this->deliver($client, self::PATH, 'installation', [
            'action' => 'unsuspend',
            'installation' => ['id' => 9_000_614, 'account' => ['id' => 4_002, 'login' => 'acme']],
        ], self::SECRET);

        self::assertResponseIsSuccessful();
        self::assertNull(self::getContainer()->get(GitHubInstallationRepository::class)?->findOneByInstallationId(9_000_614));
    }

    public function test_every_installation_event_updates_the_login_the_account_id_and_the_selection(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->installation(9_000_616);

        $this->deliver($client, self::PATH, 'installation', [
            'action' => 'unsuspend',
            'installation' => ['id' => 9_000_616, 'repository_selection' => 'all', 'account' => ['id' => 4_003, 'login' => 'acme-renamed']],
        ], self::SECRET);

        $stored = $this->storedInstallation(9_000_616);
        self::assertSame('acme-renamed', $stored->accountLogin);
        self::assertSame(4_003, $stored->accountId);
        self::assertSame(GitHubRepositorySelection::All, $stored->repositorySelection);
    }

    public function test_a_no_access_row_reconnects_when_a_new_installation_of_the_same_account_shares_it(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('reconnect');
        $this->installation(9_000_617, accountId: 4_004)->removedAt = new \DateTimeImmutable();
        $this->owned($project, 617, 'acme/back', ForgeRepositorySource::Installation, 9_000_617);
        $this->em()->flush();
        $this->deliver($client, self::PATH, 'installation', ['action' => 'created', 'installation' => ['id' => 9_000_618, 'account' => ['id' => 4_004, 'login' => 'acme']], 'repositories' => []], self::SECRET);
        $this->loseAccessOf(617);

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_618, 'account' => ['id' => 4_004, 'login' => 'acme']],
            'repositories_added' => [['id' => 617, 'full_name' => 'acme/back']],
        ], self::SECRET);

        $row = $this->installationOwnerOf(617) ?? self::fail('The row is gone.');
        self::assertNull($row->accessLostAt);
        self::assertSame('9000618', $row->sourceRef);
    }

    public function test_a_reconnect_under_a_new_path_repoints_the_stored_pull_requests(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('reconnect-renamed');
        $this->installation(9_000_626, accountId: 4_007);
        $this->installation(9_000_627, accountId: 4_007);
        $this->owned($project, 626, 'acme/old-name', ForgeRepositorySource::Installation, 9_000_626);
        $trackedId = $this->trackedPullRequest($project, 'acme/old-name', 4, 'aaa111');
        $this->loseAccessOf(626);

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_627],
            'repositories_added' => [['id' => 626, 'full_name' => 'acme/new-name']],
        ], self::SECRET);

        $this->em()->clear();
        $pullRequest = $this->em()->find(ForgePullRequest::class, $trackedId) ?? self::fail('The pull request is gone.');
        self::assertSame('acme/new-name', $pullRequest->repository);
    }

    public function test_a_no_access_row_stays_when_the_new_installation_belongs_to_another_or_an_unknown_account(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('no-reconnect');
        $this->installation(9_000_619, accountId: 4_005);
        $this->installation(9_000_623, accountId: 4_006);
        $this->installation(9_000_624);
        $this->owned($project, 619, 'acme/stay', ForgeRepositorySource::Installation, 9_000_619);
        $this->loseAccessOf(619);

        foreach ([9_000_623, 9_000_624] as $newInstallation) {
            $this->deliver($client, self::PATH, 'installation_repositories', [
                'action' => 'added',
                'installation' => ['id' => $newInstallation],
                'repositories_added' => [['id' => 619, 'full_name' => 'acme/stay']],
            ], self::SECRET);
        }

        $row = $this->installationOwnerOf(619);
        self::assertNotNull($row?->accessLostAt);
        self::assertSame('9000619', $row->sourceRef);
    }

    public function test_a_no_access_row_stays_when_the_same_installation_has_no_known_account(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('same-unknown');
        $this->installation(9_000_625);
        $this->owned($project, 625, 'acme/unknown', ForgeRepositorySource::Installation, 9_000_625);
        $this->loseAccessOf(625);

        $this->deliver($client, self::PATH, 'installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9_000_625],
            'repositories_added' => [['id' => 625, 'full_name' => 'acme/unknown']],
        ], self::SECRET);

        self::assertNotNull($this->installationOwnerOf(625)?->accessLostAt);
    }

    public function test_a_delivery_for_a_no_access_row_is_dropped(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $project = $this->project('lost-delivery');
        $this->installation(9_000_625);
        $this->owned($project, 625, 'acme/lost', ForgeRepositorySource::Installation, 9_000_625);
        $this->trackedPullRequest($project, 'acme/lost', 3, 'aaa111');
        $this->loseAccessOf(625);

        $this->deliver($client, self::PATH, 'pull_request', $this->merged(625, 'acme/lost', 3, ['installation' => ['id' => 9_000_625]]), self::SECRET);

        self::assertSame([], $this->queuedRefreshes());
    }

    public function test_the_route_is_rate_limited_by_client_address(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        self::assertInstanceOf(RouterInterface::class, $router);

        $route = $router->getRouteCollection()->get('webhook_forge_github');

        self::assertNotNull($route);
        self::assertSame([Request::METHOD_POST], $route->getMethods());
        self::assertTrue($route->getDefault(RateLimitForgeDeliveries::MARKER));
        self::assertSame(RateLimitForgeDeliveries::KEY_BY_ADDRESS, $route->getDefault(RateLimitForgeDeliveries::KEYING));
    }

    private function loseAccessOf(int $repositoryId): void
    {
        $row = $this->installationOwnerOf($repositoryId) ?? throw new \LogicException('The repository has no installation row.');
        $row->accessLostAt = new \DateTimeImmutable();
        $this->em()->flush();
    }

    private function storedInstallation(int $installationId): GitHubInstallation
    {
        $this->em()->clear();
        $installations = self::getContainer()->get(GitHubInstallationRepository::class);
        self::assertInstanceOf(GitHubInstallationRepository::class, $installations);

        return $installations->findOneByInstallationId($installationId) ?? throw new \LogicException('The installation is gone.');
    }
}
