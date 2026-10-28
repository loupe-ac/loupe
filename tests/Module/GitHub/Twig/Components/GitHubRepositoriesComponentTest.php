<?php

declare(strict_types=1);

namespace App\Tests\Module\GitHub\Twig\Components;

use App\Module\Forge\Entity\ForgeRepository;
use App\Module\Forge\Entity\ForgeRepositoryCheck;
use App\Module\Forge\Messenger\CheckForgeRepository;
use App\Module\Forge\Messenger\CheckTrigger;
use App\Module\Forge\Service\RepositorySuggestionCatalog;
use App\Module\Forge\Settings\RepositoryRules;
use App\Module\Forge\Settings\RepositoryRuleset;
use App\Module\Forge\Settings\RepositorySettings;
use App\Module\GitHub\Service\GitHubAppConfiguration;
use App\Module\GitHub\Service\HookSecretKey;
use App\Module\Workflow\Contract\RepositorySuggestion;
use App\Module\Workflow\Contract\RepositorySuggestionKey;
use App\Module\Workflow\Contract\RepositorySuggestions;
use App\Module\Workflow\Contract\SuggestionMark;
use App\Module\Workflow\Contract\WorkflowKeys;
use App\Tests\Module\GitHub\Controller\GitHubConnectionsScenario;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Ubermuda\DoctrineExtra\Encryption\EncryptionKeyProvider;

final class GitHubRepositoriesComponentTest extends WebTestCase
{
    use GitHubConnectionsScenario;

    public function test_the_owner_sees_the_empty_state(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('empty');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#repositories');
        self::assertCount(1, $section);
        self::assertStringContainsString('No repository yet', $section->text());
        self::assertCount(0, $section->filter('[data-forge-repository]'));
        self::assertCount(0, $crawler->filter('#github-hook'));
    }

    public function test_a_page_with_rows_carries_the_empty_state_for_the_last_removal(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('lastrow');
        $project = $this->projectOf($owner);
        $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $crawler->filter('#repositories [data-repository-checks-live-target="populated"] [data-forge-repository]'));
        self::assertStringContainsString('forge-repositories-empty', $crawler->filter('#repositories template[data-repository-checks-live-target="empty"]')->html());
    }

    public function test_a_row_with_a_gap_draws_the_guide_with_its_buttons_and_the_check_again_form(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::ApprovalGate, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('guide');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_NO_PULL_REQUEST_RULE])], new \DateTimeImmutable('-2 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $guide = $crawler->filter('[data-testid="forge-repository-guide"]');
        self::assertCount(1, $guide);
        self::assertSame('Fix it on GitHub', trim($guide->filter('h3')->text()));
        self::assertCount(2, $guide->filter('li[data-testid="forge-repository-guide-step"]'));
        $download = $guide->filter('a[href$="/loupe-ruleset.json"]');
        self::assertCount(1, $download);
        self::assertSame('/projects/'.$project->id.'/repositories/'.$repository->id.'/loupe-ruleset.json', $download->attr('href'));
        self::assertSame('Download loupe-ruleset.json', trim($download->text()));
        $rules = $guide->filter('a[href="https://github.com/acme/app/settings/rules"]');
        self::assertCount(1, $rules);
        self::assertSame('_blank', $rules->attr('target'));
        self::assertSame('noopener noreferrer', $rules->attr('rel'));
        self::assertStringContainsString('Check that Loupe / Approval shows the Loupe App as its source', $guide->text());

        $form = $crawler->filter('[data-forge-repository] form[action$="/repositories/'.$repository->id.'/check"]');
        self::assertCount(1, $form);
        self::assertNotSame('', (string) $form->filter('input[name="_csrf_token"]')->attr('value'));
        self::assertSame('Check again', trim($form->filter('button')->text()));
        self::assertStringContainsString('Loupe checks every hour. Check again after a fix to end the pause now.', $crawler->filter('[data-forge-repository]')->text());

        $paused = $crawler->filter('[data-testid="forge-repository-check"] > [data-testid="forge-repository-paused"]');
        self::assertCount(1, $paused);
        self::assertSame('New work is paused.', trim($paused->filter('.lp-flash__title')->text()));
        self::assertStringContainsString('workflow. Running work finishes.', $paused->text());
    }

    public function test_a_row_that_fits_has_no_guide_and_keeps_the_check_again_button(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('fits');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->item('merge-method', 'blocking', [])], new \DateTimeImmutable('-2 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-line"]'));
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-guide"]'));
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-again"]'));
        $row = $crawler->filter('[data-forge-repository]')->text();
        self::assertStringContainsString('Loupe checks every hour. Check again after a change on GitHub to see it now.', $row);
        self::assertStringNotContainsString('pause', $row);
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-paused"]'));
    }

    public function test_a_row_with_only_a_warning_gap_does_not_mention_a_pause(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::CiChecks, SuggestionMark::Warning);
        $owner = $this->signedUpUser('warnonly');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->item('ci-checks', 'warning', [RepositorySuggestionCatalog::GAP_NO_CI_CHECK])], new \DateTimeImmutable('-2 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertStringContainsString('Check again after a change on GitHub to see it now.', $crawler->filter('.lp-repository-check__hint')->text());
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-line"]'));
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-paused"]'));
    }

    public function test_the_failed_read_banner_names_the_time_and_carries_the_only_check_again_button(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('banner');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $check = $this->checkOf($repository, [$this->item('merge-method', 'blocking', [])], new \DateTimeImmutable('-1 hour'));
        $check->attemptedAt = new \DateTimeImmutable('-1 minute');
        $check->failure = 'api_failed_timeout';
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $banner = $crawler->filter('[data-testid="forge-repository-check-failed"]');
        self::assertSame('Loupe could not check acme/app.', trim($banner->filter('.lp-flash__title')->text()));
        self::assertStringContainsString('GitHub did not answer at '.$check->attemptedAt->format('H:i').'.', $banner->text());
        self::assertStringNotContainsString('paused', $banner->text());
        self::assertCount(1, $banner->filter('[data-testid="forge-repository-check-again"]'));
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-again"]'));
        self::assertStringContainsString('The list below keeps the result of the last check that worked, with its time.', $crawler->filter('[data-testid="forge-repository-check-kept"]')->text());
    }

    public function test_a_webhook_only_repository_gets_the_warning_and_no_check_again_button(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('hook');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $check = new ForgeRepositoryCheck($repository, $project);
        $check->attemptedAt = new \DateTimeImmutable('-1 minute');
        $check->failure = 'no_installation';
        $this->em()->persist($check);
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertStringContainsString('Loupe checks only repositories of the GitHub App', $crawler->filter('[data-testid="forge-repository-check-failed"]')->text());
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-check-again"]'));
    }

    public function test_classic_protection_gets_a_link_to_the_branch_protection_page_and_no_guide(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('classic');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [
            $this->item('merge-method', 'blocking', []),
            $this->item(ForgeRepositoryCheck::KEY_CLASSIC_PROTECTION, 'warning', [RepositorySuggestionCatalog::GAP_CLASSIC_PROTECTION]),
        ], new \DateTimeImmutable('-2 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $link = $crawler->filter('[data-testid="forge-repository-check-line"] a[href="https://github.com/acme/app/settings/branches"]');
        self::assertCount(1, $link);
        self::assertSame('Open branch protection on GitHub', trim($link->text()));
        self::assertSame('noopener noreferrer', $link->attr('rel'));
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-guide"]'));
    }

    public function test_a_ruleset_name_with_markup_and_a_foreign_source_stay_plain_text_and_off_the_links(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::ApprovalGate, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('markup');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $settings = new RepositorySettings('main', ['squash'], 15368, new RepositoryRules(true, true, null, ['Loupe / Approval'], [15368], true, true), [
            new RepositoryRuleset(12, '<img src=x onerror=alert(1)>', 'Repository', 'evil.example/phish', ['pull_request']),
            new RepositoryRuleset(13, '<b>org</b>', 'Organization', 'acme-org', ['pull_request']),
        ], [], false);
        $this->checkOf($repository, [$this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_REVIEW_REQUIRED])], new \DateTimeImmutable('-2 minutes'), $settings);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<b>org</b>', $html);
        $guide = $crawler->filter('[data-testid="forge-repository-guide"]');
        self::assertStringContainsString('<img src=x onerror=alert(1)>', $guide->text());
        self::assertStringContainsString('Only an organization owner can change it', $guide->text());
        $hrefs = $guide->filter('a')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertSame(['https://github.com/acme/app/rules/12', 'https://github.com/organizations/acme-org/settings/rules/13'], $hrefs);
    }

    public function test_the_empty_state_with_the_app_invites_to_install_it(): void
    {
        $client = static::createClient();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('loupe-app', 'client', 'secret', 'hook', null, null));
        $owner = $this->signedUpUser('install');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $empty = $crawler->filter('#repositories [data-testid="forge-repositories-empty"]');
        self::assertCount(1, $empty);
        self::assertStringContainsString('Install the GitHub App on a repository. Loupe then checks that it fits the workflow.', $empty->text());
        $install = $crawler->filter('#repositories a[data-testid="forge-repositories-install"]');
        self::assertSame('/projects/'.$project->id.'/github/install', $install->attr('href'));
        self::assertSame('Install the GitHub App', trim($install->text()));
    }

    public function test_the_empty_state_without_the_app_invites_to_connect_a_repository_on_connections(): void
    {
        $client = static::createClient();
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('', '', '', '', null, null));
        $owner = $this->signedUpUser('noapp');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $empty = $crawler->filter('#repositories [data-testid="forge-repositories-empty"]');
        self::assertCount(1, $empty);
        self::assertStringContainsString('No repository yet', $empty->text());
        self::assertStringContainsString('Connect a repository with a webhook on the Connections page.', $empty->text());
        $connect = $crawler->filter('#repositories a[data-testid="forge-repositories-connect"]');
        self::assertSame('/projects/'.$project->id.'/connect', $connect->attr('href'));
        self::assertSame('Connect a repository', trim($connect->text()));
        self::assertCount(0, $crawler->filter('[data-testid="forge-repositories-install"]'));
    }

    public function test_a_stranger_cannot_open_the_page_of_another_project(): void
    {
        $client = static::createClient();
        $project = $this->projectOf($this->signedUpUser('owner'));
        $stranger = $this->signedUpUser('stranger');
        $this->em()->clear();

        $client->loginUser($stranger);
        $this->repositoriesPage($client, $project);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_the_page_lists_the_nav_item_and_the_breadcrumb(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('nav');
        $project = $this->projectOf($owner);
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertSame('Repositories', trim($crawler->filter('.lp-settings-nav__item[aria-current="page"]')->text()));
        self::assertSame('Project settings', trim($crawler->filter('.lp-crumbs__link')->text()));
        self::assertSame('Repositories', trim($crawler->filter('.lp-crumbs__here')->text()));
    }

    public function test_each_repository_shows_its_path_and_its_health(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('health');
        $project = $this->projectOf($owner);
        $this->repositoryOf($project, 'acme/waiting');
        $this->repositoryOf($project, 'acme/working', new \DateTimeImmutable('-2 hours'));
        $this->repositoryOf($project, 'acme/quiet', new \DateTimeImmutable('-31 days'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $rows = [];
        $crawler->filter('#repositories [data-forge-repository]')->each(static function ($row) use (&$rows): void {
            $rows[trim($row->filter('[data-testid="forge-repository-path"]')->text())] = trim($row->filter('[data-testid="forge-repository-health"]')->text());
        });

        self::assertSame(['acme/waiting', 'acme/working', 'acme/quiet'], array_keys($rows));
        self::assertSame('Waiting for the first delivery', $rows['acme/waiting']);
        self::assertStringStartsWith('Working', $rows['acme/working']);
        self::assertStringStartsWith('Quiet', $rows['acme/quiet']);
    }

    public function test_the_connections_page_has_no_repository_rows(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('connections');
        $project = $this->projectOf($owner);
        $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->connectPage($client, $project);

        self::assertCount(0, $crawler->filter('[data-forge-repository]'));
        self::assertCount(1, $crawler->filter('#github-connection'));
    }

    public function test_a_stored_check_draws_a_chip_a_title_and_a_detail_for_each_line(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::ApprovalGate, SuggestionMark::Blocking, RepositorySuggestionKey::CiChecks, SuggestionMark::Warning, RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('lines');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [
            $this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_APPROVAL_CHECK_NOT_REQUIRED]),
            $this->item('ci-checks', 'warning', [RepositorySuggestionCatalog::GAP_NO_CI_CHECK]),
            $this->item('merge-method', 'blocking', []),
            $this->item(ForgeRepositoryCheck::KEY_CLASSIC_PROTECTION, 'warning', [RepositorySuggestionCatalog::GAP_CLASSIC_PROTECTION]),
        ], new \DateTimeImmutable('-2 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $lines = $crawler->filter('[data-forge-repository] [data-testid="forge-repository-check-line"]');
        self::assertCount(4, $lines);
        self::assertCount(1, $lines->eq(0)->filter('.lp-status-chip--failed'));
        self::assertSame('Blocking', trim($lines->eq(0)->filter('.lp-status-chip')->text()));
        self::assertStringContainsString('Loupe / Approval is not required', $lines->eq(0)->text());
        self::assertStringContainsString('nothing on GitHub gates a merge', $lines->eq(0)->text());
        self::assertCount(1, $lines->eq(1)->filter('.lp-status-chip--pending'));
        self::assertCount(1, $lines->eq(2)->filter('.lp-status-chip--ok'));
        self::assertStringContainsString('Loupe merges with squash', $lines->eq(2)->text());
        self::assertStringContainsString('main uses classic branch protection', $lines->eq(3)->text());
        self::assertStringContainsString('Checked 2m ago', trim((string) preg_replace('/\s+/', ' ', $crawler->filter('[data-testid="forge-repository-checked"]')->text())));
        self::assertSame('Lifecycle workflow', trim($crawler->filter('[data-testid="forge-repository-workflow"]')->text()));
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-check-failed"]'));
    }

    public function test_a_page_with_no_stored_read_queues_one_check_and_shows_checking(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('queue');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $queued = $this->queuedChecks();
        self::assertCount(1, $queued);
        self::assertSame((string) $repository->id, $queued[0]->repositoryId);
        self::assertSame(CheckTrigger::PageOpen, $queued[0]->trigger);
        self::assertCount(1, $crawler->filter('[data-forge-repository] [data-repository-checking]'));
        self::assertSame('Checking…', trim($crawler->filter('[data-testid="forge-repository-checking"]')->text()));
    }

    public function test_a_page_reopened_within_five_minutes_dispatches_nothing_and_shows_no_checking(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('reopen');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->item('merge-method', 'blocking', [])], new \DateTimeImmutable('-4 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-line"]'));
        self::assertSame([], $this->queuedChecks());
        self::assertCount(0, $crawler->filter('[data-repository-checking]'));
        self::assertStringContainsString('Checked 4m ago', trim((string) preg_replace('/\s+/', ' ', $crawler->filter('[data-testid="forge-repository-checked"]')->text())));
    }

    public function test_a_stale_read_queues_a_new_check_and_keeps_the_last_lines(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('stale');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->item('merge-method', 'blocking', [])], new \DateTimeImmutable('-6 minutes'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $this->queuedChecks());
        self::assertCount(1, $crawler->filter('[data-repository-checking]'));
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-line"]'));
    }

    public function test_a_project_whose_workflow_suggests_nothing_gets_no_check(): void
    {
        $client = static::createClient();
        $this->suggest();
        $owner = $this->signedUpUser('nothing');
        $project = $this->projectOf($owner);
        $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $crawler->filter('[data-forge-repository]'));
        self::assertSame([], $this->queuedChecks());
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-check"]'));
    }

    public function test_a_project_whose_workflow_dropped_its_suggestions_queues_the_clear_of_its_stored_items_and_hides_them(): void
    {
        $client = static::createClient();
        $this->suggest();
        $owner = $this->signedUpUser('dropped');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->checkOf($repository, [$this->item('merge-method', 'blocking', ['no_merge_method'])], new \DateTimeImmutable('-1 minute'));
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        $queued = $this->queuedChecks();
        self::assertCount(1, $queued);
        self::assertSame((string) $repository->id, $queued[0]->repositoryId);
        self::assertCount(0, $crawler->filter('[data-testid="forge-repository-check"]'));
        self::assertCount(0, $crawler->filter('[data-repository-checking]'));
    }

    public function test_a_failed_read_says_so_and_keeps_the_last_certain_lines(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::MergeMethod, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('failed');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $check = $this->checkOf($repository, [$this->item('merge-method', 'blocking', [])], new \DateTimeImmutable('-1 hour'));
        $check->attemptedAt = new \DateTimeImmutable('-1 minute');
        $check->failure = 'api_failed_timeout';
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertSame([], $this->queuedChecks());
        self::assertStringContainsString('Loupe could not check acme/app', $crawler->filter('[data-testid="forge-repository-check-failed"]')->text());
        self::assertStringNotContainsString('paused', $crawler->filter('[data-testid="forge-repository-check-failed"]')->text());
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-line"]'));
    }

    public function test_a_failed_read_keeps_the_pause_banner_of_the_last_certain_blocking_gap(): void
    {
        $client = static::createClient();
        $this->suggest(RepositorySuggestionKey::ApprovalGate, SuggestionMark::Blocking);
        $owner = $this->signedUpUser('failedgap');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $check = $this->checkOf($repository, [$this->item('approval-gate', 'blocking', [RepositorySuggestionCatalog::GAP_NO_PULL_REQUEST_RULE])], new \DateTimeImmutable('-1 hour'));
        $check->attemptedAt = new \DateTimeImmutable('-1 minute');
        $check->failure = 'api_failed_timeout';
        $this->em()->flush();
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-check-failed"]'));
        self::assertCount(1, $crawler->filter('[data-testid="forge-repository-paused"]'));
    }

    public function test_the_repository_removal_dialog_keeps_its_form(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('remove');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertSame(
            '/projects/'.$project->id.'/repositories/'.$repository->id.'/remove',
            $crawler->filter('[data-forge-repository] dialog form')->attr('action'),
        );
    }

    public function test_the_page_subscribes_to_the_repository_check_topic_and_names_each_row_url(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('topic');
        $project = $this->projectOf($owner);
        $repository = $this->repositoryOf($project, 'acme/app');
        $this->em()->clear();

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertCount(1, $crawler->filter('#repositories[data-controller="repository-checks-live"]'));
        self::assertSame(
            '/projects/'.$project->id.'/repositories/'.$repository->id.'/row',
            $crawler->filter('[data-forge-repository]')->attr('data-repository-row-url'),
        );
        self::assertStringContainsString('/projects/'.$project->id.'/repository-checks', (string) $client->getResponse()->getContent());
    }

    public function test_without_forge_connections_the_page_says_so(): void
    {
        $client = static::createClient();
        $owner = $this->signedUpUser('nokey');
        $project = $this->projectOf($owner);
        $this->em()->clear();
        static::getContainer()->set(HookSecretKey::class, new HookSecretKey(new EncryptionKeyProvider([''])));
        static::getContainer()->set(GitHubAppConfiguration::class, new GitHubAppConfiguration('', '', '', '', null, null));

        $client->loginUser($owner);
        $crawler = $this->repositoriesPage($client, $project);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The operator has not enabled forge connections', $crawler->filter('#repositories')->text());
    }

    private function suggest(RepositorySuggestionKey|SuggestionMark ...$pairs): void
    {
        $suggestions = [];
        for ($index = 0; $index < \count($pairs); $index += 2) {
            $key = $pairs[$index];
            $mark = $pairs[$index + 1];
            self::assertInstanceOf(RepositorySuggestionKey::class, $key);
            self::assertInstanceOf(SuggestionMark::class, $mark);
            $suggestions[] = new RepositorySuggestion($key, $mark);
        }
        static::getContainer()->set(RepositorySuggestions::class, new readonly class($suggestions) implements RepositorySuggestions {
            /** @param list<RepositorySuggestion> $suggestions */
            public function __construct(
                private array $suggestions,
            ) {
            }

            #[\Override]
            public function forProject(Uuid $projectId): array
            {
                return $this->suggestions;
            }
        });
        static::getContainer()->set(WorkflowKeys::class, new readonly class implements WorkflowKeys {
            #[\Override]
            public function forProject(Uuid $projectId): string
            {
                return 'lifecycle';
            }
        });
    }

    /** @return list<CheckForgeRepository> */
    private function queuedChecks(): array
    {
        $transport = static::getContainer()->get('messenger.transport.jobs');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn (object $message): bool => $message instanceof CheckForgeRepository,
        ));
    }

    /**
     * @param list<array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}> $items
     */
    private function checkOf(ForgeRepository $repository, array $items, \DateTimeImmutable $checkedAt, ?RepositorySettings $settings = null): ForgeRepositoryCheck
    {
        $check = new ForgeRepositoryCheck($repository, $repository->project);
        $check->items = $items;
        $check->checkedAt = $checkedAt;
        $settings ??= new RepositorySettings('main', ['squash'], 15368, new RepositoryRules(true, false, null, [], [], true, true), [], [], false);
        $check->settings = json_decode(json_encode($settings, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        $this->em()->persist($check);
        $this->em()->flush();

        return $check;
    }

    /**
     * @param list<string> $gaps
     *
     * @return array{key: string, mark: string, state: string, gaps: list<string>, parameters: array<string, mixed>}
     */
    private function item(string $key, string $mark, array $gaps): array
    {
        return ['key' => $key, 'mark' => $mark, 'state' => [] === $gaps ? 'fits' : 'gap', 'gaps' => $gaps, 'parameters' => []];
    }
}
